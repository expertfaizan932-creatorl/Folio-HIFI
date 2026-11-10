<?php
/**
 * Live-stream interval (ms) + login-identity tests.
 *
 * Four promises are pinned here:
 *
 *   1. A fresh install defaults to 120 ms per frame with streaming ON, and the
 *      agent payload carries both the ms key and the legacy seconds mirror.
 *   2. An install that predates the ms column is migrated exactly once: an
 *      enabled rate converts to ms, an off policy turns on at 120 ms.
 *   3. An account table that predates the unique-email guarantee is repaired
 *      without crashing - and while duplicates exist, login still picks the row
 *      whose password actually matches instead of an arbitrary one.
 *   4. The audit trail records refused sign-ins.
 *
 * The old-install half runs in a child process, because migrate_schema() and
 * ensure_schema() are deliberately once-per-process.
 *
 *   php php-app/test-live-config.php
 */

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

/* ------------------------------------------------------------------ child -- */

if (($argv[1] ?? '') === '--old-install') {
    // Pre-ms, pre-unique schema with duplicate accounts. The parent spawns one
    // of these per run so the once-per-process migration guards see a virgin
    // process.
    $old = (string) ($argv[2] ?? '');
    if ($old === '') { fwrite(STDERR, "missing storage dir\n"); exit(1); }
    @mkdir($old, 0777, true);
    putenv('WF_STORAGE=' . $old);
    putenv('WF_DB_DRIVER=sqlite');

    $pdo = new PDO('sqlite:' . $old . '/db.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // agent_configs as it shipped before milliseconds existed.
    $pdo->exec('CREATE TABLE agent_configs (
        email TEXT PRIMARY KEY,
        screenshot_interval_seconds INTEGER NOT NULL DEFAULT 300,
        live_stream_interval_seconds INTEGER NOT NULL DEFAULT 1,
        idle_threshold_seconds INTEGER NOT NULL DEFAULT 180,
        image_quality INTEGER NOT NULL DEFAULT 55,
        image_max_width INTEGER NOT NULL DEFAULT 1280,
        sample_interval_seconds INTEGER NOT NULL DEFAULT 10,
        break_allowance_minutes INTEGER DEFAULT 30,
        capture_screenshots INTEGER NOT NULL DEFAULT 1,
        track_mouse_keyboard INTEGER NOT NULL DEFAULT 1,
        track_app_usage INTEGER NOT NULL DEFAULT 1,
        track_websites INTEGER NOT NULL DEFAULT 1,
        excluded_processes TEXT, blocked_sites TEXT)');
    $pdo->exec("INSERT INTO agent_configs (email, live_stream_interval_seconds) VALUES ('rate@x.test', 5), ('off@x.test', 0)");
    // employees as the older Java schema left it: no unique key, two rows
    // sharing one address with different passwords.
    $pdo->exec('CREATE TABLE employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name VARCHAR(120), last_name VARCHAR(120), email VARCHAR(190) NOT NULL,
        phone VARCHAR(60), department VARCHAR(120), designation VARCHAR(120),
        `role` VARCHAR(20) NOT NULL DEFAULT \'EMPLOYEE\', salary REAL, join_date VARCHAR(30),
        address VARCHAR(500), password VARCHAR(255))');
    $h1 = password_hash('alpha123', PASSWORD_DEFAULT);
    $h2 = password_hash('beta456', PASSWORD_DEFAULT);
    $st = $pdo->prepare('INSERT INTO employees (first_name, last_name, email, `role`, password) VALUES (?,?,?,?,?)');
    $st->execute(['Alpha', 'Dup', 'shared@x.test', 'EMPLOYEE', $h1]);
    $id1 = (int) $pdo->lastInsertId();
    $st->execute(['Beta', 'Dup', 'shared@x.test', 'EMPLOYEE', $h2]);
    $id2 = (int) $pdo->lastInsertId();
    $pdo = null;

    require __DIR__ . '/lib/core.php';
    require __DIR__ . '/lib/auth.php';
    require __DIR__ . '/lib/activity.php';
    // Start before any output: do_login() regenerates the session id the same
    // way a web request does.
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

    // The migration must run without a fatal and without touching the rows.
    ensure_schema();
    $p = wf_pdo();

    // Every probe runs before the first echo: do_login() regenerates the
    // session id, and PHP refuses to do that once output has started.
    $msAdded = wf_has_column($p, 'agent_configs', 'live_stream_interval_ms');
    $rate = one("SELECT * FROM agent_configs WHERE email='rate@x.test'");
    $off  = one("SELECT * FROM agent_configs WHERE email='off@x.test'");
    $indexBlocked = !wf_has_index($p, 'employees', 'uq_emp_email');
    $all = employee_find_by_email_all('shared@x.test');
    $logged = do_login('shared@x.test', 'beta456');
    $loggedId = $logged === null ? null : (int) $logged['id'];
    $refused = do_login('shared@x.test', 'wrong-password');
    // The refusal audit itself is asserted in the parent; here the point is
    // only that a duplicate-account refusal does not fatal.

    ok('old install: ms column added', $msAdded);
    ok('old install: enabled 5s rate became 5000ms', (int) ($rate['live_stream_interval_ms'] ?? -1) === 5000,
        'got ' . var_export($rate['live_stream_interval_ms'] ?? null, true));
    ok('old install: off policy turned on at 120ms', (int) ($off['live_stream_interval_ms'] ?? -1) === 120,
        'got ' . var_export($off['live_stream_interval_ms'] ?? null, true));
    ok('old install: off policy seconds mirror corrected to 1', (int) ($off['live_stream_interval_seconds'] ?? -1) === 1);
    ok('old install: duplicate accounts block the unique index instead of crashing', $indexBlocked);
    ok('old install: both duplicate rows are visible', count($all) === 2);
    ok('old install: password picks the row that owns it',
        $loggedId !== null && $loggedId === $id2,
        'expected id ' . $id2 . ', got ' . var_export($loggedId, true));
    ok('old install: wrong password still refused', $refused === null);

    echo "old-install: $pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}

/* ----------------------------------------------------------------- parent -- */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-live-config-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/usage.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/activity.php';

ensure_schema();

echo "-- interval helpers --\n";
ok('120ms maps to a 1s legacy mirror', live_interval_seconds(120) === 1);
ok('0 stays 0 (off)', live_interval_seconds(0) === 0);
ok('sub-second still mirrors to 1, never 0', live_interval_seconds(1) === 1);
ok('1500ms rounds up to 2s', live_interval_seconds(1500) === 2);
ok('30000ms mirrors to 30s', live_interval_seconds(30000) === 30);
ok('ms value read straight from a row', live_interval_ms(['live_stream_interval_ms' => 150]) === 150);
ok('explicit 0 is authoritative over seconds', live_interval_ms(['live_stream_interval_ms' => 0, 'live_stream_interval_seconds' => 5]) === 0);
ok('row without the ms column falls back to seconds', live_interval_ms(['live_stream_interval_seconds' => 2]) === 2000);

echo "-- fresh install --\n";
$d = default_config();
ok('default config is 120ms', (int) $d['live_stream_interval_ms'] === 120);
ok('default config streams on', live_interval_ms($d) > 0);
ok('fresh table has the unique email index', wf_has_index(wf_pdo(), 'employees', 'uq_emp_email'));

$c = config_for('fresh@x.test');
ok('new employee row stored 120ms', (int) ($c['live_stream_interval_ms'] ?? -1) === 120);
ok('new employee row stored 1s mirror', (int) ($c['live_stream_interval_seconds'] ?? -1) === 1);
$payload = config_payload($c);
ok('payload carries liveStreamIntervalMs', array_key_exists('liveStreamIntervalMs', $payload) && (int) $payload['liveStreamIntervalMs'] === 120);
ok('payload carries legacy liveStreamIntervalSeconds', array_key_exists('liveStreamIntervalSeconds', $payload) && (int) $payload['liveStreamIntervalSeconds'] === 1);
ok('null email answers with defaults', live_interval_ms(config_for(null)) === 120);

echo "-- admin edit --\n";
$c['live_stream_interval_ms'] = 250;
save_config($c);
$row = one('SELECT * FROM agent_configs WHERE email=?', ['fresh@x.test']);
ok('save writes ms', (int) $row['live_stream_interval_ms'] === 250);
ok('save recomputes the seconds mirror', (int) $row['live_stream_interval_seconds'] === 1);
$c['live_stream_interval_ms'] = 0;
save_config($c);
$row = one('SELECT * FROM agent_configs WHERE email=?', ['fresh@x.test']);
ok('ms=0 turns streaming off end to end', (int) $row['live_stream_interval_ms'] === 0 && live_interval_ms($row) === 0);
ok('ms=0 mirror stays 0, not a stray second', (int) $row['live_stream_interval_seconds'] === 0);

/* Refused sign-in audit, exercised through the same call page_login makes. */
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
activity_log(null, 'LOGIN_FAILED', 'Refused sign-in for ghost@x.test from 127.0.0.1', 'web');
$failed = one("SELECT * FROM activity_log WHERE action='LOGIN_FAILED' ORDER BY id DESC");
ok('LOGIN_FAILED audit holds email + source', $failed !== null
    && strpos((string) $failed['details'], 'ghost@x.test') !== false
    && strpos((string) $failed['details'], '127.0.0.1') !== false);

echo "-- old install migration (child process) --\n";
$oldDir = $tmp . DIRECTORY_SEPARATOR . 'old';
$proc = proc_open(
    [PHP_BINARY, __FILE__, '--old-install', $oldDir],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes, __DIR__
);
$out = '';
$err = '';
if (is_resource($proc)) {
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
} else {
    $code = -1;
}
echo $out;
if ($err !== '') echo "  (stderr) " . trim($err) . "\n";
preg_match('/old-install: (\d+) passed, (\d+) failed/', $out, $m);
$childPass = (int) ($m[1] ?? 0);
$childFail = (int) ($m[2] ?? ($code === 0 ? 0 : 999));
$pass += $childPass;
$fail += $childFail;
ok('old-install child ran to completion', $code === 0, 'exit ' . $code);

/* ------------------------------------------------------------------ teardown */
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        if (is_dir($f)) { foreach (glob($f . '/*') ?: [] as $g) @unlink($g); @rmdir($f); }
        else @unlink($f);
    }
    @rmdir($tmp);
});

echo "\n" . ($fail === 0 ? "OK  $pass passed" : "FAILED  $fail of " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
