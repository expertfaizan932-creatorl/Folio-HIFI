<?php
/**
 * WorkFolio PHP server - configuration, database, schema bootstrap.
 *
 * This is a straight port of the Spring Boot server so the SAME Windows agent
 * (WorkFolioAgent.exe) and the SAME reports UI work against cheap shared PHP
 * hosting instead of a VPS.
 *
 * Configuration resolution order:
 *   1. real environment variables (WF_DB_DRIVER, WF_DB_HOST, ...)
 *   2. constants in config.local.php (the file shared hosting users edit)
 *   3. defaults (SQLite file database, zero setup)
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$WF = [];

/* The office timezone every recorded time is expressed in. Override it with the
 * WF_TIMEZONE environment variable or $WF['timezone'] in config.local.php.
 * Kept as a constant so the resolver below and any later fallback agree. */
define('WF_DEFAULT_TIMEZONE', 'Asia/Karachi');

/* ----------------------------------------------------------------- config -- */

$WF['db_driver']   = strtolower((string) (getenv('WF_DB_DRIVER') ?: 'sqlite'));
$WF['db_host']     = (string) (getenv('WF_DB_HOST')     ?: 'localhost');
$WF['db_port']     = (string) (getenv('WF_DB_PORT')     ?: '3306');
$WF['db_name']     = (string) (getenv('WF_DB_NAME')     ?: 'workfolio');
$WF['db_user']     = (string) (getenv('WF_DB_USER')     ?: 'workfolio');
$WF['db_password'] = (string) (getenv('WF_DB_PASSWORD') ?: '');
$WF['db_params']   = (string) (getenv('WF_DB_PARAMS')   ?: '');
/* Screenshots + SQLite database. Default must stay under the app's own data/
 * folder - the root .htaccess blocks ^data/ from HTTP so uploads are never
 * web-reachable (lib/data would bypass that block). */
$WF['storage']     = (string) (getenv('WF_STORAGE')     ?: __DIR__ . '/../data');
$WF['retention']   = (int)    (getenv('WF_RETENTION_DAYS') ?: 14);
/* Daily productive-time target the reports classify each day against. Kept at 8
 * hours to match the desktop agent's ProductivityTarget default, so the badge an
 * employee sees in the tray and the one HR sees on the web come from one number.
 * Override with WF_WORKDAY_TARGET_HOURS or $WF['workday_target_hours']. */
$WF['workday_target_hours'] = (float) (getenv('WF_WORKDAY_TARGET_HOURS') ?: 8);
/* The office timezone. This is the single value every clock in the app is
 * measured against, and it used to default to an EMPTY string - which meant
 * date_default_timezone_set() was never called and PHP silently fell back to
 * whatever php.ini happened to say, on shared hosting almost always UTC. Every
 * check-in, break, screenshot and duration was then written five hours off, and
 * the report disagreed with the wall clock on the wall. Set it once, here. */
$WF['timezone']    = (string) (getenv('WF_TIMEZONE') ?: WF_DEFAULT_TIMEZONE);
$WF['show_demo']   = getenv('WF_SHOW_DEMO') === '1';
/* Outgoing mail. Nothing is sent until mail_from names a real address on the
 * host's own domain - mail() on shared hosting is refused (or silently dropped)
 * for anything else, and an unconfigured site must not look like a broken one.
 * The password mail in lib/mailer.php is the only consumer. */
$WF['mail_from']      = (string) (getenv('WF_MAIL_FROM') ?: '');
$WF['mail_from_name'] = (string) (getenv('WF_MAIL_FROM_NAME') ?: 'Folio');
$WF['mail_enabled']   = getenv('WF_MAIL_ENABLED') !== '0';
/* Password for the emergency ADMIN that seed_defaults() restores if the table is
 * ever empty. Set WF_ADMIN_PASSWORD on the host so it is never the demo value. */
$WF['admin_password'] = (string) (getenv('WF_ADMIN_PASSWORD') ?: '');
define('WF_APP_VERSION', '1.4.0');

$localCfg = __DIR__ . '/config.local.php';
if (!is_file($localCfg)) $localCfg = __DIR__ . '/../config.local.php';
if (is_file($localCfg)) {
    require $localCfg; // may redefine $WF entries via $WF['db_driver'] = 'mysql'; ...
}

/* --------------------------------------------------------------- timezone --- */

/**
 * Decides the one timezone the whole app measures time in.
 *
 * Order of preference:
 *   1. WF_TIMEZONE / $WF['timezone']  - the office's own setting
 *   2. a non-UTC value already set in php.ini - the host was configured on purpose
 *   3. WF_DEFAULT_TIMEZONE            - the shipped office default
 *
 * Step 2 exists because shared hosts often do set date.timezone correctly, and
 * overriding a deliberate host setting with a hard-coded guess would be wrong.
 * UTC is treated as "nobody configured anything", which is the case that caused
 * the original bug.
 *
 * An unknown zone name is never accepted: PHP would emit an E_WARNING on every
 * single request and, worse, quietly keep using the previous value.
 */
function wf_resolve_timezone(string $configured): string {
    $candidates = [];
    $candidates[] = trim($configured);
    if (function_exists('date_default_timezone_get')) {
        $inUse = (string) @date_default_timezone_get();
        if ($inUse !== '' && strcasecmp($inUse, 'UTC') !== 0) $candidates[] = $inUse;
    }
    $candidates[] = WF_DEFAULT_TIMEZONE;

    foreach ($candidates as $tz) {
        if ($tz === '') continue;
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            error_log('folio: unknown timezone "' . $tz . '" ignored');
            continue;
        }
        return $tz;
    }
    return 'UTC';
}

/** The app's timezone, as an IANA name. The one answer for "what time is it". */
function wf_tz(): string {
    global $WF;
    return (string) ($WF['timezone'] ?? WF_DEFAULT_TIMEZONE);
}

/** Offset from UTC in minutes, DST included. Asia/Karachi is a flat +300. */
function wf_tz_offset_min(): int {
    return (int) round((new DateTimeImmutable('now', new DateTimeZone(wf_tz())))->getOffset() / 60);
}

/**
 * Short label for the clock chip, e.g. "PKT +05:00". Showing it next to the time
 * is what makes a wrong timezone obvious at a glance instead of something a
 * manager has to work out from a check-in that looks two hours too early.
 */
function wf_tz_label(): string {
    $min = wf_tz_offset_min();
    $sign = $min < 0 ? '-' : '+';
    $abs = abs($min);
    $abbr = (new DateTimeZone(wf_tz()))->getLocation()['country_code'] ?? '';
    $abbr = $abbr === '' ? '' : strtoupper($abbr);
    return $abbr . ' ' . $sign . sprintf('%02d:%02d', intdiv($abs, 60), $abs % 60);
}

$WF['timezone'] = wf_resolve_timezone((string) ($WF['timezone'] ?? ''));
date_default_timezone_set($WF['timezone']);

/* --------------------------------------------------------------- session --- */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('WORKFOLIOSID');
    session_start();
}

/* ------------------------------------------------------------------ base ---- */

/**
 * URL prefix the app is served under, e.g. '' at a domain root or
 * '/workfolio' in a sub-folder. Everything links through base_url() so the
 * same files work in both setups.
 */
function app_base(): string {
    static $base = null;
    if ($base === null) {
        /* Derive the base from DOCUMENT_ROOT vs the app directory, NOT from
         * SCRIPT_NAME: on Apache SCRIPT_NAME is /index.php but the PHP built-in
         * dev server sets it to the requested path (dirname() would then report
         * a bogus sub-folder like /admin for /admin/usage.csv). Comparing real
         * paths is correct in all four cases: root, sub-folder, Windows, dev. */
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $appDir  = realpath(__DIR__ . '/..');
        $base = '';
        if ($docRoot !== false && $appDir !== false) {
            $dr = rtrim(str_replace('\\', '/', $docRoot), '/') . '/';
            $ad = rtrim(str_replace('\\', '/', $appDir), '/') . '/';
            if (strncasecmp($ad, $dr, strlen($dr)) === 0) {
                $rel = substr($appDir, strlen($docRoot));
                $base = ($rel === '' || $rel === false) ? '' : '/' . ltrim(str_replace('\\', '/', $rel), '/');
            }
        }
    }
    return $base;
}

function base_url(string $path = '/'): string {
    $b = app_base();
    if ($path === '') $path = '/';
    if ($path[0] !== '/') $path = '/' . $path;
    return ($b === '/' || $b === '') ? $path : $b . $path;
}

/**
 * Scheme + host of this request, for links that must work outside the browser
 * (the sign-in link in a password email).
 *
 * The Host header is client-supplied, so it is pattern-checked before it is put
 * anywhere. Left unchecked, a crafted Host would end up as a phishing link in
 * somebody's inbox - the mail is sent to a real employee, so that is exactly
 * the wrong place to trust a header. A forwarded host (reverse proxy) is
 * preferred, then the plain one, then the server's own name.
 */
function app_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string) ($_SERVER['SERVER_PORT'] ?? '')) === '443';

    $host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($host === '') $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') $host = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));
    /* only a plain hostname (optionally with a numeric port) - no userinfo, no
     * path, no spaces, nothing a client could use to bend the URL elsewhere */
    if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:[0-9]{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host;
}

/* -------------------------------------------------------------------- db ---- */

$WF_PDO = null;

