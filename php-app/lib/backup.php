<?php
/**
 * WorkFolio PHP - database backup and recovery.
 *
 * Two drivers, two strategies, one interface:
 *
 *  - SQLite: a VACUUM INTO snapshot of the live file, which is the only copy
 *    that is safe while the WAL journal is open. Restoring swaps the file
 *    back after closing the connection, taking its own snapshot first so an
 *    accidental restore cannot destroy the current state.
 *  - MySQL/MariaDB: a plain SQL script (DROP + CREATE + INSERT per table) that
 *    this file can both write and replay, so recovery never depends on
 *    mysqldump or the mysql client being installed on the host.
 *
 * Backups live under <storage>/backups, which is the same directory tree the
 * router and .htaccess refuse to serve directly; downloads always go through
 * the role-checked route in backup_pages.php.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';

/* ------------------------------------------------------------------ paths ---- */

function backup_dir(): string {
    global $WF;
    $dir = rtrim((string) $WF['storage'], '/\\') . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function backup_sqlite_file(): string {
    global $WF;
    return rtrim((string) $WF['storage'], '/\\') . '/db.sqlite';
}

function backup_is_sqlite(): bool {
    return wf_driver() !== 'mysql';
}

/** A backup name is a plain file name, never a path. */
function backup_valid_name(string $name): bool {
    return $name !== '' && preg_match('/^[A-Za-z0-9._-]{1,80}$/', $name) === 1
        && strpos($name, '..') === false;
}

function backup_path(string $name): ?string {
    if (!backup_valid_name($name)) return null;
    $path = backup_dir() . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? $path : null;
}

/* --------------------------------------------------------------- snapshots ---- */

function backup_pdo_close(): void {
    global $WF_PDO;
    if ($WF_PDO instanceof PDO) $WF_PDO = null;
}

/** Consistent single-file snapshot of the SQLite database. */
function backup_sqlite_snapshot(string $target): void {
    if (is_file($target)) @unlink($target);
    $pdo = wf_pdo();
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
        return;
    } catch (Throwable $e) {
        /* Older SQLite: checkpoint first, then copy the file and its WAL. */
    }
    try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) {}
    $src = backup_sqlite_file();
    if (!is_file($src)) throw new RuntimeException('SQLite database file not found.');
    if (!copy($src, $target)) throw new RuntimeException('Could not copy the SQLite database.');
    foreach (['-wal', '-shm'] as $sfx) {
        if (is_file($src . $sfx)) @copy($src . $sfx, $target . $sfx);
    }
}

function backup_mysql_tables(): array {
    $out = [];
    $sql = 'SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'';
    foreach (wf_pdo()->query($sql) as $row) {
        $out[] = (string) reset($row);
    }
    sort($out);
    return $out;
}

/** Plain SQL script: DROP + CREATE + batched INSERTs for every base table. */
function backup_mysql_dump(string $target): void {
    $pdo = wf_pdo();
    $fh = fopen($target, 'w');
    if ($fh === false) throw new RuntimeException('Could not open the backup file for writing.');
    $w = static function (string $s) use ($fh) { fwrite($fh, $s); };
    $w("-- WorkFolio database backup\n");
    $w('-- driver: mysql  created: ' . date('Y-m-d H:i:s') . "\n");
    $w("SET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach (backup_mysql_tables() as $t) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch();
        $sql = is_array($create) ? (string) ($create['Create Table'] ?? '') : '';
        $w('DROP TABLE IF EXISTS ' . $q . ";\n");
        if ($sql !== '') $w($sql . ";\n");
        $offset = 0;
        while (true) {
            $batch = $pdo->query('SELECT * FROM ' . $q . ' LIMIT 1000 OFFSET ' . $offset)->fetchAll(PDO::FETCH_NUM);
            if ($batch === []) break;
            $offset += count($batch);
            $lines = [];
            foreach ($batch as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                }
                $lines[] = '(' . implode(',', $vals) . ')';
            }
            $w('INSERT INTO ' . $q . ' VALUES ' . implode(',', $lines) . ";\n");
            if (count($batch) < 1000) break;
        }
        $w("\n");
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
}

/* -------------------------------------------------------- create and list ---- */

