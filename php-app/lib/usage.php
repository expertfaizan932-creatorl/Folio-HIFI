<?php
/**
 * WorkFolio PHP - UsageService port.
 *
 * Turns raw window samples into per-day, per-application / per-website time
 * totals, holds the per-employee monitoring policy, and answers every report
 * question ("which apps, which sites, how long on each").
 */
require_once __DIR__ . '/core.php';

/** Longest time a single window sample may claim, so a flapping window cannot inflate totals. */
const MAX_SAMPLE_SECONDS = 120;

const USAGE_TYPE_APP = 'APP';
const USAGE_TYPE_WEB = 'WEB';
const USAGE_TYPE_IDLE = 'IDLE';
const USAGE_TYPE_LOCK = 'LOCK';

/* ------------------------------------------------------------------ config ---- */

function config_for(?string $email): array {
    if ($email === null || $email === '') return default_config();
    $c = one('SELECT * FROM agent_configs WHERE email=?', [$email]);
    if ($c !== null) return $c;
    try {
        $d = default_config();
        $d['email'] = $email;
        q('INSERT INTO agent_configs (email, screenshot_interval_seconds, live_stream_interval_seconds, live_stream_interval_ms, idle_threshold_seconds, image_quality, image_max_width, sample_interval_seconds, break_allowance_minutes, capture_screenshots, track_mouse_keyboard, track_app_usage, track_websites, allow_supervisor_talk, excluded_processes, blocked_sites) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $email, $d['screenshot_interval_seconds'], $d['live_stream_interval_seconds'],
            $d['live_stream_interval_ms'],
            $d['idle_threshold_seconds'], $d['image_quality'], $d['image_max_width'],
            $d['sample_interval_seconds'], $d['break_allowance_minutes'], $d['capture_screenshots'],
            $d['track_mouse_keyboard'], $d['track_app_usage'], $d['track_websites'],
            $d['allow_supervisor_talk'], $d['excluded_processes'], $d['blocked_sites'],
        ]);
    } catch (Throwable $e) {
        /* concurrent create - re-select below */
    }
    $c = one('SELECT * FROM agent_configs WHERE email=?', [$email]);
    return $c ?? array_merge(default_config(), ['email' => $email]);
}

function save_config(array $c): void {
    // The milliseconds value is the one thing an admin edits; the legacy
    // seconds column stays a rounded-up mirror of it so older agents, which
    // only understand seconds, keep streaming at a sensible rate.
    $ms = (int) ($c['live_stream_interval_ms'] ?? 0);
    if ($ms < 0) $ms = 0;
    if ($ms > 30000) $ms = 30000;
    q('UPDATE agent_configs SET screenshot_interval_seconds=?, live_stream_interval_seconds=?, live_stream_interval_ms=?, idle_threshold_seconds=?, image_quality=?, image_max_width=?, sample_interval_seconds=?, break_allowance_minutes=?, capture_screenshots=?, track_mouse_keyboard=?, track_app_usage=?, track_websites=?, allow_supervisor_talk=?, excluded_processes=?, blocked_sites=? WHERE email=?', [
        (int) $c['screenshot_interval_seconds'], live_interval_seconds($ms), $ms,
        (int) $c['idle_threshold_seconds'], (int) $c['image_quality'], (int) $c['image_max_width'],
        (int) $c['sample_interval_seconds'], (int) $c['break_allowance_minutes'],
        $c['capture_screenshots'] ? 1 : 0, $c['track_mouse_keyboard'] ? 1 : 0,
        $c['track_app_usage'] ? 1 : 0, $c['track_websites'] ? 1 : 0,
        $c['allow_supervisor_talk'] ? 1 : 0,
        $c['excluded_processes'] ?? '', $c['blocked_sites'] ?? '', $c['email'],
    ]);
}

/**
 * The legacy seconds view of a millisecond interval: 0 stays 0 (off), anything
 * live rounds up so an old agent never receives a 0 it would read as "stop".
 */
