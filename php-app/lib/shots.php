<?php
/**
 * WorkFolio PHP - ScreenshotStorage port.
 *
 * Screenshots are written as JPEG files under <storage>/screenshots/<date>/<email>/
 * so the database stays small and the gallery can stream files straight off disk.
 * Legacy in-DB captures (image column) still read back for old rows.
 */
require_once __DIR__ . '/core.php';

function shots_root(): string {
    global $WF;
    return rtrim($WF['storage'], '/\\') . '/screenshots';
}

function shots_day_folder(string $email, string $day): string {
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $email);
    if ($safe === null || $safe === '') $safe = 'unknown';
    return shots_root() . '/' . $day . '/' . $safe;
}

/** Persists a new capture. Returns the stored row, or null when unusable. */
function shots_store(string $email, ?string $base64, ?string $trigger, ?string $appName,
                     ?string $windowTitle, ?string $host, bool $live): ?array {
    if ($base64 === null || $base64 === '') return null;
    $bytes = shots_decode($base64);
    if ($bytes === null || $bytes === '') return null;

    $day = today();
    $folder = shots_day_folder($email, $day);
    if (!is_dir($folder)) {
        if (!@mkdir($folder, 0775, true) && !is_dir($folder)) return null;
    }
    $ms = (int) ((microtime(true) - (int) microtime(true)) * 1000);
    $fileName = date('Ymd-His-') . sprintf('%03d', $ms) . ($live ? '-live' : '') . '.jpg';
    if (@file_put_contents($folder . '/' . $fileName, $bytes) === false) return null;

    q('INSERT INTO screenshots (email, captured_at, file_name, `trigger`, app_name, window_title, host, size_bytes, live) VALUES (?,?,?,?,?,?,?,?,?)', [
        $email, now_sql(), $fileName, $trigger === null || $trigger === '' ? 'AUTO' : $trigger,
        shots_trim($appName, 200), shots_trim($windowTitle, 500), shots_trim($host, 200),
        strlen($bytes), $live ? 1 : 0,
    ]);
    // A 15s live stream would otherwise pile up ~5,700 files a day per employee.
    if ($live) shots_maybe_purge_live($email);
    return one('SELECT * FROM screenshots WHERE id=?', [last_id()]);
}

/**
 * Drops live frames older than the window, files included. Called from the live
 * store path and throttled per employee with a marker file so a 15s stream does
 * not run a delete query every time. Live frames only feed the live view, so a
 * short window is enough; AUTO/MANUAL evidence is untouched.
 */
function shots_maybe_purge_live(string $email, int $hours = 3, int $throttleSeconds = 300): int {
    $root = shots_root();
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $email);
    if ($safe === null || $safe === '') return 0;
    $marker = $root . '/.live-purge-' . $safe;
    $now = time();
    if (is_file($marker) && ($now - (int) @filemtime($marker)) < $throttleSeconds) return 0;
    @touch($marker);
    $cutoff = date('Y-m-d H:i:s', $now - max(1, $hours) * 3600);
    $old = rows('SELECT * FROM screenshots WHERE email=? AND live=1 AND captured_at < ?', [$email, $cutoff]);
    foreach ($old as $s) shots_delete($s);
    return count($old);
}

/** Raw JPEG bytes for a row: the on-disk file first, then any legacy inline data. */
function shots_read_bytes(array $shot): ?string {
    if (!empty($shot['file_name']) && !empty($shot['captured_at'])) {
        $day = substr((string) $shot['captured_at'], 0, 10);
        $p = shots_day_folder($shot['email'], $day) . '/' . $shot['file_name'];
        if (is_file($p)) {
            $b = @file_get_contents($p);
            if ($b !== false) return $b;
        }
    }
    return shots_decode($shot['image'] ?? null);
}

function shots_read_base64(array $shot): ?string {
    $b = shots_read_bytes($shot);
    return $b === null ? null : base64_encode($b);
}