/**
 * Writes a new backup and returns its descriptor. $note is 'manual' for a
 * click and 'pre-restore' for the safety copy taken before a recovery.
 */
function backup_create(string $note = 'manual'): array {
    $dir = backup_dir();
    $sqlite = backup_is_sqlite();
    $stamp = date('Ymd-His');
    $name = 'wf-' . $stamp . '-' . substr(md5((string) microtime(true)), 0, 4) . ($sqlite ? '.sqlite' : '.sql');
    $target = $dir . DIRECTORY_SEPARATOR . $name;
    if ($sqlite) {
        backup_sqlite_snapshot($target);
    } else {
        backup_mysql_dump($target);
    }
    if (!is_file($target)) throw new RuntimeException('The backup file was not written.');
    return [
        'name' => $name,
        'size' => (int) filesize($target),
        'mtime' => (int) filemtime($target),
        'driver' => $sqlite ? 'sqlite' : 'mysql',
        'note' => $note,
    ];
}

function backup_list(): array {
    $out = [];
    foreach (glob(backup_dir() . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $name = basename($path);
        if (!preg_match('/^(wf-.+|pre-restore-.+)\.(sqlite|sql)$/', $name)) continue;
        $out[] = [
            'name' => $name,
            'size' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
            'driver' => str_ends_with($name, '.sqlite') ? 'sqlite' : 'mysql',
            'note' => str_starts_with($name, 'pre-restore-') ? 'pre-restore' : 'manual',
        ];
    }
    usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

function backup_delete(string $name): bool {
    $path = backup_path($name);
    if ($path === null) return false;
    return @unlink($path);
}

/* --------------------------------------------------------------- recovery ---- */

/**
 * Restores a backup over the live database. A snapshot of the current state
 * is taken first (and kept, prefixed pre-restore-) so recovery is reversible.
 */
function backup_restore(string $name): array {
    $path = backup_path($name);
    if ($path === null) throw new RuntimeException('Backup not found.');
    $safety = backup_create('pre-restore');

    if (backup_is_sqlite()) {
        $live = backup_sqlite_file();
        backup_pdo_close();
        foreach (['', '-wal', '-shm'] as $sfx) @unlink($live . $sfx);
        if (!copy($path, $live)) throw new RuntimeException('Could not write the database file back.');
        try {
            wf_pdo();
            val('SELECT COUNT(*) FROM employees');
        } catch (Throwable $e) {
            /* Roll back to the snapshot taken moments ago. */
            backup_pdo_close();
            foreach (['', '-wal', '-shm'] as $sfx) @unlink($live . $sfx);
            backup_sqlite_snapshot($live);
            wf_pdo();
            throw new RuntimeException('The restored database failed its check and was rolled back: ' . $e->getMessage());
        }
        return ['restored' => true, 'safety' => $safety['name'], 'tables' => null];
    }

    $sql = (string) @file_get_contents($path);
    if ($sql === '') throw new RuntimeException('The backup file could not be read.');
    $tables = 0;
    foreach (backup_split_statements($sql) as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        wf_pdo()->exec($stmt);
        if (stripos($stmt, 'CREATE TABLE') === 0) $tables++;
    }
    if ($tables === 0) throw new RuntimeException('The backup contained no tables.');
    return ['restored' => true, 'safety' => $safety['name'], 'tables' => $tables];
}

/** Splits a SQL script into statements, honouring quotes and -- / # comments. */
function backup_split_statements(string $sql): array {
    $out = [];
    $cur = '';
    $quote = null;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            $cur .= $ch;
            if ($quote !== '`' && $ch === '\\' && $i + 1 < $len) {
                $cur .= $sql[++$i];
                continue;
            }
            if ($ch === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) { $cur .= $sql[++$i]; continue; }
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $cur .= $ch; continue; }
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) break;
            $i = $nl;
            continue;
        }
        if ($ch === '#') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) break;
            $i = $nl;
            continue;
        }
        if ($ch === ';') { $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return array_values(array_filter($out, static fn($s) => $s !== ''));
}

function backup_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    $num = $i === 0 ? (string) $bytes : rtrim(rtrim(number_format($v, 1), '0'), '.');
    return $num . ' ' . $units[$i];
}