function live_interval_seconds(int $ms): int {
    if ($ms <= 0) return 0;
    return max(1, (int) ceil($ms / 1000));
}

/** The live-stream interval in ms, tolerating a row that predates the column. */
function live_interval_ms(array $c): int {
    if (array_key_exists('live_stream_interval_ms', $c)) {
        $ms = (int) $c['live_stream_interval_ms'];
        return $ms >= 0 ? $ms : 0;
    }
    /* Row from before the column existed: the seconds value is all we have. */
    return max(0, (int) ($c['live_stream_interval_seconds'] ?? 0)) * 1000;
}

function all_configs(): array {
    return rows('SELECT * FROM agent_configs ORDER BY email ASC');
}

/** The JSON the agent receives for its policy. Exact camelCase keys as Spring. */
function config_payload(array $c): array {
    $ms = live_interval_ms($c);
    return [
        'screenshotIntervalSeconds' => (int) ($c['screenshot_interval_seconds'] ?? 300),
        // New agents take the millisecond value; the seconds mirror keeps old
        // agents (which clamp to whole seconds) streaming instead of reading a
        // 0 and going quiet.
        'liveStreamIntervalMs'      => $ms,
        'liveStreamIntervalSeconds' => live_interval_seconds($ms),
        'idleThresholdSeconds'      => (int) ($c['idle_threshold_seconds'] ?? 180),
        'imageQuality'              => (int) ($c['image_quality'] ?? 55),
        'imageMaxWidth'             => (int) ($c['image_max_width'] ?? 1280),
        'sampleIntervalSeconds'     => (int) ($c['sample_interval_seconds'] ?? 10),
        'breakAllowanceMinutes'     => (int) ($c['break_allowance_minutes'] ?? 30),
        'captureScreenshots'        => (bool) ($c['capture_screenshots'] ?? true),
        'trackMouseKeyboard'        => (bool) ($c['track_mouse_keyboard'] ?? true),
        'trackAppUsage'             => (bool) ($c['track_app_usage'] ?? true),
        'trackWebsites'             => (bool) ($c['track_websites'] ?? true),
        'allowSupervisorTalk'        => (bool) ($c['allow_supervisor_talk'] ?? true),
        'excludedProcesses'         => $c['excluded_processes'] ?? '',
        'blockedSites'              => $c['blocked_sites'] ?? '',
    ];
}

function clamp_cfg(int $value, int $min, int $max, int $fallback): int {
    if ($value <= 0) return $fallback;
    return max($min, min($max, $value));
}

/* ------------------------------------------------------------- ingestion ---- */

/**
 * Records time on one foreground target. Race-safe upsert: when two requests
 * create the same (email, work_date, target_key) at once, the loser re-selects.
 */