function shots_delete(array $shot): void {
    if (!empty($shot['file_name']) && !empty($shot['captured_at'])) {
        $day = substr((string) $shot['captured_at'], 0, 10);
        $dir = shots_day_folder($shot['email'], $day);
        $p = $dir . '/' . $shot['file_name'];
        if (is_file($p)) @unlink($p);
        // The folder is per person per day, so emptying it means the person has
        // no evidence left that day. Leaving hundreds of empty folders behind
        // just makes the storage report lie about what is still there.
        if (is_dir($dir) && (glob($dir . '/*') ?: []) === []) @rmdir($dir);
    }
    q('DELETE FROM screenshots WHERE id=?', [(int) $shot['id']]);
}

/** Removes captures older than the retention window. Returns the count removed. */
function shots_purge_older_than(int $days): int {
    if ($days <= 0) return 0;
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
    $old = rows('SELECT * FROM screenshots WHERE captured_at < ?', [$cutoff]);
    foreach ($old as $s) shots_delete($s);
    return count($old);
}

/* ---------------------------------------------------------- live video ---- */

/**
 * The live stream is an overwrite slot, not an archive: one JPEG per employee at
 * <storage>/live/<email>.jpg plus one live_frames row. A 1 fps broadcast
 * therefore costs a single file instead of ~5,700 rows a day.
 */
function live_frame_dir(): string {
    global $WF;
    return rtrim($WF['storage'], '/\\') . '/live';
}

function live_frame_safe(string $email): string {
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $email);
    return ($safe === null || $safe === '') ? 'unknown' : $safe;
}

function live_frame_path(string $email): string {
    return live_frame_dir() . '/' . live_frame_safe($email) . '.jpg';
}

function live_frame_row(string $email): ?array {
    return one('SELECT * FROM live_frames WHERE email=?', [$email]);
}

function live_frame_age(?array $frame): ?int {
    if ($frame === null) return null;
    return secs_since($frame['captured_at'] ?? null);
}

/** Stores the newest frame for one employee, replacing whatever was there. */
function live_frame_store(string $email, ?string $base64, int $seq, array $meta): ?array {
    if ($base64 === null || $base64 === '') return null;
    $bytes = shots_decode($base64);
    if ($bytes === null || $bytes === '' || strlen($bytes) > 3 * 1024 * 1024) return null;

    $dir = live_frame_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    $file = live_frame_safe($email) . '.jpg';
    $target = $dir . '/' . $file;
    // Write beside it and rename, so a viewer never reads a half-written frame.
    if (@file_put_contents($target . '.part', $bytes) === false) return null;
    if (!@rename($target . '.part', $target)) {
        @unlink($target . '.part');
        return null;
    }

    q('REPLACE INTO live_frames (email, seq, captured_at, file_name, size_bytes, status, app_name, window_title, host, category, host_name, agent_version, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $email, $seq, now_sql(), $file, strlen($bytes),
        shots_trim($meta['status'] ?? null, 20), shots_trim($meta['app'] ?? null, 200),
        shots_trim($meta['title'] ?? null, 500), shots_trim($meta['host'] ?? null, 200),
        shots_trim($meta['category'] ?? null, 80), shots_trim($meta['hostName'] ?? null, 255),
        shots_trim($meta['agentVersion'] ?? null, 60), shots_trim($meta['ip'] ?? null, 60),
    ]);
    return live_frame_row($email);
}

/** Total bytes of every screenshot file on disk (for the report footer). */
function shots_storage_bytes(): int {
    $root = shots_root();
    if (!is_dir($root)) return 0;
    $total = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) $total += $f->getSize();
    }
    return $total;
}

function shots_trim(?string $value, int $max): ?string {
    if ($value === null) return null;
    $v = trim($value);
    return mb_strlen($v) <= $max ? $v : mb_substr($v, 0, $max);
}

function shots_decode(?string $base64): ?string {
    if ($base64 === null || trim($base64) === '') return null;
    try {
        $payload = trim($base64);
        $comma = strpos($payload, ',');
        if (strpos($payload, 'data:') === 0 && $comma > 0) $payload = substr($payload, $comma + 1);
        $decoded = base64_decode($payload, true);
        return $decoded === false ? null : $decoded;
    } catch (Throwable $e) {
        return null;
    }
}