function wf_pdo(): PDO {
    global $WF, $WF_PDO;
    if ($WF_PDO instanceof PDO) return $WF_PDO;

    $driver = $WF['db_driver'];
    if ($driver === 'mysql') {
        $dsn = 'mysql:host=' . $WF['db_host'] . ';port=' . $WF['db_port'] . ';dbname=' . $WF['db_name'] . ';charset=utf8mb4';
        if (!empty($WF['db_params'])) $dsn .= ';' . $WF['db_params'];
        $WF_PDO = new PDO($dsn, $WF['db_user'], $WF['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $file = rtrim($WF['storage'], '/\\') . '/db.sqlite';
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        $WF_PDO = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $WF_PDO->exec('PRAGMA busy_timeout = 10000');
        $WF_PDO->exec('PRAGMA journal_mode = WAL');
        $WF_PDO->exec('PRAGMA foreign_keys = ON');
    }
    return $WF_PDO;
}

function wf_driver(): string {
    global $WF;
    return $WF['db_driver'];
}

/* query helpers ------------------------------------------------------------ */

function q(string $sql, array $p = []): PDOStatement {
    $st = wf_pdo()->prepare($sql);
    $st->execute($p);
    return $st;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function one(string $sql, array $p = []): ?array {
    $r = q($sql, $p)->fetch();
    return $r === false ? null : $r;
}
function val(string $sql, array $p = []) {
    $r = q($sql, $p)->fetchColumn();
    return $r === false ? null : $r;
}
function execq(string $sql, array $p = []): int { return (int) q($sql, $p)->rowCount(); }
function last_id(): int { return (int) wf_pdo()->lastInsertId(); }

/* time helpers ------------------------------------------------------------- */

function now_sql(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }

/**
 * Parses a 'Y-m-d H:i:s' column written by now_sql() back to a unix stamp.
 *
 * strtotime() alone is unsafe here for two reasons. It uses the *server's*
 * date_default_timezone at the moment it is called, which is fine, but it also
 * happily accepts a bare 'H:i:s' or 'Y-m-d' and resolves the missing half
 * against TODAY - so a malformed column silently becomes a timestamp on the
 * current date. Every duration in the app is a difference between two of these,
 * so one bad parse turns into a plausible but completely wrong number of hours.
 * Anything that does not match the exact stored format is therefore rejected
 * rather than guessed at, and null propagates.
 */
function ts_of(?string $dt): ?int {
    if ($dt === null) return null;
    $dt = trim($dt);
    if ($dt === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $dt, $m)) {
        return null;
    }
    return mktime(
        (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0),
        (int) $m[2], (int) $m[3], (int) $m[1]
    );
}

/** End of the given 'Y-m-d' day, as a unix stamp. The hard ceiling for a day. */
function day_end_ts(string $day): int {
    $y = (int) substr($day, 0, 4);
    $m = (int) substr($day, 5, 2);
    $d = (int) substr($day, 8, 2);
    if ($y < 1970 || $m < 1 || $m > 12 || $d < 1 || $d > 31) return time();
    $ts = mktime(23, 59, 59, $m, $d, $y);
    return $ts === false ? time() : $ts;
}

/**
 * Seconds between a stored timestamp and now, never negative.
 *
 * An unreadable or missing timestamp yields null, NOT a number. Coercing it to 0
 * and subtracting from time() is what turns "no timestamp" into "56 years ago",
 * which then renders as "1623h ago" or "62y ago" in the UI. Callers decide what
 * an unknown age means; this function only reports what it knows.
 */
function secs_since(?string $dt, ?int $now = null): ?int {
    $t = ts_of($dt);
    if ($t === null) return null;
    return max(0, (($now ?? time()) - $t));
}

/* text helpers ------------------------------------------------------------- */

function esc(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function app_version(): string { return defined('WF_APP_VERSION') ? WF_APP_VERSION : '1.0'; }

function fmt_dur(int $secs): string {
    if ($secs < 0) $secs = 0;
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    return sprintf('%02dh %02dm', $h, $m);
}

function time_text(int $secs): string {
    if ($secs < 0) $secs = 0;
    $h = intdiv($secs, 3600);
    $m = intdiv(($secs % 3600), 60);
    $s = $secs % 60;
    return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm %02ds', $m, $s);
}

function short_time_text(int $secs): string {
    if ($secs < 0) $secs = 0;
    $h = intdiv($secs, 3600);
    $m = intdiv(($secs % 3600), 60);
    return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm', $m);
}

function time_12(int $secs): string {
    $h = intdiv($secs, 3600) % 24;
    $m = intdiv($secs % 3600, 60);
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $hh = $h % 12; if ($hh === 0) $hh = 12;
    return sprintf('%d:%02d %s', $hh, $m, $ampm);
}

function mouse_distance_text(int $px): string {
    return $px < 1000 ? $px . ' px' : sprintf('%.1f k px', $px / 1000.0);
}

/**
 * A stored timestamp as "09:12 AM", or '-' when there is none or it is
 * unreadable. The lowercase meridiem matches every other clock string in the
 * app so the columns line up visually.
 */
function hhmm_a(?string $dt): string {
    $t = ts_of($dt);
    if ($t === null) return '-';
    return date('h:i a', $t);
}

/**
 * A stored timestamp as "05 Mar, 09:12 AM", or '-' when there is none or it is
 * unreadable. The null guard matters: a bad value used to reach strtotime(),
 * return false, and render as "01 Jan 1970" - a 56-year-old screenshot next to
 * the real ones.
 */
function dt_fmt(?string $dt, string $format = 'd M, h:i a'): string {
    $t = ts_of($dt);
    if ($t === null) return '-';
    return date($format, $t);
}

/* ------------------------------------------------------------------ schema -- */

function schema_sql(): array {
    if (wf_driver() === 'mysql') {
        return [
"CREATE TABLE IF NOT EXISTS employees (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(120), last_name VARCHAR(120), email VARCHAR(190) NOT NULL,
  phone VARCHAR(60), department VARCHAR(120), designation VARCHAR(120),
  `role` VARCHAR(20) NOT NULL DEFAULT 'EMPLOYEE', salary DOUBLE, join_date VARCHAR(30),
  address VARCHAR(500), password VARCHAR(255),
  UNIQUE KEY uq_emp_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS agent_configs (
  email VARCHAR(190) PRIMARY KEY,
  screenshot_interval_seconds INT NOT NULL DEFAULT 300,
  live_stream_interval_seconds INT NOT NULL DEFAULT 1,
  live_stream_interval_ms INT NOT NULL DEFAULT 120,
  idle_threshold_seconds INT NOT NULL DEFAULT 180,
  image_quality INT NOT NULL DEFAULT 55,
  image_max_width INT NOT NULL DEFAULT 1280,
  sample_interval_seconds INT NOT NULL DEFAULT 10,
  break_allowance_minutes INT NULL DEFAULT 30,
  capture_screenshots TINYINT(1) NOT NULL DEFAULT 1,
  track_mouse_keyboard TINYINT(1) NOT NULL DEFAULT 1,
  track_app_usage TINYINT(1) NOT NULL DEFAULT 1,
  track_websites TINYINT(1) NOT NULL DEFAULT 1,
  allow_supervisor_talk TINYINT(1) NOT NULL DEFAULT 1,
  excluded_processes TEXT, blocked_sites TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS track_records (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(10) NOT NULL,
  check_in DATETIME, check_out DATETIME, break_start DATETIME, last_seen DATETIME,
  break_seconds BIGINT NOT NULL DEFAULT 0, active_seconds BIGINT NOT NULL DEFAULT 0,
  idle_seconds BIGINT NOT NULL DEFAULT 0, paused_seconds BIGINT NOT NULL DEFAULT 0,
  totals_locked TINYINT(1) NOT NULL DEFAULT 0, manual_locked TINYINT(1) NOT NULL DEFAULT 0,
  screenshot_count INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'OFF', last_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  keystrokes BIGINT NOT NULL DEFAULT 0, mouse_clicks BIGINT NOT NULL DEFAULT 0,
  mouse_scrolls BIGINT NOT NULL DEFAULT 0, mouse_distance_px BIGINT NOT NULL DEFAULT 0,
  current_app VARCHAR(255), current_title TEXT, current_host VARCHAR(255), current_category VARCHAR(60),
  distinct_apps INT NOT NULL DEFAULT 0, distinct_sites INT NOT NULL DEFAULT 0,
  host_name VARCHAR(255), agent_version VARCHAR(60), agent_ip VARCHAR(60), last_shot_at DATETIME,
  UNIQUE KEY uq_tr_email_date (email, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

  // One row per break the employee actually took, so HR gets a separate note per
  // break instead of only a day total.
  "CREATE TABLE IF NOT EXISTS break_records (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL, work_date VARCHAR(10) NOT NULL,
    break_start DATETIME NOT NULL, break_end DATETIME, seconds BIGINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_br_email_start (email, break_start),
    KEY ix_br_email_date (email, work_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS app_usages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(10) NOT NULL,
  target_key VARCHAR(400) NOT NULL, target_type VARCHAR(10) NOT NULL DEFAULT 'APP',
  display_name VARCHAR(500), process_name VARCHAR(500), window_title TEXT, host VARCHAR(400),
  category VARCHAR(80), seconds BIGINT NOT NULL DEFAULT 0, sample_count INT NOT NULL DEFAULT 0,
  first_seen BIGINT NOT NULL DEFAULT 0, last_seen BIGINT NOT NULL DEFAULT 0, updated_at DATETIME,
  UNIQUE KEY uq_au (email, work_date, target_key(190)),
  KEY idx_au_email_date (email, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS screenshots (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, captured_at DATETIME, file_name VARCHAR(300),
  `trigger` VARCHAR(20) NOT NULL DEFAULT 'AUTO', app_name VARCHAR(200),
  window_title VARCHAR(500), host VARCHAR(200), size_bytes BIGINT NOT NULL DEFAULT 0,
  live TINYINT(1) NOT NULL DEFAULT 0, image LONGTEXT,
  KEY idx_shot_email (email, captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// One row per employee, replaced on every live video frame: the stream lives in
// <storage>/live/<email>.jpg so a 1 fps broadcast costs one file, not 5,700.
"CREATE TABLE IF NOT EXISTS live_frames (
  email VARCHAR(190) PRIMARY KEY,
  seq BIGINT NOT NULL DEFAULT 0,
  captured_at DATETIME, file_name VARCHAR(300), size_bytes BIGINT NOT NULL DEFAULT 0,
  status VARCHAR(20), app_name VARCHAR(200), window_title VARCHAR(500),
  host VARCHAR(200), category VARCHAR(80), host_name VARCHAR(255),
  agent_version VARCHAR(60), ip VARCHAR(60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190), full_name VARCHAR(200), role VARCHAR(20),
  action VARCHAR(60), details TEXT, source VARCHAR(30), created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS devices (
          device_id BIGINT AUTO_INCREMENT PRIMARY KEY,
          email VARCHAR(190) NOT NULL,
          device_type VARCHAR(20) NOT NULL DEFAULT 'PC',
          device_key VARCHAR(190) NOT NULL DEFAULT 'PC',
          os_name VARCHAR(190),
          os_version VARCHAR(190),
          manufacturer VARCHAR(190),
          model VARCHAR(190),
          app_version VARCHAR(60),
          host_name VARCHAR(255),
          last_seen DATETIME,
          created_at DATETIME NOT NULL,
          UNIQUE KEY uq_dev_email_key (email, device_key),
          KEY ix_dev_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS agent_tokens (
          token VARCHAR(64) PRIMARY KEY, email VARCHAR(190) NOT NULL, device_id BIGINT NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* The HRM employee information form. One row per employee, holding the answer to
   every field of the nine-section form. Sections 1-7 and 9 are filled by the
   employee through their personal token link; section 8 is HR's alone. */
"CREATE TABLE IF NOT EXISTS employee_hrm_records (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  form_token VARCHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  full_name VARCHAR(200), father_name VARCHAR(200), cnic VARCHAR(30),
  dob VARCHAR(20), gender VARCHAR(20), marital_status VARCHAR(30),
  contact_number VARCHAR(40), personal_email VARCHAR(190),
  current_address TEXT, permanent_address TEXT,
  father_cnic VARCHAR(30), father_contact VARCHAR(40), father_occupation VARCHAR(200),
  emergency_contact_name VARCHAR(200), emergency_contact_number VARCHAR(40), emergency_relationship VARCHAR(120),
  highest_degree VARCHAR(160), degree_program VARCHAR(200), institute VARCHAR(200),
  graduation_year VARCHAR(20), certifications TEXT, skills TEXT,
  employee_code VARCHAR(60), joining_date VARCHAR(20), designation VARCHAR(160),
  department VARCHAR(160), employment_type VARCHAR(60), reporting_manager VARCHAR(200),
  previous_company VARCHAR(200), previous_experience TEXT, portfolio_link VARCHAR(400), linkedin VARCHAR(400),
  salary VARCHAR(60), bank_name VARCHAR(200), account_title VARCHAR(200), account_number VARCHAR(120),
  bank_confirmed INTEGER NOT NULL DEFAULT 0, bank_confirm_date VARCHAR(20),
  probation_period VARCHAR(60), probation_end_date VARCHAR(20), leave_record TEXT,
  attendance_record TEXT, performance_review TEXT, hr_remarks TEXT,
  employment_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  hr_updated_by VARCHAR(190), hr_updated_at DATETIME,
  declaration_text TEXT, signature_type VARCHAR(20), signature_name VARCHAR(200),
  signature_png TEXT, confirmed_at DATETIME, confirmed_ip VARCHAR(60),
  submitted_at DATETIME, last_saved_at DATETIME, created_at DATETIME,
  UNIQUE KEY uq_hrm_email (email), UNIQUE KEY uq_hrm_token (form_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* The Employee Form: hiring intake. One row per submission, filled in by whoever
   is doing the hiring about somebody who does not exist in the roster yet, so it
   is a separate table from employee_hrm_records rather than a new column there.
   Nothing here is unique on the person: the same candidate may apply twice, for
   two different roles, and both applications have to be on record. */
"CREATE TABLE IF NOT EXISTS employee_form_submissions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(200) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40), cnic VARCHAR(30), dob VARCHAR(20),
  gender VARCHAR(20), marital_status VARCHAR(30), current_address TEXT,
  position_title VARCHAR(160) NOT NULL,
  department VARCHAR(160), employment_type VARCHAR(60), work_location VARCHAR(120),
  joining_date VARCHAR(20), salary_expected VARCHAR(60), salary_offered VARCHAR(60),
  highest_degree VARCHAR(160), institute VARCHAR(200), graduation_year VARCHAR(20),
  previous_company VARCHAR(200), total_experience VARCHAR(60),
  skills TEXT, previous_experience TEXT, linkedin VARCHAR(400), portfolio VARCHAR(400),
  emergency_contact_name VARCHAR(200), emergency_contact_number VARCHAR(40), notes TEXT,
  cv_file VARCHAR(300), cv_orig VARCHAR(300), cv_mime VARCHAR(120),
  cv_size BIGINT NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'SUBMITTED',
  remarks TEXT,
  submitted_by VARCHAR(190), submitted_ip VARCHAR(60),
  status_updated_at DATETIME,
  created_at DATETIME NOT NULL, updated_at DATETIME,
  KEY ix_ef_status (status), KEY ix_ef_created (created_at), KEY ix_ef_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* Assets are repeatable: one employee can hold a laptop, a phone and a badge. */
"CREATE TABLE IF NOT EXISTS hrm_assets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  asset_name VARCHAR(200), asset_type VARCHAR(120), asset_serial VARCHAR(120),
  issued_date VARCHAR(20), condition_issued VARCHAR(200),
  acknowledged INTEGER NOT NULL DEFAULT 0, ack_date VARCHAR(20),
  return_date VARCHAR(20), condition_return VARCHAR(200), remarks TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* The stored file name is generated; the name the employee chose is kept only as
   a label. Nothing here is ever served straight off disk. */
"CREATE TABLE IF NOT EXISTS hrm_documents (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, doc_key VARCHAR(60) NOT NULL,
  file_name VARCHAR(300) NOT NULL, orig_name VARCHAR(300),
  mime VARCHAR(120), size_bytes BIGINT NOT NULL DEFAULT 0,
  uploaded_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ================= HR module (additive): org, leave, attendance, notices =========
   Nothing here alters a table the existing app reads. These are new tables the HR
   screens own; the roster itself stays in employees and is only referenced by email. */

/* Branches and departments are lookups now instead of free text, because the HR
   dashboard filters on them. teams hang off a department. All three are optional:
   an employee with no profile row simply has no branch/department assignment. */
"CREATE TABLE IF NOT EXISTS hr_branches (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL, city VARCHAR(120), address VARCHAR(300), phone VARCHAR(60),
  created_at DATETIME,
  UNIQUE KEY uq_hrb_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_departments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL, head_email VARCHAR(190),
  created_at DATETIME,
  UNIQUE KEY uq_hrd_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_teams (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL, department_id BIGINT, lead_email VARCHAR(190),
  created_at DATETIME,
  UNIQUE KEY uq_hrt_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* The HR-only half of an employee's record. Keyed by email like the HRM form, so
   the roster table is never reshaped. Salary stays in employee_hrm_records. */
"CREATE TABLE IF NOT EXISTS hr_employee_profiles (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  employee_code VARCHAR(60),
  branch_id BIGINT, department_id BIGINT, team_id BIGINT,
  manager_email VARCHAR(190),
  employment_type VARCHAR(60), employment_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  date_of_birth VARCHAR(20), gender VARCHAR(20), marital_status VARCHAR(30),
  cnic VARCHAR(30), contract_end VARCHAR(20),
  created_at DATETIME, updated_at DATETIME,
  UNIQUE KEY uq_hrp_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* Leave. Types are configurable; a balance row per employee/type/year is updated
   when a request is approved; requests carry the two-step manager -> HR approval. */
"CREATE TABLE IF NOT EXISTS hr_leave_types (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL, code VARCHAR(40) NOT NULL,
  annual_quota DOUBLE NOT NULL DEFAULT 0, paid TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME,
  UNIQUE KEY uq_hlt_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_leave_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, leave_type_id BIGINT NOT NULL,
  start_date VARCHAR(20) NOT NULL, end_date VARCHAR(20) NOT NULL,
  days DOUBLE NOT NULL DEFAULT 0, reason TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING', applied_at DATETIME,
  manager_email VARCHAR(190), manager_action_at DATETIME, manager_note TEXT,
  hr_email VARCHAR(190), hr_action_at DATETIME, hr_note TEXT,
  cancelled_at DATETIME, created_at DATETIME,
  KEY ix_hlr_email (email), KEY ix_hlr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_leave_balances (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, leave_type_id BIGINT NOT NULL, `year` INT NOT NULL,
  entitled_days DOUBLE NOT NULL DEFAULT 0, used_days DOUBLE NOT NULL DEFAULT 0,
  updated_at DATETIME,
  UNIQUE KEY uq_hlb (email, leave_type_id, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_holidays (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  holiday_date VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL,
  branch_id BIGINT, created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* One row per employee per day. The agent's track_records are the raw source; this
   table is the HR view of attendance and may be corrected by HR (source=MANUAL). */
"CREATE TABLE IF NOT EXISTS hr_attendance_days (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PRESENT',
  check_in VARCHAR(30), check_out VARCHAR(30),
  worked_seconds BIGINT NOT NULL DEFAULT 0,
  late_minutes INT NOT NULL DEFAULT 0, early_minutes INT NOT NULL DEFAULT 0,
  overtime_minutes INT NOT NULL DEFAULT 0,
  source VARCHAR(20) NOT NULL DEFAULT 'AGENT', note TEXT,
  updated_by VARCHAR(190), updated_at DATETIME,
  UNIQUE KEY uq_had (email, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_attendance_corrections (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(20) NOT NULL,
  requested_check_in VARCHAR(30), requested_check_out VARCHAR(30), reason TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  reviewed_by VARCHAR(190), reviewed_at DATETIME, review_note TEXT,
  created_at DATETIME,
  KEY ix_hac_email (email), KEY ix_hac_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, type VARCHAR(40) NOT NULL,
  title VARCHAR(200) NOT NULL, body TEXT, link VARCHAR(300), actor_email VARCHAR(190),
  read_at DATETIME, created_at DATETIME,
  KEY ix_hn_email (email, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_announcements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL, body TEXT,
  audience VARCHAR(20) NOT NULL DEFAULT 'ALL',
  branch_id BIGINT, department_id BIGINT,
  created_by VARCHAR(190), created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module Phase 2 (additive): recruitment / ATS =================
   Vacancies, interviews and offers are new tables the ATS screens own. The
   candidate pool is the existing employee_form_submissions table; an application
   is attached to a vacancy through hr_application_openings rather than a new
   column on the intake table, so the form itself is never reshaped. */

"CREATE TABLE IF NOT EXISTS hr_job_openings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  department_id BIGINT, branch_id BIGINT,
  employment_type VARCHAR(60), work_location VARCHAR(160),
  vacancies INT NOT NULL DEFAULT 1,
  salary_min VARCHAR(60), salary_max VARCHAR(60),
  description TEXT, requirements TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  posted_date VARCHAR(20), closing_date VARCHAR(20),
  hiring_manager VARCHAR(190),
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hjo_status (status), KEY ix_hjo_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_application_openings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  submission_id BIGINT NOT NULL,
  opening_id BIGINT,
  source VARCHAR(60), recruiter_email VARCHAR(190),
  linked_by VARCHAR(190), linked_at DATETIME,
  UNIQUE KEY uq_hao_sub (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_candidate_comms (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  submission_id BIGINT NOT NULL,
  channel VARCHAR(30) NOT NULL DEFAULT 'NOTE',
  direction VARCHAR(10) NOT NULL DEFAULT 'OUT',
  summary VARCHAR(200), body TEXT,
  logged_by VARCHAR(190), created_at DATETIME,
  KEY ix_hcc_sub (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_interviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  submission_id BIGINT NOT NULL, opening_id BIGINT,
  round_no INT NOT NULL DEFAULT 1,
  stage VARCHAR(20) NOT NULL DEFAULT 'SCHEDULED',
  mode VARCHAR(20) NOT NULL DEFAULT 'ONSITE',
  scheduled_at VARCHAR(30), interviewer VARCHAR(190), location VARCHAR(300),
  notes TEXT, feedback TEXT, rating INT NOT NULL DEFAULT 0,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hi_sub (submission_id), KEY ix_hi_stage (stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_offers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  submission_id BIGINT NOT NULL, opening_id BIGINT,
  position_title VARCHAR(160),
  offered_salary VARCHAR(60), joining_date VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT', notes TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  UNIQUE KEY uq_ho_sub (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module Phase 2 (additive): onboarding ======================
   A reusable checklist (template + items) is copied onto a plan for one new
   hire. Plans and their tasks are keyed by email, so a plan can exist before the
   person has a roster account. A plan copies its template's items; editing the
   template afterwards never rewrites a plan that already started. */

"CREATE TABLE IF NOT EXISTS hr_onboarding_templates (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  description TEXT,
  department_id BIGINT, branch_id BIGINT,
  employment_type VARCHAR(60),
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hot_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_onboarding_template_tasks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  template_id BIGINT NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'GENERAL',
  owner_role VARCHAR(20) NOT NULL DEFAULT 'HR',
  due_offset_days INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME,
  KEY ix_hott_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_onboarding_plans (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  full_name VARCHAR(200),
  template_id BIGINT,
  department_id BIGINT, branch_id BIGINT,
  designation VARCHAR(160), employment_type VARCHAR(60),
  joining_date VARCHAR(20), target_date VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'IN_PROGRESS',
  started_at DATETIME, completed_at DATETIME,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hop_email (email), KEY ix_hop_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_onboarding_tasks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'GENERAL',
  owner_role VARCHAR(20) NOT NULL DEFAULT 'HR',
  assignee_email VARCHAR(190),
  due_date VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  sort_order INT NOT NULL DEFAULT 0,
  notes TEXT,
  completed_by VARCHAR(190), completed_at DATETIME,
  created_at DATETIME, updated_at DATETIME,
  KEY ix_hotp_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): employee documents ======================
   A category is a document type (CNIC, CV, Contract...). A document belongs to
   one employee and one category; the file itself lives under <storage>/hr_docs
   and is never served off disk without an access re-check. Every change is
   written to hr_document_history, which is the who-did-what-when record the
   security requirements ask for. `sensitive` marks a category an employee may
   not read about themselves (a warning letter, a performance review). */
"CREATE TABLE IF NOT EXISTS hr_document_categories (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL, code VARCHAR(60) NOT NULL,
  `sensitive` TINYINT(1) NOT NULL DEFAULT 0,
  expiry_tracked TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME,
  UNIQUE KEY uq_hdc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_employee_documents (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  category_id BIGINT,
  title VARCHAR(200) NOT NULL,
  file_name VARCHAR(300) NOT NULL,
  orig_name VARCHAR(300), mime VARCHAR(120),
  size_bytes BIGINT NOT NULL DEFAULT 0,
  issued_date VARCHAR(20), expiry_date VARCHAR(20),
  notes TEXT,
  uploaded_by VARCHAR(190), uploaded_at DATETIME, updated_at DATETIME,
  expiry_notified_at DATETIME,
  KEY ix_hed_email (email), KEY ix_hed_cat (category_id), KEY ix_hed_expiry (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_document_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT NOT NULL,
  action VARCHAR(30) NOT NULL,
  actor_email VARCHAR(190), note TEXT, snapshot VARCHAR(300),
  created_at DATETIME,
  KEY ix_hedh_doc (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): performance management =================
   A cycle folds reviews for one appraisal period. A review is a single
   employee's appraisal; goals are what they were measured against. A review
   only reaches ACKNOWLEDGED when the employee signs it off in self-service. */
"CREATE TABLE IF NOT EXISTS hr_performance_cycles (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  period_start VARCHAR(20), period_end VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  description TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hpc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_performance_reviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  cycle_id BIGINT,
  email VARCHAR(190) NOT NULL,
  reviewer_email VARCHAR(190),
  review_type VARCHAR(20) NOT NULL DEFAULT 'MANAGER',
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  overall_rating INT NOT NULL DEFAULT 0,
  self_rating INT NOT NULL DEFAULT 0,
  strengths TEXT, improvements TEXT, comments TEXT, manager_comments TEXT,
  submitted_at DATETIME, acknowledged_at DATETIME,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hpr_email (email), KEY ix_hpr_cycle (cycle_id), KEY ix_hpr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_performance_goals (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  cycle_id BIGINT, review_id BIGINT,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  weight INT NOT NULL DEFAULT 0,
  progress INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  due_date VARCHAR(20),
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hpg_email (email), KEY ix_hpg_cycle (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): tasks & work ==========================
   A lightweight task board over the roster. Tasks are keyed by assignee email
   and carry a simple status ladder; comments are the thread on a task. */
"CREATE TABLE IF NOT EXISTS hr_tasks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  assignee_email VARCHAR(190), assigned_by VARCHAR(190),
  department_id BIGINT, branch_id BIGINT,
  priority VARCHAR(20) NOT NULL DEFAULT 'MEDIUM',
  status VARCHAR(20) NOT NULL DEFAULT 'TODO',
  progress INT NOT NULL DEFAULT 0,
  start_date VARCHAR(20), due_date VARCHAR(20),
  completed_at DATETIME,
  created_at DATETIME, updated_at DATETIME,
  KEY ix_ht_assignee (assignee_email), KEY ix_ht_status (status), KEY ix_ht_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_task_comments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT NOT NULL,
  author_email VARCHAR(190),
  body TEXT,
  created_at DATETIME,
  KEY ix_htc_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): payroll ===============================
   A structure holds the default earnings/deduction amounts. A run is one month
   for the whole roster; a payslip is one employee's line in that run. */
"CREATE TABLE IF NOT EXISTS hr_payroll_structures (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  basic VARCHAR(40) NOT NULL DEFAULT '0',
  house_rent VARCHAR(40) NOT NULL DEFAULT '0',
  medical VARCHAR(40) NOT NULL DEFAULT '0',
  transport VARCHAR(40) NOT NULL DEFAULT '0',
  other_allowance VARCHAR(40) NOT NULL DEFAULT '0',
  tax_percent VARCHAR(40) NOT NULL DEFAULT '0',
  eobi VARCHAR(40) NOT NULL DEFAULT '0',
  lwp_day_rate VARCHAR(40) NOT NULL DEFAULT '0',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_payroll_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  period VARCHAR(7) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  total_gross VARCHAR(40) NOT NULL DEFAULT '0',
  total_net VARCHAR(40) NOT NULL DEFAULT '0',
  headcount INT NOT NULL DEFAULT 0,
  notes TEXT,
  processed_by VARCHAR(190), processed_at DATETIME,
  created_at DATETIME, updated_at DATETIME,
  UNIQUE KEY uq_hprun_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_payslips (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT NOT NULL,
  email VARCHAR(190) NOT NULL,
  basic VARCHAR(40) NOT NULL DEFAULT '0',
  allowances VARCHAR(40) NOT NULL DEFAULT '0',
  bonus VARCHAR(40) NOT NULL DEFAULT '0',
  commission VARCHAR(40) NOT NULL DEFAULT '0',
  deductions VARCHAR(40) NOT NULL DEFAULT '0',
  tax VARCHAR(40) NOT NULL DEFAULT '0',
  advance_deduction VARCHAR(40) NOT NULL DEFAULT '0',
  attendance_deduction VARCHAR(40) NOT NULL DEFAULT '0',
  net VARCHAR(40) NOT NULL DEFAULT '0',
  notes TEXT,
  created_at DATETIME, updated_at DATETIME,
  KEY ix_hips_run (run_id), KEY ix_hips_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* Which salary structure each employee draws on when a run is generated.
   One row per person keeps the payroll math deterministic and auditable. */
"CREATE TABLE IF NOT EXISTS hr_payroll_assignments (
  email VARCHAR(190) NOT NULL,
  structure_id BIGINT NOT NULL,
  updated_by VARCHAR(190), updated_at DATETIME,
  PRIMARY KEY (email), KEY ix_hpa_struct (structure_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* One-off salary advances drawn against future payroll. Outstanding amounts
   are deducted from the next eligible run. */
"CREATE TABLE IF NOT EXISTS hr_payroll_advances (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  amount VARCHAR(40) NOT NULL DEFAULT '0',
  reason VARCHAR(255),
  status VARCHAR(20) NOT NULL DEFAULT 'OUTSTANDING',
  recovered_run_id BIGINT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  KEY ix_hpa_adv_email (email), KEY ix_hpa_adv_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): assets ================================
   An asset is one tagged item. An assignment links it to an employee until it
   is returned; only one open assignment may exist per asset. */
"CREATE TABLE IF NOT EXISTS hr_asset_categories (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL, code VARCHAR(60) NOT NULL,
  created_at DATETIME,
  UNIQUE KEY uq_hac_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_assets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  asset_tag VARCHAR(80) NOT NULL,
  name VARCHAR(200) NOT NULL,
  category_id BIGINT,
  serial_no VARCHAR(160), brand VARCHAR(120), model VARCHAR(160),
  purchase_date VARCHAR(20), purchase_cost VARCHAR(40),
  warranty_until VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'AVAILABLE',
  asset_condition VARCHAR(30) NOT NULL DEFAULT 'GOOD',
  notes TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME,
  UNIQUE KEY uq_ha_tag (asset_tag),
  KEY ix_ha_status (status), KEY ix_ha_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_asset_assignments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  asset_id BIGINT NOT NULL,
  email VARCHAR(190) NOT NULL,
  assigned_by VARCHAR(190),
  assigned_at DATETIME, returned_at DATETIME,
  condition_out VARCHAR(30), condition_in VARCHAR(30),
  notes TEXT,
  KEY ix_haa_asset (asset_id), KEY ix_haa_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): offboarding ===========================
   A plan is one leaver's checklist, keyed by email. It mirrors onboarding so
   the same clearance items (return asset, disable login, final settlement) can
   be tracked until the last day. */
"CREATE TABLE IF NOT EXISTS hr_offboarding_plans (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  full_name VARCHAR(200),
  offboard_type VARCHAR(20) NOT NULL DEFAULT 'RESIGNATION',
  resignation_date VARCHAR(20), last_working_date VARCHAR(20),
  reason TEXT, status VARCHAR(20) NOT NULL DEFAULT 'IN_PROGRESS',
  exit_interview TEXT, eligible_rehire TINYINT(1) NOT NULL DEFAULT 0,
  clearance_notes TEXT,
  notice_period_days INT NOT NULL DEFAULT 0, notice_end_date VARCHAR(20),
  source VARCHAR(20) NOT NULL DEFAULT 'HR',
  approved_by VARCHAR(190), approved_at DATETIME, decision_note TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME, completed_at DATETIME,
  KEY ix_hoff_email (email), KEY ix_hoff_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_offboarding_tasks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'GENERAL',
  owner_role VARCHAR(20) NOT NULL DEFAULT 'HR',
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  sort_order INT NOT NULL DEFAULT 0,
  notes TEXT,
  completed_by VARCHAR(190), completed_at DATETIME,
  created_at DATETIME, updated_at DATETIME,
  KEY ix_hofft_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): settings =============================
   A simple key/value store for HR configuration (working hours, leave policy,
   probation, payroll defaults, notification toggles). */
"CREATE TABLE IF NOT EXISTS hr_settings (
  skey VARCHAR(80) NOT NULL,
  svalue TEXT,
  updated_by VARCHAR(190), updated_at DATETIME,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= Manual update (SUPER_ADMIN) ================================
   The owner's own overrides: the company-wide expected-hours target, and a
   per-employee target on top of it. Everything else the owner types (clock
   times, active/idle/break minutes) is written straight onto track_records. */
"CREATE TABLE IF NOT EXISTS manual_settings (
  skey VARCHAR(80) NOT NULL,
  svalue TEXT,
  updated_by VARCHAR(190), updated_at DATETIME,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS manual_user_prefs (
  email VARCHAR(190) NOT NULL,
  target_hours DOUBLE NULL,
  updated_by VARCHAR(190), updated_at DATETIME,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* ============= HR module (additive): approval workflows ===================
   Admin-defined, multi-step approval chains. A workflow is a named, ordered
   list of approvers (by role, by email, or the requester's manager). A request
   snapshots its steps so later edits to the workflow never rewrite history. */
"CREATE TABLE IF NOT EXISTS hr_approval_workflows (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(400),
  request_type VARCHAR(60) NOT NULL DEFAULT 'GENERAL',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by VARCHAR(190), updated_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_approval_workflow_steps (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  workflow_id BIGINT NOT NULL,
  step_no INT NOT NULL DEFAULT 1,
  approver_kind VARCHAR(20) NOT NULL DEFAULT 'ROLE',
  approver_value VARCHAR(190) NOT NULL DEFAULT 'HR',
  label VARCHAR(160),
  created_at DATETIME,
  KEY ix_haws_wf (workflow_id, step_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_approval_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  workflow_id BIGINT,
  request_type VARCHAR(60) NOT NULL DEFAULT 'GENERAL',
  title VARCHAR(200) NOT NULL,
  body TEXT,
  amount VARCHAR(60),
  requested_by VARCHAR(190) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  current_step INT NOT NULL DEFAULT 1,
  total_steps INT NOT NULL DEFAULT 0,
  created_at DATETIME, updated_at DATETIME,
  decided_by VARCHAR(190), decided_at DATETIME, decision_note VARCHAR(400),
  KEY ix_har_req (requested_by), KEY ix_har_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hr_approval_steps (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,
  step_no INT NOT NULL,
  approver_kind VARCHAR(20) NOT NULL DEFAULT 'ROLE',
  approver_value VARCHAR(190) NOT NULL DEFAULT 'HR',
  label VARCHAR(160),
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  decided_by VARCHAR(190), decided_at DATETIME, note VARCHAR(400),
  created_at DATETIME,
  KEY ix_has_req (request_id, step_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* Remote Control: one line per command an admin aims at one installed agent.
   The agent polls only its own QUEUED rows, runs the line through cmd.exe and
   reports the exit code and captured output back. Every row names the admin who
   asked for it, which is what keeps the feature auditable. */
"CREATE TABLE IF NOT EXISTS remote_commands (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  target_email VARCHAR(190) NOT NULL,
  command TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'QUEUED',
  exitcode INT, output MEDIUMTEXT,
  requested_by VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL,
  started_at DATETIME, finished_at DATETIME,
  KEY ix_rc_target (target_email, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* Interactive terminal: one long-lived cmd.exe session per target. The admin
   opens a shell, types lines and reads what the console prints back; the input
   and output rows exist only for the life of the session and are deleted when
   it closes, so nothing a manager types here leaves a browsable history. */
"CREATE TABLE IF NOT EXISTS remote_shells (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  target_email VARCHAR(190) NOT NULL,
  opened_by VARCHAR(190) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'OPEN',
  cwd VARCHAR(500),
  opened_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  closed_at DATETIME,
  KEY ix_rs_target (target_email, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS remote_shell_in (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  shell_id BIGINT NOT NULL,
  command TEXT NOT NULL,
  delivered TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_rsi_shell (shell_id, delivered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS remote_shell_out (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  shell_id BIGINT NOT NULL,
  data MEDIUMTEXT,
  created_at DATETIME NOT NULL,
  KEY ix_rso_shell (shell_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

/* Remote file manager: one long-lived browsing session per target, plus the
   request queue (admin -> agent) and the reply queue (agent -> admin). The
   payloads carry JSON ops and base64 file chunks; rows live only for the life
   of the session and are deleted when it closes. */
"CREATE TABLE IF NOT EXISTS remote_fs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  target_email VARCHAR(190) NOT NULL,
  opened_by VARCHAR(190) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'OPEN',
  cwd VARCHAR(1000),
  opened_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  closed_at DATETIME,
  KEY ix_rf_target (target_email, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS remote_fs_in (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  fs_id BIGINT NOT NULL,
  command MEDIUMTEXT NOT NULL,
  delivered TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_rfi_fs (fs_id, delivered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS remote_fs_out (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  fs_id BIGINT NOT NULL,
  data MEDIUMTEXT,
  created_at DATETIME NOT NULL,
  KEY ix_rfo_fs (fs_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

    }
    return [
"CREATE TABLE IF NOT EXISTS employees (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  first_name VARCHAR(120), last_name VARCHAR(120), email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(60), department VARCHAR(120), designation VARCHAR(120),
  `role` VARCHAR(20) NOT NULL DEFAULT 'EMPLOYEE', salary REAL, join_date VARCHAR(30),
  address VARCHAR(500), password VARCHAR(255)
)",
"CREATE TABLE IF NOT EXISTS agent_configs (
  email TEXT PRIMARY KEY,
  screenshot_interval_seconds INTEGER NOT NULL DEFAULT 300,
  live_stream_interval_seconds INTEGER NOT NULL DEFAULT 1,
  live_stream_interval_ms INTEGER NOT NULL DEFAULT 120,
  idle_threshold_seconds INTEGER NOT NULL DEFAULT 180,
  image_quality INTEGER NOT NULL DEFAULT 55,
  image_max_width INTEGER NOT NULL DEFAULT 1280,
  sample_interval_seconds INTEGER NOT NULL DEFAULT 10,
  break_allowance_minutes INTEGER DEFAULT 30,
  capture_screenshots INTEGER NOT NULL DEFAULT 1,
  track_mouse_keyboard INTEGER NOT NULL DEFAULT 1,
  track_app_usage INTEGER NOT NULL DEFAULT 1,
  track_websites INTEGER NOT NULL DEFAULT 1,
  excluded_processes TEXT, blocked_sites TEXT
)",
"CREATE TABLE IF NOT EXISTS track_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(10) NOT NULL,
  check_in DATETIME, check_out DATETIME, break_start DATETIME, last_seen DATETIME,
break_seconds INTEGER NOT NULL DEFAULT 0, active_seconds INTEGER NOT NULL DEFAULT 0,
    idle_seconds INTEGER NOT NULL DEFAULT 0, paused_seconds INTEGER NOT NULL DEFAULT 0,
    totals_locked INTEGER NOT NULL DEFAULT 0, manual_locked INTEGER NOT NULL DEFAULT 0,
    screenshot_count INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'OFF', last_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  keystrokes INTEGER NOT NULL DEFAULT 0, mouse_clicks INTEGER NOT NULL DEFAULT 0,
  mouse_scrolls INTEGER NOT NULL DEFAULT 0, mouse_distance_px INTEGER NOT NULL DEFAULT 0,
  current_app VARCHAR(255), current_title TEXT, current_host VARCHAR(255), current_category VARCHAR(60),
  distinct_apps INTEGER NOT NULL DEFAULT 0, distinct_sites INTEGER NOT NULL DEFAULT 0,
  host_name VARCHAR(255), agent_version VARCHAR(60), agent_ip VARCHAR(60), last_shot_at DATETIME,
UNIQUE(email, work_date)
  )",
  "CREATE TABLE IF NOT EXISTS break_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(190) NOT NULL, work_date VARCHAR(10) NOT NULL,
    break_start DATETIME NOT NULL, break_end DATETIME, seconds INTEGER NOT NULL DEFAULT 0,
    UNIQUE(email, break_start)
  )",
  "CREATE INDEX IF NOT EXISTS ix_br_email_date ON break_records (email, work_date)",
"CREATE TABLE IF NOT EXISTS app_usages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(10) NOT NULL,
  target_key VARCHAR(400) NOT NULL, target_type VARCHAR(10) NOT NULL DEFAULT 'APP',
  display_name VARCHAR(500), process_name VARCHAR(500), window_title TEXT, host VARCHAR(400),
  category VARCHAR(80), seconds INTEGER NOT NULL DEFAULT 0, sample_count INTEGER NOT NULL DEFAULT 0,
  first_seen INTEGER NOT NULL DEFAULT 0, last_seen INTEGER NOT NULL DEFAULT 0, updated_at DATETIME,
  UNIQUE(email, work_date, target_key)
)",
"CREATE TABLE IF NOT EXISTS screenshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, captured_at DATETIME, file_name VARCHAR(300),
`trigger` VARCHAR(20) NOT NULL DEFAULT 'AUTO', app_name VARCHAR(200),
  window_title VARCHAR(500), host VARCHAR(200), size_bytes BIGINT NOT NULL DEFAULT 0,
  live INTEGER NOT NULL DEFAULT 0, image TEXT
)",
"CREATE TABLE IF NOT EXISTS live_frames (
  email TEXT PRIMARY KEY,
  seq INTEGER NOT NULL DEFAULT 0,
  captured_at DATETIME, file_name VARCHAR(300), size_bytes INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(20), app_name VARCHAR(200), window_title VARCHAR(500),
  host VARCHAR(200), category VARCHAR(80), host_name VARCHAR(255),
  agent_version VARCHAR(60), ip VARCHAR(60)
)",
"CREATE TABLE IF NOT EXISTS devices (
  device_id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL,
  device_type VARCHAR(20) NOT NULL DEFAULT 'PC',
  device_key VARCHAR(190) NOT NULL DEFAULT 'PC',
  os_name VARCHAR(190),
  os_version VARCHAR(190),
  manufacturer VARCHAR(190),
  model VARCHAR(190),
  app_version VARCHAR(60),
  host_name VARCHAR(255),
  last_seen DATETIME,
  created_at DATETIME NOT NULL
)",
"CREATE UNIQUE INDEX IF NOT EXISTS uq_dev_email_key ON devices(email, device_key)",
"CREATE INDEX IF NOT EXISTS ix_dev_email ON devices(email)",
"CREATE TABLE IF NOT EXISTS agent_tokens (
  token TEXT PRIMARY KEY, email VARCHAR(190) NOT NULL, device_id INTEGER, created_at DATETIME NOT NULL
)",
"CREATE TABLE IF NOT EXISTS activity_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190), full_name VARCHAR(200), role VARCHAR(20),
  action VARCHAR(60), details TEXT, source VARCHAR(30), created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS employee_hrm_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL UNIQUE, form_token VARCHAR(64) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  full_name TEXT, father_name TEXT, cnic TEXT, dob TEXT, gender TEXT, marital_status TEXT,
  contact_number TEXT, personal_email TEXT, current_address TEXT, permanent_address TEXT,
  father_cnic TEXT, father_contact TEXT, father_occupation TEXT,
  emergency_contact_name TEXT, emergency_contact_number TEXT, emergency_relationship TEXT,
  highest_degree TEXT, degree_program TEXT, institute TEXT, graduation_year TEXT,
  certifications TEXT, skills TEXT,
  employee_code TEXT, joining_date TEXT, designation TEXT, department TEXT,
  employment_type TEXT, reporting_manager TEXT, previous_company TEXT,
  previous_experience TEXT, portfolio_link TEXT, linkedin TEXT,
  salary TEXT, bank_name TEXT, account_title TEXT, account_number TEXT,
  bank_confirmed INTEGER NOT NULL DEFAULT 0, bank_confirm_date TEXT,
  probation_period TEXT, probation_end_date TEXT, leave_record TEXT,
  attendance_record TEXT, performance_review TEXT, hr_remarks TEXT,
  employment_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  hr_updated_by TEXT, hr_updated_at DATETIME,
  declaration_text TEXT, signature_type TEXT, signature_name TEXT, signature_png TEXT,
  confirmed_at DATETIME, confirmed_ip TEXT,
  submitted_at DATETIME, last_saved_at DATETIME, created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS employee_form_submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT, cnic TEXT, dob TEXT, gender TEXT, marital_status TEXT, current_address TEXT,
  position_title TEXT NOT NULL,
  department TEXT, employment_type TEXT, work_location TEXT,
  joining_date TEXT, salary_expected TEXT, salary_offered TEXT,
  highest_degree TEXT, institute TEXT, graduation_year TEXT,
  previous_company TEXT, total_experience TEXT,
  skills TEXT, previous_experience TEXT, linkedin TEXT, portfolio TEXT,
  emergency_contact_name TEXT, emergency_contact_number TEXT, notes TEXT,
  cv_file TEXT, cv_orig TEXT, cv_mime TEXT, cv_size INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'SUBMITTED',
  remarks TEXT,
  submitted_by TEXT, submitted_ip TEXT,
  status_updated_at DATETIME,
  created_at DATETIME NOT NULL, updated_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hrm_assets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, asset_name TEXT, asset_type TEXT, asset_serial TEXT,
  issued_date TEXT, condition_issued TEXT,
  acknowledged INTEGER NOT NULL DEFAULT 0, ack_date TEXT,
  return_date TEXT, condition_return TEXT, remarks TEXT
)",
"CREATE TABLE IF NOT EXISTS hrm_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, doc_key VARCHAR(60) NOT NULL,
  file_name VARCHAR(300) NOT NULL, orig_name VARCHAR(300),
  mime VARCHAR(120), size_bytes BIGINT NOT NULL DEFAULT 0,
  uploaded_at DATETIME NOT NULL
)",
/* HR module (additive): org lookups, employee profile, leave, attendance, notices. */
"CREATE TABLE IF NOT EXISTS hr_branches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL UNIQUE, city VARCHAR(120), address VARCHAR(300), phone VARCHAR(60),
  created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL UNIQUE, head_email VARCHAR(190), created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_teams (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL UNIQUE, department_id INTEGER, lead_email VARCHAR(190), created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_employee_profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL UNIQUE,
  employee_code VARCHAR(60), branch_id INTEGER, department_id INTEGER, team_id INTEGER,
  manager_email VARCHAR(190), employment_type VARCHAR(60),
  employment_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  date_of_birth VARCHAR(20), gender VARCHAR(20), marital_status VARCHAR(30),
  cnic VARCHAR(30), contract_end VARCHAR(20), created_at DATETIME, updated_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_leave_types (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(120) NOT NULL, code VARCHAR(40) NOT NULL UNIQUE,
  annual_quota REAL NOT NULL DEFAULT 0, paid INTEGER NOT NULL DEFAULT 1,
  active INTEGER NOT NULL DEFAULT 1, created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_leave_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, leave_type_id INTEGER NOT NULL,
  start_date VARCHAR(20) NOT NULL, end_date VARCHAR(20) NOT NULL,
  days REAL NOT NULL DEFAULT 0, reason TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING', applied_at DATETIME,
  manager_email VARCHAR(190), manager_action_at DATETIME, manager_note TEXT,
  hr_email VARCHAR(190), hr_action_at DATETIME, hr_note TEXT,
  cancelled_at DATETIME, created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hlr_email ON hr_leave_requests(email)",
"CREATE INDEX IF NOT EXISTS ix_hlr_status ON hr_leave_requests(status)",
"CREATE TABLE IF NOT EXISTS hr_leave_balances (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, leave_type_id INTEGER NOT NULL, `year` INTEGER NOT NULL,
  entitled_days REAL NOT NULL DEFAULT 0, used_days REAL NOT NULL DEFAULT 0, updated_at DATETIME,
  UNIQUE(email, leave_type_id, `year`)
)",
"CREATE TABLE IF NOT EXISTS hr_holidays (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  holiday_date VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL, branch_id INTEGER, created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_attendance_days (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PRESENT',
  check_in VARCHAR(30), check_out VARCHAR(30), worked_seconds INTEGER NOT NULL DEFAULT 0,
  late_minutes INTEGER NOT NULL DEFAULT 0, early_minutes INTEGER NOT NULL DEFAULT 0,
  overtime_minutes INTEGER NOT NULL DEFAULT 0, source VARCHAR(20) NOT NULL DEFAULT 'AGENT',
  note TEXT, updated_by VARCHAR(190), updated_at DATETIME, UNIQUE(email, work_date)
)",
"CREATE TABLE IF NOT EXISTS hr_attendance_corrections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, work_date VARCHAR(20) NOT NULL,
  requested_check_in VARCHAR(30), requested_check_out VARCHAR(30), reason TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  reviewed_by VARCHAR(190), reviewed_at DATETIME, review_note TEXT, created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hac_email ON hr_attendance_corrections(email)",
"CREATE INDEX IF NOT EXISTS ix_hac_status ON hr_attendance_corrections(status)",
"CREATE TABLE IF NOT EXISTS hr_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, type VARCHAR(40) NOT NULL, title VARCHAR(200) NOT NULL,
  body TEXT, link VARCHAR(300), actor_email VARCHAR(190), read_at DATETIME, created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hn_email ON hr_notifications(email, read_at)",
"CREATE TABLE IF NOT EXISTS hr_announcements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title VARCHAR(200) NOT NULL, body TEXT, audience VARCHAR(20) NOT NULL DEFAULT 'ALL',
  branch_id INTEGER, department_id INTEGER, created_by VARCHAR(190), created_at DATETIME
)",
/* HR module Phase 2 (additive): recruitment / ATS. */
"CREATE TABLE IF NOT EXISTS hr_job_openings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title VARCHAR(160) NOT NULL,
  department_id INTEGER, branch_id INTEGER,
  employment_type VARCHAR(60), work_location VARCHAR(160),
  vacancies INTEGER NOT NULL DEFAULT 1,
  salary_min VARCHAR(60), salary_max VARCHAR(60),
  description TEXT, requirements TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  posted_date VARCHAR(20), closing_date VARCHAR(20),
  hiring_manager VARCHAR(190),
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hjo_status ON hr_job_openings(status)",
"CREATE INDEX IF NOT EXISTS ix_hjo_dept ON hr_job_openings(department_id)",
"CREATE TABLE IF NOT EXISTS hr_application_openings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  submission_id INTEGER NOT NULL UNIQUE,
  opening_id INTEGER,
  source VARCHAR(60), recruiter_email VARCHAR(190),
  linked_by VARCHAR(190), linked_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_candidate_comms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  submission_id INTEGER NOT NULL,
  channel VARCHAR(30) NOT NULL DEFAULT 'NOTE',
  direction VARCHAR(10) NOT NULL DEFAULT 'OUT',
  summary VARCHAR(200), body TEXT,
  logged_by VARCHAR(190), created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hcc_sub ON hr_candidate_comms(submission_id)",
"CREATE TABLE IF NOT EXISTS hr_interviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  submission_id INTEGER NOT NULL, opening_id INTEGER,
  round_no INTEGER NOT NULL DEFAULT 1,
  stage VARCHAR(20) NOT NULL DEFAULT 'SCHEDULED',
  mode VARCHAR(20) NOT NULL DEFAULT 'ONSITE',
  scheduled_at VARCHAR(30), interviewer VARCHAR(190), location VARCHAR(300),
  notes TEXT, feedback TEXT, rating INTEGER NOT NULL DEFAULT 0,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hi_sub ON hr_interviews(submission_id)",
"CREATE INDEX IF NOT EXISTS ix_hi_stage ON hr_interviews(stage)",
"CREATE TABLE IF NOT EXISTS hr_offers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  submission_id INTEGER NOT NULL UNIQUE, opening_id INTEGER,
  position_title VARCHAR(160),
  offered_salary VARCHAR(60), joining_date VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT', notes TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
/* HR module Phase 2 (additive): onboarding. */
"CREATE TABLE IF NOT EXISTS hr_onboarding_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL,
  description TEXT,
  department_id INTEGER, branch_id INTEGER,
  employment_type VARCHAR(60),
  active INTEGER NOT NULL DEFAULT 1,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_onboarding_template_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  template_id INTEGER NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'GENERAL',
  owner_role VARCHAR(20) NOT NULL DEFAULT 'HR',
  due_offset_days INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hott_template ON hr_onboarding_template_tasks (template_id)",
"CREATE TABLE IF NOT EXISTS hr_onboarding_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL,
  full_name VARCHAR(200),
  template_id INTEGER,
  department_id INTEGER, branch_id INTEGER,
  designation VARCHAR(160), employment_type VARCHAR(60),
  joining_date VARCHAR(20), target_date VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'IN_PROGRESS',
  started_at DATETIME, completed_at DATETIME,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hop_email ON hr_onboarding_plans (email)",
"CREATE INDEX IF NOT EXISTS ix_hop_status ON hr_onboarding_plans (status)",
"CREATE TABLE IF NOT EXISTS hr_onboarding_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_id INTEGER NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'GENERAL',
  owner_role VARCHAR(20) NOT NULL DEFAULT 'HR',
  assignee_email VARCHAR(190),
  due_date VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  sort_order INTEGER NOT NULL DEFAULT 0,
  notes TEXT,
  completed_by VARCHAR(190), completed_at DATETIME,
  created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hotp_plan ON hr_onboarding_tasks (plan_id)",
/* HR module (additive): employee documents. */
"CREATE TABLE IF NOT EXISTS hr_document_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL, code VARCHAR(60) NOT NULL UNIQUE,
  sensitive INTEGER NOT NULL DEFAULT 0, expiry_tracked INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_employee_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL, category_id INTEGER,
  title VARCHAR(200) NOT NULL, file_name VARCHAR(300) NOT NULL,
  orig_name VARCHAR(300), mime VARCHAR(120), size_bytes INTEGER NOT NULL DEFAULT 0,
  issued_date VARCHAR(20), expiry_date VARCHAR(20), notes TEXT,
  uploaded_by VARCHAR(190), uploaded_at DATETIME, updated_at DATETIME, expiry_notified_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hed_email ON hr_employee_documents(email)",
"CREATE INDEX IF NOT EXISTS ix_hed_cat ON hr_employee_documents(category_id)",
"CREATE INDEX IF NOT EXISTS ix_hed_expiry ON hr_employee_documents(expiry_date)",
"CREATE TABLE IF NOT EXISTS hr_document_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  document_id INTEGER NOT NULL, action VARCHAR(30) NOT NULL,
  actor_email VARCHAR(190), note TEXT, snapshot VARCHAR(300), created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hedh_doc ON hr_document_history(document_id)",
/* HR module (additive): performance management. */
"CREATE TABLE IF NOT EXISTS hr_performance_cycles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL,
  period_start VARCHAR(20), period_end VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  description TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hpc_status ON hr_performance_cycles(status)",
"CREATE TABLE IF NOT EXISTS hr_performance_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER,
  email VARCHAR(190) NOT NULL,
  reviewer_email VARCHAR(190),
  review_type VARCHAR(20) NOT NULL DEFAULT 'MANAGER',
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  overall_rating INTEGER NOT NULL DEFAULT 0,
  self_rating INTEGER NOT NULL DEFAULT 0,
  strengths TEXT, improvements TEXT, comments TEXT, manager_comments TEXT,
  submitted_at DATETIME, acknowledged_at DATETIME,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hpr_email ON hr_performance_reviews(email)",
"CREATE INDEX IF NOT EXISTS ix_hpr_cycle ON hr_performance_reviews(cycle_id)",
"CREATE INDEX IF NOT EXISTS ix_hpr_status ON hr_performance_reviews(status)",
"CREATE TABLE IF NOT EXISTS hr_performance_goals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL,
  cycle_id INTEGER, review_id INTEGER,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  weight INTEGER NOT NULL DEFAULT 0,
  progress INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  due_date VARCHAR(20),
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hpg_email ON hr_performance_goals(email)",
"CREATE INDEX IF NOT EXISTS ix_hpg_cycle ON hr_performance_goals(cycle_id)",
/* HR module (additive): tasks & work. */
"CREATE TABLE IF NOT EXISTS hr_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  assignee_email VARCHAR(190), assigned_by VARCHAR(190),
  department_id INTEGER, branch_id INTEGER,
  priority VARCHAR(20) NOT NULL DEFAULT 'MEDIUM',
  status VARCHAR(20) NOT NULL DEFAULT 'TODO',
  progress INTEGER NOT NULL DEFAULT 0,
  start_date VARCHAR(20), due_date VARCHAR(20),
  completed_at DATETIME,
  created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_ht_assignee ON hr_tasks(assignee_email)",
"CREATE INDEX IF NOT EXISTS ix_ht_status ON hr_tasks(status)",
"CREATE INDEX IF NOT EXISTS ix_ht_due ON hr_tasks(due_date)",
"CREATE TABLE IF NOT EXISTS hr_task_comments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL,
  author_email VARCHAR(190),
  body TEXT,
  created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_htc_task ON hr_task_comments(task_id)",
/* HR module (additive): payroll. */
"CREATE TABLE IF NOT EXISTS hr_payroll_structures (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL,
  basic VARCHAR(40) NOT NULL DEFAULT '0',
  house_rent VARCHAR(40) NOT NULL DEFAULT '0',
  medical VARCHAR(40) NOT NULL DEFAULT '0',
  transport VARCHAR(40) NOT NULL DEFAULT '0',
  other_allowance VARCHAR(40) NOT NULL DEFAULT '0',
  tax_percent VARCHAR(40) NOT NULL DEFAULT '0',
  eobi VARCHAR(40) NOT NULL DEFAULT '0',
  lwp_day_rate VARCHAR(40) NOT NULL DEFAULT '0',
  active INTEGER NOT NULL DEFAULT 1,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_payroll_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  period VARCHAR(7) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  total_gross VARCHAR(40) NOT NULL DEFAULT '0',
  total_net VARCHAR(40) NOT NULL DEFAULT '0',
  headcount INTEGER NOT NULL DEFAULT 0,
  notes TEXT,
  processed_by VARCHAR(190), processed_at DATETIME,
  created_at DATETIME, updated_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_payslips (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id INTEGER NOT NULL,
  email VARCHAR(190) NOT NULL,
  basic VARCHAR(40) NOT NULL DEFAULT '0',
  allowances VARCHAR(40) NOT NULL DEFAULT '0',
  bonus VARCHAR(40) NOT NULL DEFAULT '0',
  commission VARCHAR(40) NOT NULL DEFAULT '0',
  deductions VARCHAR(40) NOT NULL DEFAULT '0',
  tax VARCHAR(40) NOT NULL DEFAULT '0',
  advance_deduction VARCHAR(40) NOT NULL DEFAULT '0',
  attendance_deduction VARCHAR(40) NOT NULL DEFAULT '0',
  net VARCHAR(40) NOT NULL DEFAULT '0',
  notes TEXT,
  created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hips_run ON hr_payslips(run_id)",
"CREATE INDEX IF NOT EXISTS ix_hips_email ON hr_payslips(email)",
/* Which salary structure each employee draws on when a run is generated. */
"CREATE TABLE IF NOT EXISTS hr_payroll_assignments (
  email VARCHAR(190) NOT NULL PRIMARY KEY,
  structure_id INTEGER NOT NULL,
  updated_by VARCHAR(190), updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hpa_struct ON hr_payroll_assignments(structure_id)",
/* One-off salary advances drawn against future payroll. */
"CREATE TABLE IF NOT EXISTS hr_payroll_advances (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL,
  amount VARCHAR(40) NOT NULL DEFAULT '0',
  reason VARCHAR(255),
  status VARCHAR(20) NOT NULL DEFAULT 'OUTSTANDING',
  recovered_run_id INTEGER,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hpa_adv_email ON hr_payroll_advances(email)",
"CREATE INDEX IF NOT EXISTS ix_hpa_adv_status ON hr_payroll_advances(status)",
/* HR module (additive): assets. */
"CREATE TABLE IF NOT EXISTS hr_asset_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL, code VARCHAR(60) NOT NULL UNIQUE,
  created_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_assets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  asset_tag VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  category_id INTEGER,
  serial_no VARCHAR(160), brand VARCHAR(120), model VARCHAR(160),
  purchase_date VARCHAR(20), purchase_cost VARCHAR(40),
  warranty_until VARCHAR(20),
  status VARCHAR(20) NOT NULL DEFAULT 'AVAILABLE',
  asset_condition VARCHAR(30) NOT NULL DEFAULT 'GOOD',
  notes TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_ha_status ON hr_assets(status)",
"CREATE INDEX IF NOT EXISTS ix_ha_cat ON hr_assets(category_id)",
"CREATE TABLE IF NOT EXISTS hr_asset_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  asset_id INTEGER NOT NULL,
  email VARCHAR(190) NOT NULL,
  assigned_by VARCHAR(190),
  assigned_at DATETIME, returned_at DATETIME,
  condition_out VARCHAR(30), condition_in VARCHAR(30),
  notes TEXT
)",
"CREATE INDEX IF NOT EXISTS ix_haa_asset ON hr_asset_assignments(asset_id)",
"CREATE INDEX IF NOT EXISTS ix_haa_email ON hr_asset_assignments(email)",
/* HR module (additive): offboarding. */
"CREATE TABLE IF NOT EXISTS hr_offboarding_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email VARCHAR(190) NOT NULL,
  full_name VARCHAR(200),
  offboard_type VARCHAR(20) NOT NULL DEFAULT 'RESIGNATION',
  resignation_date VARCHAR(20), last_working_date VARCHAR(20),
  reason TEXT, status VARCHAR(20) NOT NULL DEFAULT 'IN_PROGRESS',
  exit_interview TEXT, eligible_rehire INTEGER NOT NULL DEFAULT 0,
  clearance_notes TEXT,
  notice_period_days INTEGER NOT NULL DEFAULT 0, notice_end_date VARCHAR(20),
  source VARCHAR(20) NOT NULL DEFAULT 'HR',
  approved_by VARCHAR(190), approved_at DATETIME, decision_note TEXT,
  created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME, completed_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hoff_email ON hr_offboarding_plans(email)",
"CREATE INDEX IF NOT EXISTS ix_hoff_status ON hr_offboarding_plans(status)",
"CREATE TABLE IF NOT EXISTS hr_offboarding_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_id INTEGER NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'GENERAL',
  owner_role VARCHAR(20) NOT NULL DEFAULT 'HR',
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  sort_order INTEGER NOT NULL DEFAULT 0,
  notes TEXT,
  completed_by VARCHAR(190), completed_at DATETIME,
  created_at DATETIME, updated_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_hofft_plan ON hr_offboarding_tasks(plan_id)",
/* HR module (additive): settings. */
"CREATE TABLE IF NOT EXISTS hr_settings (
  skey VARCHAR(80) NOT NULL PRIMARY KEY,
  svalue TEXT,
  updated_by VARCHAR(190), updated_at DATETIME
)",
/* Manual update (SUPER_ADMIN): global + per-user expected-hours targets. */
"CREATE TABLE IF NOT EXISTS manual_settings (
  skey VARCHAR(80) NOT NULL PRIMARY KEY,
  svalue TEXT,
  updated_by VARCHAR(190), updated_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS manual_user_prefs (
  email VARCHAR(190) NOT NULL PRIMARY KEY,
  target_hours REAL,
  updated_by VARCHAR(190), updated_at DATETIME
)",
/* HR module (additive): approval workflows. */
"CREATE TABLE IF NOT EXISTS hr_approval_workflows (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(400),
  request_type VARCHAR(60) NOT NULL DEFAULT 'GENERAL',
  active INTEGER NOT NULL DEFAULT 1,
  created_by VARCHAR(190), updated_by VARCHAR(190), created_at DATETIME, updated_at DATETIME
)",
"CREATE TABLE IF NOT EXISTS hr_approval_workflow_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  workflow_id INTEGER NOT NULL,
  step_no INTEGER NOT NULL DEFAULT 1,
  approver_kind VARCHAR(20) NOT NULL DEFAULT 'ROLE',
  approver_value VARCHAR(190) NOT NULL DEFAULT 'HR',
  label VARCHAR(160),
  created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_haws_wf ON hr_approval_workflow_steps (workflow_id, step_no)",
"CREATE TABLE IF NOT EXISTS hr_approval_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  workflow_id INTEGER,
  request_type VARCHAR(60) NOT NULL DEFAULT 'GENERAL',
  title VARCHAR(200) NOT NULL,
  body TEXT,
  amount VARCHAR(60),
  requested_by VARCHAR(190) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  current_step INTEGER NOT NULL DEFAULT 1,
  total_steps INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME, updated_at DATETIME,
  decided_by VARCHAR(190), decided_at DATETIME, decision_note VARCHAR(400)
)",
"CREATE INDEX IF NOT EXISTS ix_har_req ON hr_approval_requests (requested_by)",
"CREATE INDEX IF NOT EXISTS ix_har_status ON hr_approval_requests (status)",
"CREATE TABLE IF NOT EXISTS hr_approval_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL,
  step_no INTEGER NOT NULL,
  approver_kind VARCHAR(20) NOT NULL DEFAULT 'ROLE',
  approver_value VARCHAR(190) NOT NULL DEFAULT 'HR',
  label VARCHAR(160),
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  decided_by VARCHAR(190), decided_at DATETIME, note VARCHAR(400),
  created_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_has_req ON hr_approval_steps (request_id, step_no)",
"CREATE TABLE IF NOT EXISTS remote_commands (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_email VARCHAR(190) NOT NULL,
  command TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'QUEUED',
  exitcode INTEGER, output TEXT,
  requested_by VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL,
  started_at DATETIME, finished_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_rc_target ON remote_commands (target_email, status)",
"CREATE TABLE IF NOT EXISTS remote_shells (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_email VARCHAR(190) NOT NULL,
  opened_by VARCHAR(190) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'OPEN',
  cwd VARCHAR(500),
  opened_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  closed_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_rs_target ON remote_shells (target_email, status)",
"CREATE TABLE IF NOT EXISTS remote_shell_in (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  shell_id INTEGER NOT NULL,
  command TEXT NOT NULL,
  delivered INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
)",
"CREATE INDEX IF NOT EXISTS ix_rsi_shell ON remote_shell_in (shell_id, delivered)",
"CREATE TABLE IF NOT EXISTS remote_shell_out (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  shell_id INTEGER NOT NULL,
  data TEXT,
  created_at DATETIME NOT NULL
)",
"CREATE INDEX IF NOT EXISTS ix_rso_shell ON remote_shell_out (shell_id, id)",
"CREATE TABLE IF NOT EXISTS remote_fs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  target_email VARCHAR(190) NOT NULL,
  opened_by VARCHAR(190) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'OPEN',
  cwd VARCHAR(1000),
  opened_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  closed_at DATETIME
)",
"CREATE INDEX IF NOT EXISTS ix_rf_target ON remote_fs (target_email, status)",
"CREATE TABLE IF NOT EXISTS remote_fs_in (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fs_id INTEGER NOT NULL,
  command TEXT NOT NULL,
  delivered INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
)",
"CREATE INDEX IF NOT EXISTS ix_rfi_fs ON remote_fs_in (fs_id, delivered)",
"CREATE TABLE IF NOT EXISTS remote_fs_out (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fs_id INTEGER NOT NULL,
  data TEXT,
  created_at DATETIME NOT NULL
)",
"CREATE INDEX IF NOT EXISTS ix_rfo_fs ON remote_fs_out (fs_id, id)",
"CREATE INDEX IF NOT EXISTS idx_au_email_date ON app_usages(email, work_date)",
"CREATE INDEX IF NOT EXISTS idx_shot_email ON screenshots(email, captured_at)",
"CREATE INDEX IF NOT EXISTS idx_ef_status ON employee_form_submissions(status)",
"CREATE INDEX IF NOT EXISTS idx_ef_created ON employee_form_submissions(created_at)",
    ];
}

/** Creates every table if missing. Runs once on the first request. */
function ensure_schema(): void {
    global $WF;
    static $done = false;
    if ($done) return;
    try {
        foreach (schema_sql() as $ddl) wf_pdo()->exec($ddl);
        migrate_schema();

        // screenshots root must exist and be writable
        $shotsRoot = rtrim($WF['storage'], '/\\') . '/screenshots';
        if (!is_dir($shotsRoot)) @mkdir($shotsRoot, 0775, true);

        seed_defaults();
        $done = true;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Database setup failed.\n";
        echo ($e->getMessage()) . "\n";
        echo "Check the credentials in config (config.local.php) or the WF_DB_* settings.\n";
        exit;
    }
}

/**
 * Brings an already-created table up to the current schema.
 *
 * CREATE TABLE IF NOT EXISTS is a no-op on a table that is already there, so a
 * column added to the DDL above never reaches an existing install. Each step is
 * guarded by a column check first, which makes this safe to run on every
 * request and safe to interrupt.
 */
function migrate_schema(): void {
    global $WF;
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = wf_pdo();
    $isSqlite = wf_is_sqlite($pdo);

    // paused_seconds: time the laptop was folded / locked while the employee was
    // on duty. It is deliberately NOT part of worked time.
    wf_add_column($pdo, 'track_records', 'paused_seconds',
        $isSqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'BIGINT NOT NULL DEFAULT 0');

    // totals_locked: set once the agent has reported its own shift buckets for
    // this day. From that moment the agent's monotonic numbers are the truth and
    // the server stops adding wall-clock time on top of them. Without it the two
    // sources were both written and whichever was larger won, which is what made
    // worked time read high for anyone whose laptop was folded and read low for
    // anyone who clocked in twice.
    wf_add_column($pdo, 'track_records', 'totals_locked',
        $isSqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0');

    // allow_supervisor_talk: may a supervisor push live audio to this employee's
    // speakers. Defaults on, because a supervisor talking to a monitored person is
    // ordinary support work; the owner is refused separately at the endpoint, since
    // the owner is never a monitored target.
    wf_add_column($pdo, 'agent_configs', 'allow_supervisor_talk',
        $isSqlite ? 'INTEGER NOT NULL DEFAULT 1' : 'TINYINT(1) NOT NULL DEFAULT 1');

    // track_* flags: added alongside the three policy columns above. An existing
    // install would otherwise never receive them, and the agent would read them
    // as missing and stop reporting the very numbers this app is built on.
    foreach ([
        'capture_screenshots'   => 'INTEGER NOT NULL DEFAULT 1',
        'track_mouse_keyboard' => 'INTEGER NOT NULL DEFAULT 1',
        'track_app_usage'      => 'INTEGER NOT NULL DEFAULT 1',
        'track_websites'       => 'INTEGER NOT NULL DEFAULT 1',
    ] as $col => $type) {
        wf_add_column($pdo, 'agent_configs', $col, $isSqlite ? $type : 'TINYINT(1) NOT NULL DEFAULT 1');
    }

    // live_stream_interval_ms: the live stream moved from whole seconds to
    // milliseconds so it can run at 120ms per frame (~8 fps). The one-time
    // backfill runs only on the request that actually adds the column: a policy
    // that was enabled keeps its rate converted to ms, and one that was off
    // turns on at the 120ms default - streaming is on by default now, which is
    // what the monitor's "live off" badge was reporting all along.
    $hadMs = wf_has_column($pdo, 'agent_configs', 'live_stream_interval_ms');
    wf_add_column($pdo, 'agent_configs', 'live_stream_interval_ms',
        $isSqlite ? 'INTEGER NOT NULL DEFAULT 120' : 'INT NOT NULL DEFAULT 120');
    if (!$hadMs) {
        try {
            $pdo->exec('UPDATE agent_configs SET live_stream_interval_ms = CASE'
                . ' WHEN live_stream_interval_seconds > 0 THEN live_stream_interval_seconds * 1000'
                . ' ELSE 120 END');
            // A policy that was off keeps its old 0 in the seconds column while
            // the new ms value says 120. Leave that pair and every derived
            // reader disagrees with the stored row: the mirror is corrected so
            // the two columns always tell the same story.
            $pdo->exec('UPDATE agent_configs SET live_stream_interval_seconds = 1'
                . ' WHERE live_stream_interval_ms > 0 AND live_stream_interval_seconds = 0');
        } catch (Throwable $e) {
            error_log('folio: live_stream_interval_ms backfill failed: ' . $e->getMessage());
        }
    }

    // employees.email must be unique: two rows sharing one address is the only
    // way one person's password can answer for another's account. Tables built
    // by the current DDL already declare it; this closes the gap for a table
    // created by the older Java schema. If duplicates already exist the CREATE
    // would fail on every request, so they are counted first and reported once.
    static $dupeNoticed = false;
    if (!wf_has_index($pdo, 'employees', 'uq_emp_email')) {
        try {
            $dupes = $pdo->query('SELECT email FROM employees GROUP BY email HAVING COUNT(*) > 1 LIMIT 5')
                ->fetchAll(PDO::FETCH_COLUMN);
            if ($dupes !== []) {
                if (!$dupeNoticed) {
                    $dupeNoticed = true;
                    error_log('folio: employees holds duplicate emails (' . implode(', ', $dupes)
                        . '). Merge each pair into one account - until then the unique index cannot be added.');
                }
            } else {
                $pdo->exec('CREATE UNIQUE INDEX uq_emp_email ON employees(email)');
            }
        } catch (Throwable $e) {
            error_log('folio: unique email index failed: ' . $e->getMessage());
        }
    }

    // Payroll gains: bonus & commission (one-off earnings on a payslip),
    // advance_deduction (salary advance recovery) and attendance_deduction
    // (leave-without-pay daily deduction), plus a per-structure LWP day rate
    // and the advances register. Each is a no-op when already present.
    foreach (['bonus', 'commission', 'advance_deduction', 'attendance_deduction'] as $col) {
        wf_add_column($pdo, 'hr_payslips', $col, $isSqlite ? "VARCHAR(40) NOT NULL DEFAULT '0'" : "VARCHAR(40) NOT NULL DEFAULT '0'");
    }
    wf_add_column($pdo, 'hr_payroll_structures', 'lwp_day_rate',
        $isSqlite ? "VARCHAR(40) NOT NULL DEFAULT '0'" : "VARCHAR(40) NOT NULL DEFAULT '0'");

    if (!wf_has_table($pdo, 'hr_payroll_advances')) {
        try {
            $pdo->exec('CREATE TABLE hr_payroll_advances ('
                . ($isSqlite
                    ? 'id INTEGER PRIMARY KEY AUTOINCREMENT'
                    : 'id BIGINT AUTO_INCREMENT PRIMARY KEY')
                . ', email VARCHAR(190) NOT NULL, amount VARCHAR(40) NOT NULL DEFAULT \'0\','
                . ' reason VARCHAR(255), status VARCHAR(20) NOT NULL DEFAULT \'OUTSTANDING\','
                . ' recovered_run_id ' . ($isSqlite ? 'INTEGER' : 'BIGINT') . ','
                . ' created_by VARCHAR(190), created_at DATETIME, updated_at DATETIME)');
            if (!$isSqlite) {
                $pdo->exec('CREATE INDEX ix_hpa_adv_email ON hr_payroll_advances(email)');
                $pdo->exec('CREATE INDEX ix_hpa_adv_status ON hr_payroll_advances(status)');
            }
        } catch (Throwable $e) {
            error_log('folio: hr_payroll_advances creation failed: ' . $e->getMessage());
        }
    }

    // Offboarding gains: employee-submitted resignations awaiting HR approval,
    // plus the notice period that drives the computed last working day.
    wf_add_column($pdo, 'hr_offboarding_plans', 'notice_period_days',
        $isSqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT NOT NULL DEFAULT 0');
    wf_add_column($pdo, 'hr_offboarding_plans', 'notice_end_date',
        $isSqlite ? 'VARCHAR(20)' : 'VARCHAR(20)');
    wf_add_column($pdo, 'hr_offboarding_plans', 'source',
        $isSqlite ? "VARCHAR(20) NOT NULL DEFAULT 'HR'" : "VARCHAR(20) NOT NULL DEFAULT 'HR'");
    wf_add_column($pdo, 'hr_offboarding_plans', 'approved_by',
        $isSqlite ? 'VARCHAR(190)' : 'VARCHAR(190)');
    wf_add_column($pdo, 'hr_offboarding_plans', 'approved_at',
        $isSqlite ? 'DATETIME' : 'DATETIME');
    wf_add_column($pdo, 'hr_offboarding_plans', 'decision_note',
        $isSqlite ? 'TEXT' : 'TEXT');

    // Recruitment gains: where a candidate came from, the recruiter who owns
    // them, and a running log of communications (calls, emails, notes).
    wf_add_column($pdo, 'hr_application_openings', 'source',
        $isSqlite ? 'VARCHAR(60)' : 'VARCHAR(60)');
    wf_add_column($pdo, 'hr_application_openings', 'recruiter_email',
        $isSqlite ? 'VARCHAR(190)' : 'VARCHAR(190)');
    if (!wf_has_table($pdo, 'hr_candidate_comms')) {
        try {
            $pdo->exec('CREATE TABLE hr_candidate_comms ('
                . ($isSqlite
                    ? 'id INTEGER PRIMARY KEY AUTOINCREMENT'
                    : 'id BIGINT AUTO_INCREMENT PRIMARY KEY')
                . ', submission_id ' . ($isSqlite ? 'INTEGER' : 'BIGINT') . ' NOT NULL,'
                . ' channel VARCHAR(30) NOT NULL DEFAULT \'NOTE\','
                . ' direction VARCHAR(10) NOT NULL DEFAULT \'OUT\','
                . ' summary VARCHAR(200), body TEXT,'
                . ' logged_by VARCHAR(190), created_at DATETIME)');
            if ($isSqlite) {
                $pdo->exec('CREATE INDEX ix_hcc_sub ON hr_candidate_comms(submission_id)');
            } else {
                $pdo->exec('CREATE INDEX ix_hcc_sub ON hr_candidate_comms(submission_id)');
            }
        } catch (Throwable $e) {
            error_log('folio: hr_candidate_comms creation failed: ' . $e->getMessage());
        }
    }

    // manual_locked: the owner typed this day's numbers by hand on the Manual
    // Update screen. From that moment the agent is not allowed to touch the row
    // at all - not its shift totals, not its wall-clock settle - because every
    // writer that still ran would quietly undo the edit (totals only grow, so a
    // lower typed value always lost to the next heartbeat).
    wf_add_column($pdo, 'track_records', 'manual_locked',
        $isSqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0');

    // Device-aware columns
    wf_add_column($pdo, 'agent_tokens', 'device_id',
        $isSqlite ? 'INTEGER' : 'BIGINT NULL');
    wf_add_column($pdo, 'remote_commands', 'target_device_id',
        $isSqlite ? 'INTEGER' : 'BIGINT NULL');
    wf_add_column($pdo, 'remote_shells', 'target_device_id',
        $isSqlite ? 'INTEGER' : 'BIGINT NULL');
    wf_add_column($pdo, 'remote_fs', 'target_device_id',
        $isSqlite ? 'INTEGER' : 'BIGINT NULL');

    // Configurable approval workflows: admin-defined chains plus the live
    // requests and per-step decisions they generate.
    wf_create_approval_tables($pdo, $isSqlite);

    // Manual update: the company-wide and per-employee expected-hours targets
    // the owner sets by hand.
    wf_create_manual_tables($pdo, $isSqlite);

    // Backfill device_id for existing agent_tokens
    try {
        $rows = rows('SELECT DISTINCT email FROM agent_tokens WHERE device_id IS NULL');
        foreach ($rows as $r) {
            $email = (string) ($r['email'] ?? '');
            if ($email === '') continue;
            $dev = one('SELECT device_id FROM devices WHERE email=? AND device_key=? LIMIT 1', [$email, 'PC']);
            if ($dev === null) {
                $now = now_sql();
                q('INSERT INTO devices (email, device_type, device_key, created_at) VALUES (?,?,?,?)',
                    [$email, 'PC', 'PC', $now]);
                $devId = last_id();
            } else {
                $devId = (int) $dev['device_id'];
            }
            q('UPDATE agent_tokens SET device_id=? WHERE email=? AND device_id IS NULL', [$devId, $email]);
        }
    } catch (Throwable $e) {
        error_log('folio: device backfill failed: ' . $e->getMessage());
    }
}

/** Create the Manual Update tables on an existing install if missing. */
function wf_create_manual_tables(PDO $pdo, bool $isSqlite): void {
    $defs = [
        'manual_settings' => 'CREATE TABLE manual_settings ('
            . 'skey VARCHAR(80) NOT NULL PRIMARY KEY, svalue TEXT,'
            . ' updated_by VARCHAR(190), updated_at DATETIME)',
        'manual_user_prefs' => 'CREATE TABLE manual_user_prefs ('
            . 'email VARCHAR(190) NOT NULL PRIMARY KEY, target_hours DOUBLE NULL,'
            . ' updated_by VARCHAR(190), updated_at DATETIME)',
    ];
    foreach ($defs as $table => $ddl) {
        if (wf_has_table($pdo, $table)) continue;
        try {
            $pdo->exec($ddl);
        } catch (Throwable $e) {
            error_log('folio: ' . $table . ' creation failed: ' . $e->getMessage());
        }
    }
}

/** Create the four approval-workflow tables on an existing install if missing. */
function wf_create_approval_tables(PDO $pdo, bool $isSqlite): void {
    $pk = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
    $defs = [
        'hr_approval_workflows' => 'CREATE TABLE hr_approval_workflows ('
            . 'id ' . $pk . ', name VARCHAR(160) NOT NULL, description VARCHAR(400),'
            . ' request_type VARCHAR(60) NOT NULL DEFAULT \'GENERAL\','
            . ' active ' . ($isSqlite ? 'INTEGER' : 'TINYINT(1)') . ' NOT NULL DEFAULT 1,'
            . ' created_by VARCHAR(190), updated_by VARCHAR(190), created_at DATETIME, updated_at DATETIME)',
        'hr_approval_workflow_steps' => 'CREATE TABLE hr_approval_workflow_steps ('
            . 'id ' . $pk . ', workflow_id ' . ($isSqlite ? 'INTEGER' : 'BIGINT') . ' NOT NULL,'
            . ' step_no INT NOT NULL DEFAULT 1,'
            . ' approver_kind VARCHAR(20) NOT NULL DEFAULT \'ROLE\','
            . ' approver_value VARCHAR(190) NOT NULL DEFAULT \'HR\','
            . ' label VARCHAR(160), created_at DATETIME)',
        'hr_approval_requests' => 'CREATE TABLE hr_approval_requests ('
            . 'id ' . $pk . ', workflow_id ' . ($isSqlite ? 'INTEGER' : 'BIGINT') . ','
            . ' request_type VARCHAR(60) NOT NULL DEFAULT \'GENERAL\','
            . ' title VARCHAR(200) NOT NULL, body TEXT, amount VARCHAR(60),'
            . ' requested_by VARCHAR(190) NOT NULL,'
            . ' status VARCHAR(20) NOT NULL DEFAULT \'PENDING\','
            . ' current_step INT NOT NULL DEFAULT 1, total_steps INT NOT NULL DEFAULT 0,'
            . ' created_at DATETIME, updated_at DATETIME,'
            . ' decided_by VARCHAR(190), decided_at DATETIME, decision_note VARCHAR(400))',
        'hr_approval_steps' => 'CREATE TABLE hr_approval_steps ('
            . 'id ' . $pk . ', request_id ' . ($isSqlite ? 'INTEGER' : 'BIGINT') . ' NOT NULL,'
            . ' step_no INT NOT NULL,'
            . ' approver_kind VARCHAR(20) NOT NULL DEFAULT \'ROLE\','
            . ' approver_value VARCHAR(190) NOT NULL DEFAULT \'HR\','
            . ' label VARCHAR(160), status VARCHAR(20) NOT NULL DEFAULT \'PENDING\','
            . ' decided_by VARCHAR(190), decided_at DATETIME, note VARCHAR(400),'
            . ' created_at DATETIME)',
    ];
    foreach ($defs as $table => $ddl) {
        if (wf_has_table($pdo, $table)) continue;
        try { $pdo->exec($ddl); }
        catch (Throwable $e) { error_log('folio: ' . $table . ' creation failed: ' . $e->getMessage()); }
    }
    foreach ([['ix_haws_wf', 'hr_approval_workflow_steps', 'workflow_id, step_no'],
              ['ix_har_req', 'hr_approval_requests', 'requested_by'],
              ['ix_har_status', 'hr_approval_requests', 'status'],
              ['ix_has_req', 'hr_approval_steps', 'request_id, step_no']] as $ix) {
        if (wf_has_index($pdo, $ix[1], $ix[0])) continue;
        try { $pdo->exec('CREATE INDEX ' . $ix[0] . ' ON ' . $ix[1] . ' (' . $ix[2] . ')'); }
        catch (Throwable $e) { /* index is a nicety; ignore */ }
    }
}

function wf_is_sqlite(PDO $pdo): bool {
    $driver = (string) ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? '');
    return stripos($driver, 'sqlite') !== false;
}

/** Does the table already have the column? Safe on every driver, every request. */
function wf_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $cols = [];
        if (wf_is_sqlite($pdo)) {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $row) $cols[] = $row['name'];
        } else {
            foreach ($pdo->query('SHOW COLUMNS FROM ' . $table) as $row) $cols[] = $row['Field'];
        }
        return in_array($column, $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}

/** Does the table already have the named index? Answered, not assumed. */
function wf_has_index(PDO $pdo, string $table, string $index): bool {
    try {
        if (wf_is_sqlite($pdo)) {
            foreach ($pdo->query('PRAGMA index_list(' . $table . ')') as $row) {
                if (strcasecmp((string) ($row['name'] ?? ''), $index) === 0) return true;
            }
            return false;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $st->execute([$table, $index]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Does the table exist at all? Useful before creating a new table on an
    existing install where the DDL's CREATE TABLE IF NOT EXISTS is a no-op. */
function wf_has_table(PDO $pdo, string $table): bool {
    try {
        if (wf_is_sqlite($pdo)) {
            $st = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
            return (int) $st->fetchColumn() > 0;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$table]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Adds one column if the table does not already have it.
 *
 * CREATE TABLE IF NOT EXISTS is a no-op on a table that is already there, so a
 * column added to the DDL above never reaches an existing install. This is the
 * one place that gap is closed, and every call is guarded by a column check
 * first - which makes it safe to run on every request and safe to interrupt. A
 * failure is logged and swallowed: a missing reporting column must not take the
 * whole site down.
 */
function wf_add_column(PDO $pdo, string $table, string $column, string $type): void {
    try {
        if (wf_has_column($pdo, $table, $column)) return;
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
    } catch (Throwable $e) {
        error_log('folio: ' . $table . '.' . $column . ' migration failed: ' . $e->getMessage());
    }
}

/**
 * Guarantees exactly one way in: the ADMIN account.
 *
 * This used to seed four demo logins (admin/hr/two employees) whenever the table
 * was empty, which is a real hazard on a production install - delete the last
 * employee through the UI and the next request quietly hands the admin password
 * to anyone who loads the login page. Only the ADMIN is restored, and only when
 * the table is genuinely empty.
 */
function seed_defaults(): void {
    $count = val('SELECT COUNT(*) FROM employees');
    if ((int) $count > 0) return;
    global $WF;
    $seed = [[
        'first' => 'Admin', 'last' => 'Root', 'email' => 'admin@workfolio.com',
        'pass' => $WF['admin_password'] !== '' ? $WF['admin_password'] : 'admin123',
        'role' => 'ADMIN', 'dept' => 'Administration', 'desig' => 'System Administrator',
        'phone' => '9876543210', 'salary' => 120000.0, 'join' => '2020-01-15', 'addr' => 'New Delhi, India',
    ]];
    foreach ($seed as $s) {
        q('INSERT INTO employees (first_name,last_name,email,`role`,department,designation,phone,salary,join_date,address,password)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $s['first'], $s['last'], $s['email'], $s['role'], $s['dept'], $s['desig'],
            $s['phone'], $s['salary'], $s['join'], $s['addr'], password_hash($s['pass'], PASSWORD_BCRYPT),
        ]);
    }
    seed_config_for_all();
}

/** Like DataSeeder.seedAgentConfigs: every employee gets a policy row. */
function seed_config_for_all(): void {
    foreach (rows('SELECT email FROM employees') as $e) {
        config_for($e['email']);
    }
}

/** Default monitoring policy (matches the Java AgentConfig defaults). */
function default_config(): array {
    return [
        'email' => '',
        'screenshot_interval_seconds' => 300,
        'live_stream_interval_seconds' => 1,
        'live_stream_interval_ms' => 120,
        'idle_threshold_seconds' => 180,
        'image_quality' => 55,
        'image_max_width' => 1280,
        'sample_interval_seconds' => 10,
        'break_allowance_minutes' => 30,
        'capture_screenshots' => 1,
        'track_mouse_keyboard' => 1,
        'track_app_usage' => 1,
        'track_websites' => 1,
        'allow_supervisor_talk' => 1,
        'excluded_processes' => 'explorer.exe,dwm.exe,ShellExperienceHost,StartMenuExperienceHost,SearchHost,TextInputHost,LockApp.exe,sihost.exe,RuntimeBroker,SystemSettings.exe',
        'blocked_sites' => '',
    ];
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);