function usage_record_sample(string $email, string $targetKey, ?string $targetType, ?string $displayName,
                             ?string $processName, ?string $windowTitle, ?string $host, ?string $category,
                             int $seconds, bool $idle): ?array {
    if ($targetKey === '' || $seconds <= 0) return null;
    $date = today();
    $capped = min($seconds, MAX_SAMPLE_SECONDS);
    $nowMs = (int) round(microtime(true) * 1000);

    $row = one('SELECT * FROM app_usages WHERE email=? AND work_date=? AND target_key=?', [$email, $date, $targetKey]);
    $isNew = $row === null;
    if ($isNew) {
        try {
            q('INSERT INTO app_usages (email, work_date, target_key, target_type, display_name, process_name, window_title, host, category, seconds, sample_count, first_seen, last_seen, updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,0,?,?,?)', [
                $email, $date, $targetKey, $targetType ?? USAGE_TYPE_APP, $displayName, $processName,
                $windowTitle, $host, $category, $nowMs, $nowMs, now_sql(),
            ]);
            $row = one('SELECT * FROM app_usages WHERE email=? AND work_date=? AND target_key=?', [$email, $date, $targetKey]);
        } catch (Throwable $e) {
            $row = one('SELECT * FROM app_usages WHERE email=? AND work_date=? AND target_key=?', [$email, $date, $targetKey]);
            $isNew = $row === null;
        }
    }
    if ($row === null) return null;

    $row['target_type'] = $targetType === null ? USAGE_TYPE_APP : $targetType;
    if ($displayName !== null) $row['display_name'] = $displayName;
    if ($processName !== null) $row['process_name'] = $processName;
    if ($windowTitle !== null) $row['window_title'] = $windowTitle;
    if ($host !== null) $row['host'] = $host;
    $row['category'] = $category === null
        ? usage_category_for($row['target_type'], $host, $processName)
        : $category;
    $row['seconds'] = (int) $row['seconds'] + $capped;
    $row['sample_count'] = (int) $row['sample_count'] + 1;
    $row['last_seen'] = $nowMs;
    $row['updated_at'] = now_sql();

    q('UPDATE app_usages SET target_type=?, display_name=?, process_name=?, window_title=?, host=?, category=?, seconds=?, sample_count=?, last_seen=?, updated_at=? WHERE id=?', [
        $row['target_type'], $row['display_name'], $row['process_name'], $row['window_title'],
        $row['host'], $row['category'], (int) $row['seconds'], (int) $row['sample_count'],
        $row['last_seen'], $row['updated_at'], (int) $row['id'],
    ]);
    if ($isNew) usage_refresh_counters($email, $date);
    return $row;
}

/** Recomputes the "N apps / N sites" counters on the employee's day record. */
function usage_refresh_counters(string $email, string $date): void {
    $r = one('SELECT * FROM track_records WHERE email=? AND work_date=?', [$email, $date]);
    if ($r === null) return;
    $r['distinct_apps'] = (int) val('SELECT COUNT(DISTINCT target_key) FROM app_usages WHERE email=? AND work_date=? AND target_type=?', [$email, $date, USAGE_TYPE_APP]);
    $r['distinct_sites'] = (int) val('SELECT COUNT(DISTINCT target_key) FROM app_usages WHERE email=? AND work_date=? AND target_type=?', [$email, $date, USAGE_TYPE_WEB]);
    q('UPDATE track_records SET distinct_apps=?, distinct_sites=? WHERE id=?', [$r['distinct_apps'], $r['distinct_sites'], (int) $r['id']]);
    /* importing track_save here only to avoid a second circular require */
}

/* ------------------------------------------------------------- reporting ---- */

function usage_for(string $email, string $date, ?string $type = null): array {
    if ($type !== null) {
        return rows('SELECT * FROM app_usages WHERE email=? AND work_date=? AND target_type=? ORDER BY seconds DESC', [$email, $date, $type]);
    }
    return rows('SELECT * FROM app_usages WHERE email=? AND work_date=? ORDER BY seconds DESC', [$email, $date]);
}

function usage_for_all(string $date): array {
    return rows('SELECT * FROM app_usages WHERE work_date=? ORDER BY seconds DESC', [$date]);
}

function usage_app_seconds(string $email, string $date): int {
    return (int) (val('SELECT COALESCE(SUM(seconds),0) FROM app_usages WHERE email=? AND work_date=? AND target_type=?', [$email, $date, USAGE_TYPE_APP]) ?? 0);
}

function usage_site_seconds(string $email, string $date): int {
    return (int) (val('SELECT COALESCE(SUM(seconds),0) FROM app_usages WHERE email=? AND work_date=? AND target_type=?', [$email, $date, USAGE_TYPE_WEB]) ?? 0);
}

function usage_app_count(string $email, string $date): int {
    return (int) (val('SELECT COUNT(DISTINCT target_key) FROM app_usages WHERE email=? AND work_date=? AND target_type=?', [$email, $date, USAGE_TYPE_APP]) ?? 0);
}

function usage_site_count(string $email, string $date): int {
    return (int) (val('SELECT COUNT(DISTINCT target_key) FROM app_usages WHERE email=? AND work_date=? AND target_type=?', [$email, $date, USAGE_TYPE_WEB]) ?? 0);
}

function usage_dates(string $email): array {
    $out = [];
    foreach (rows('SELECT DISTINCT work_date FROM app_usages WHERE email=? ORDER BY work_date DESC', [$email]) as $r) {
        $out[] = $r['work_date'];
    }
    return $out;
}

/** Ordered host -> seconds for the "websites used" table. */
function usage_top_sites(string $email, string $date, int $limit): array {
    $out = [];
    foreach (rows('SELECT host AS k, SUM(seconds) AS s FROM app_usages WHERE email=? AND work_date=? AND target_type=? AND host IS NOT NULL GROUP BY host ORDER BY s DESC LIMIT ' . (int) max(1, $limit),
        [$email, $date, USAGE_TYPE_WEB]) as $r) {
        $out[$r['k']] = (int) $r['s'];
    }
    return $out;
}

/** Ordered process -> seconds for the "apps used" table. */
function usage_top_apps(string $email, string $date, int $limit): array {
    $out = [];
    foreach (rows('SELECT process_name AS k, SUM(seconds) AS s FROM app_usages WHERE email=? AND work_date=? AND target_type=? AND process_name IS NOT NULL GROUP BY process_name ORDER BY s DESC LIMIT ' . (int) max(1, $limit),
        [$email, $date, USAGE_TYPE_APP]) as $r) {
        $out[$r['k']] = (int) $r['s'];
    }
    return $out;
}

/** Ordered host -> seconds for the whole company on a day. */
function usage_top_sites_company(string $date, int $limit): array {
    $totals = [];
    foreach (usage_for_all($date) as $u) {
        if ($u['target_type'] !== USAGE_TYPE_WEB || empty($u['host'])) continue;
        $totals[$u['host']] = ($totals[$u['host']] ?? 0) + (int) $u['seconds'];
    }
    arsort($totals);
    return array_slice($totals, 0, $limit, true);
}

/** Ordered process -> seconds for the whole company on a day. */
function usage_top_apps_company(string $date, int $limit): array {
    $totals = [];
    foreach (usage_for_all($date) as $u) {
        if ($u['target_type'] !== USAGE_TYPE_APP || empty($u['process_name'])) continue;
        $totals[$u['process_name']] = ($totals[$u['process_name']] ?? 0) + (int) $u['seconds'];
    }
    arsort($totals);
    return array_slice($totals, 0, $limit, true);
}

/** Sum of tracked seconds per employee for the day (admin overview). */
function usage_seconds_by_employee(string $date): array {
    $out = [];
    foreach (usage_for_all($date) as $u) {
        $out[$u['email']] = ($out[$u['email']] ?? 0) + (int) $u['seconds'];
    }
    return $out;
}

function usage_seconds_total(string $date): int {
    return (int) (val('SELECT COALESCE(SUM(seconds),0) FROM app_usages WHERE work_date=?', [$date]) ?? 0);
}

/** The row shape the monitor drill-down / agent apps lists consume. */
function usage_api_row(array $u): array {
    return [
        'name'     => $u['display_name'],
        'key'      => $u['target_key'],
        'type'     => $u['target_type'],
        'category' => $u['category'],
        'time'     => usage_time_text((int) $u['seconds']),
        'seconds'  => (int) $u['seconds'],
    ];
}

/* ---------------------------------------------------------------- helpers ---- */

function usage_time_text(int $secs): string {
    return time_text($secs);
}

function usage_category_for(?string $targetType, ?string $host, ?string $processName): string {
    if ($targetType === USAGE_TYPE_IDLE) return 'Idle';
    if ($targetType === USAGE_TYPE_LOCK) return 'Locked';
    if ($targetType === USAGE_TYPE_WEB) return usage_web_category($host);
    return usage_app_category($processName);
}

function usage_web_category(?string $host): string {
    if ($host === null || $host === '') return 'Web';
    $h = strtolower($host);
    if (usage_contains($h, 'github', 'gitlab', 'bitbucket')) return 'Development';
    if (usage_contains($h, 'stackoverflow', 'w3schools', 'mdn', 'geeksforgeeks')) return 'Learning';
    if (strpos($h, 'mail.') !== false) return 'Email';
    if (usage_contains($h, 'docs.google', 'drive.google', 'office.com', 'notion.so', 'slack')) return 'Productivity';
    if (usage_contains($h, 'youtube', 'netflix', 'instagram', 'facebook', 'twitter', 'x.com', 'reddit')) return 'Social / Entertainment';
    if (usage_contains($h, 'chat.openai', 'claude.ai', 'gemini')) return 'AI Tools';
    if (usage_contains($h, 'jira', 'trello', 'asana', 'monday')) return 'Project Management';
    if (usage_contains($h, 'amazon', 'flipkart', 'myntra')) return 'Shopping';
    return 'Web';
}

function usage_app_category(?string $processName): string {
    if ($processName === null || $processName === '') return 'Application';
    $p = strtolower($processName);
    if (usage_contains($p, 'code', 'idea', 'studio', 'pycharm', 'webstorm', 'eclipse', 'netbeans', 'notepad++', 'sublime')) return 'Development';
    if (usage_contains($p, 'chrome', 'firefox', 'msedge', 'edge', 'opera', 'brave', 'browser')) return 'Web Browser';
    if (usage_contains($p, 'word', 'excel', 'powerpnt', 'outlook', 'onenote', 'notepad', 'libreoffice')) return 'Office';
    if (usage_contains($p, 'slack', 'teams', 'zoom', 'skype', 'discord', 'whatsapp', 'telegram')) return 'Communication';
    if (usage_contains($p, 'figma', 'photoshop', 'illustrator', 'xd', 'sketch', 'canva')) return 'Design';
    if (usage_contains($p, 'postman', 'insomnia', 'putty', 'winscp', 'xshell', 'moba')) return 'Tools';
    if (usage_contains($p, 'sqlserver', 'mysql', 'pgadmin', 'dbeaver', 'mongodb', 'redis')) return 'Database';
    if (usage_contains($p, 'cmd', 'powershell', 'terminal', 'windowsterminal', 'bash')) return 'Terminal';
    if (usage_contains($p, 'spotify', 'vlc', 'media', 'itunes', 'music', 'netflix', 'primevideo')) return 'Media';
    return 'Application';
}

function usage_contains(string $value, ...$needles): bool {
    foreach ($needles as $n) {
        if (strpos($value, $n) !== false) return true;
    }
    return false;
}

/** True when a sample should be ignored entirely (system shell, agent itself, blocked host). */
function usage_is_ignorable(array $cfg, ?string $processName, ?string $host): bool {
    if (usage_matches_csv($cfg['excluded_processes'] ?? '', $processName)) return true;
    if ($host !== null && $host !== '' && usage_matches_csv($cfg['blocked_sites'] ?? '', $host)) return true;
    return false;
}

/** Matches the Java AgentConfig.matchesCsv semantics: exact or dotted-suffix match. */
function usage_matches_csv(?string $csv, ?string $value): bool {
    if ($csv === null || $csv === '' || $value === null || $value === '') return false;
    $v = strtolower(trim($value));
    $parts = explode(',', strtolower($csv));
    foreach ($parts as $part) {
        $p = trim($part);
        if ($p === '') continue;
        if ($v === $p) return true;
        if (substr($v, -strlen('.' . $p)) === '.' . $p || strpos($v, '.' . $p) !== false) return true;
    }
    return false;
}