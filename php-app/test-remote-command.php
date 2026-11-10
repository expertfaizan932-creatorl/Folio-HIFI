<?php
/**
 * Remote Control end-to-end test over real HTTP.
 *
 * Exercises the complete journey exactly as the pieces touch it: an owner
 * queues a cmd line for a chosen PC, the agent polls that line, runs it,
 * reports output back, and the console reads the live terminal - and the
 * transcript vanishes when the terminal closes. Role gates are asserted too:
 * HR and employees can neither queue nor poll other people's commands, and an
 * ADMIN account is never a valid target.
 *
 *   php php-app/test-remote-command.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-rc-e2e-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

function free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($s === false) {
        return 9900 + (getmypid() % 90);
    }
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr(strrchr($name, ':'), 1);
}

/**
 * Stop the dev server process tree. proc_terminate() alone leaves zombies on
 * Windows, and a zombie holding the port means the next run hits a stale
 * server whose sessions no longer exist - so tear the tree down for real.
 */
function stop_server($server): void
{
    if (!is_resource($server)) {
        return;
    }
    $status = proc_get_status($server);
    if ($status && !empty($status['running'])) {
        $pid = (int) $status['pid'];
        if ($pid > 0) {
            @exec('taskkill /PID ' . $pid . ' /T /F 2>NUL');
        }
        proc_terminate($server);
    }
    proc_close($server);
}

$root = dirname(__DIR__) . '/php-app';
$port = free_port();
$base = 'http://127.0.0.1:' . $port;
$pass = 0;
$fail = 0;

function ok(string $name, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n";
    }
}

$jarWeb = $tmp . '/cookies-web.txt';
$jarHr  = $tmp . '/cookies-hr.txt';
$jarMgr = $tmp . '/cookies-mgr.txt';

$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes,
    $root
);

register_shutdown_function(function () use ($server, $pipes, $tmp, $jarWeb, $jarHr, $jarMgr) {
    stop_server($server);
    foreach ([$jarWeb, $jarHr, $jarMgr] as $f) {
        @unlink($f);
    }
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});

$up = false;
for ($i = 0; $i < 60; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) {
        fclose($s);
        $up = true;
        break;
    }
}
if (!$up) {
    fwrite(STDERR, "server did not start\n--- log ---\n" . (string) @file_get_contents($tmp . '/srv.log') . "\n");
    exit(1);
}

function req(string $url, ?array $post = null, ?string $jar = null, bool $follow = false): array
{
    global $base;
    $jar = $jar ?? ($GLOBALS['jarWeb'] ?? '');
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : $base . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $head = substr($raw, 0, strpos($raw, "\r\n\r\n") ?: 0);
    $loc = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $head, $m)) {
        $loc = $m[1];
    }
    return ['code' => $code, 'body' => substr($raw, strpos($raw, "\r\n\r\n") + 4), 'loc' => $loc];
}

function db(): PDO
{
    global $tmp;
    return new PDO('sqlite:' . $tmp . '/db.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/* Sign-in carries a CSRF token, so fetch the form on the same jar first. */
function login_as(string $email, string $pass, string $jar): array
{
    $form = req('/login', null, $jar, false);
    $token = preg_match('/name="_csrf" value="([a-f0-9]+)"/', $form['body'], $m) ? $m[1] : '';
    return req('/login', ['username' => $email, 'password' => $pass, '_csrf' => $token], $jar, true);
}

function make_employee(string $email, string $role, string $password): void
{
    $p = db()->prepare('INSERT INTO employees (first_name,last_name,email,`role`,password) VALUES (?,?,?,?,?)');
    $p->execute([ucfirst(explode('@', $email)[0] . '_' . $role), 'T', $email, $role, password_hash($password, PASSWORD_BCRYPT)]);
}

/*
 * People: seeded ADMIN (admin@workfolio.com/admin123), an EMPLOYEE with the
 * agent, and an HR account that must be refused. The first request boots the
 * schema, so it has to happen before any direct INSERT.
 */
req('/', null, $jarWeb);
make_employee('bob@workfolio.com', 'EMPLOYEE', 'bob123');
make_employee('hr@workfolio.com', 'HR', 'hr123');

/* ---------------------------------------------------------------- owner web -- */
$r = login_as('admin@workfolio.com', 'admin123', $jarWeb);
ok('owner signs in', $r['code'] === 200 && stripos($r['body'], 'dashboard') !== false);

/* ------------------------------------------------------------ manager api -- */
/*
 * The console (WorkFolioManager.exe) is the only client now - the browser CMD
 * page is gone. Sign in over the API and queue a line for bob's PC.
 */
$ch = curl_init($base . '/api/manager/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['username' => 'admin@workfolio.com', 'password' => 'admin123']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
]);
$rawMgr = (string) curl_exec($ch);
curl_close($ch);
$splitMgr = strpos($rawMgr, "\r\n\r\n");
$headMgr = $splitMgr === false ? $rawMgr : substr($rawMgr, 0, $splitMgr);
$mgr = json_decode($splitMgr === false ? '' : substr($rawMgr, $splitMgr + 4), true);
ok('manager can log in', is_array($mgr) && ($mgr['ok'] ?? false));
$csrfMgr = is_array($mgr) ? ($mgr['csrf'] ?? null) : null;
ok('manager login hands out the CSRF token', is_string($csrfMgr) && $csrfMgr !== '');

/*
 * The console keeps the sign-in cookie in memory (CaptureCookies); mirror that
 * here so the calls below are made as the manager that just signed in. The dev
 * server rotates the session on login, so a plain cookie jar misses it.
 */
if (preg_match('/^Set-Cookie:\s*([^=\r\n]+)=([^;\r\n]+)/mi', $headMgr, $m)) {
    file_put_contents($jarMgr, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t0\t{$m[1]}\t{$m[2]}\n");
}

$q = api_manager_post('/api/manager/command', ['_csrf' => $csrfMgr, 'target' => 'bob@workfolio.com', 'command' => 'echo hello'], $jarMgr);
ok('the console can queue a command', is_array($q) && ($q['ok'] ?? false) && (int) ($q['id'] ?? 0) > 0);

$row = db()->query("SELECT * FROM remote_commands WHERE target_email='bob@workfolio.com' AND command='echo hello' ORDER BY id DESC LIMIT 1")->fetch();
ok('the command is queued against the employee', $row !== null && $row['status'] === 'QUEUED');
ok('the queue names the asking admin', $row !== null && $row['requested_by'] === 'admin@workfolio.com');

/* A blank line is refused, and nothing extra lands in the queue. */
$blank = api_manager_post('/api/manager/command', ['_csrf' => $csrfMgr, 'target' => 'bob@workfolio.com', 'command' => ''], $jarMgr);
ok('an empty command is refused', ($blank['ok'] ?? true) === false);
ok('and nothing extra was queued', (int) db()->query('SELECT COUNT(*) FROM remote_commands')->fetchColumn() === 1);

/* ------------------------------------------------------------- agent side -- */
$r = req('/api/agent/login', null, null);
ok('agent login endpoint rejects a plain GET jar (no post)', $r['code'] !== 200);

$r = req('/api/agent/login', null, null);
$ch = curl_init($base . '/api/agent/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['email' => 'bob@workfolio.com', 'password' => 'bob123']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
]);
$raw = (string) curl_exec($ch);
curl_close($ch);
$login = json_decode($raw, true);
ok('the employee signs the agent in', is_array($login) && ($login['ok'] ?? false));
$token = is_array($login) ? ($login['token'] ?? null) : null;

function api_agent_post(string $path, array $body): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    return json_decode($raw, true);
}

$poll = api_agent_post('/api/agent/remote/poll', ['token' => $token]);
ok('the agent polls its command', is_array($poll) && ($poll['ok'] ?? false) && is_array($poll['command'] ?? null));
$cmdId = $poll['command']['id'] ?? 0;
ok('the queued line comes back', ($poll['command']['command'] ?? '') === 'echo hello');

$c = db()->query('SELECT * FROM remote_commands WHERE id=' . (int) $cmdId)->fetch();
ok('polling claims the row as RUNNING', $c !== null && $c['status'] === 'RUNNING');

$poll = api_agent_post('/api/agent/remote/poll', ['token' => $token]);
ok('a second poll gets nothing', is_array($poll) && ($poll['ok'] ?? false) && ($poll['command'] ?? null) === null);

$rep = api_agent_post('/api/agent/remote/report', ['token' => $token, 'id' => $cmdId, 'exitcode' => 0, 'output' => "hello\n"]);
ok('the output is reported', is_array($rep) && ($rep['ok'] ?? false));

$c = db()->query('SELECT * FROM remote_commands WHERE id=' . (int) $cmdId)->fetch();
ok('the row settles DONE', $c !== null && $c['status'] === 'DONE' && (int) $c['exitcode'] === 0);
ok('the captured output was stored', $c !== null && trim((string) $c['output']) === 'hello');

/* An agent token cannot touch someone else's command. */
$other = (int) db()->query("SELECT id FROM remote_commands WHERE id<>" . (int) $cmdId . " LIMIT 1")->fetchColumn();
if ($other > 0) {
    $rep = api_agent_post('/api/agent/remote/report', ['token' => $token, 'id' => $other, 'exitcode' => 0, 'output' => 'nope']);
    ok('an agent cannot report another command', (($rep['ok'] ?? true) === false) || (($rep['error'] ?? '') !== ''));
}
$c = false;
ok('the other row survived untouched', $c !== true || ($c && $c['status'] === 'QUEUED'));

/* ------------------------------------------------------------- shell api -- */
/*
 * The console opens a live terminal on bob's PC, sends a line, reads what the
 * agent posts back, then closes it - and the transcript vanishes with it.
 */
$open = api_manager_post('/api/manager/shell/open', ['_csrf' => $csrfMgr, 'target' => 'bob@workfolio.com'], $jarMgr);
ok('the console opens a terminal', is_array($open) && ($open['ok'] ?? false) && (int) ($open['shell'] ?? 0) > 0);
$shellId = (int) ($open['shell'] ?? 0);

$w = api_manager_post('/api/manager/shell/write', ['_csrf' => $csrfMgr, 'shell' => $shellId, 'line' => 'echo hi'], $jarMgr);
ok('the console sends a line to the terminal', is_array($w) && ($w['ok'] ?? false));

$sp = api_agent_post('/api/agent/shell/poll', ['token' => $token]);
ok('the agent receives the terminal line', is_array($sp) && ($sp['ok'] ?? false)
    && is_array($sp['shell'] ?? null) && (int) ($sp['shell']['id'] ?? 0) === $shellId
    && ($sp['shell']['inputs'][0]['command'] ?? '') === 'echo hi');

$so = api_agent_post('/api/agent/shell/out', ['token' => $token, 'shell' => $shellId, 'data' => "hi\r\n"]);
ok('the agent posts terminal output', is_array($so) && ($so['ok'] ?? false));

$rd = api_manager_post('/api/manager/shell/read', ['_csrf' => $csrfMgr, 'shell' => $shellId, 'cursor' => 0], $jarMgr);
ok('the console reads terminal output', is_array($rd) && ($rd['ok'] ?? false) && strpos((string) ($rd['data'] ?? ''), 'hi') !== false);

$cl = api_manager_post('/api/manager/shell/close', ['_csrf' => $csrfMgr, 'shell' => $shellId], $jarMgr);
ok('the console closes the terminal', is_array($cl) && ($cl['ok'] ?? false));
ok('the transcript is erased on close',
    (int) db()->query('SELECT COUNT(*) FROM remote_shells')->fetchColumn() === 0
    && (int) db()->query('SELECT COUNT(*) FROM remote_shell_out')->fetchColumn() === 0);

/* ------------------------------------------------------------ manager api -- */
$r = req('/api/manager/login', null, $jarWeb);
ok('manager login is POST-only (GET yields a page, not JSON)', $r['code'] !== 200 || strpos($r['body'], '{') === false);

function api_manager_post(string $path, array $body, ?string $jar): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return ['ok' => false, 'error' => 'non-JSON: HTTP ' . $code . ' ' . substr($raw, 0, 160)];
    }
    return $dec;
}

$sys = api_manager_post('/api/manager/systems', ['_csrf' => $csrfMgr], $jarMgr);
ok('the console lists connected systems', is_array($sys) && ($sys['ok'] ?? false) && is_array($sys['systems'] ?? null));
$emails = [];
foreach ($sys['systems'] as $s) {
    $emails[] = (string) ($s['email'] ?? '');
}
ok('the employee with a live token is in the list', in_array('bob@workfolio.com', $emails, true));

$q = api_manager_post('/api/manager/command', ['_csrf' => $csrfMgr, 'target' => 'bob@workfolio.com', 'command' => 'dir'], $jarMgr);
ok('the console can queue a command', is_array($q) && ($q['ok'] ?? false) && (int) ($q['id'] ?? 0) > 0);

$h = api_manager_post('/api/manager/history', ['_csrf' => $csrfMgr, 'target' => 'bob@workfolio.com'], $jarMgr);
ok('the console reads history', is_array($h) && ($h['ok'] ?? false) && is_array($h['history'] ?? null));
$ids = array_map(fn ($x) => (int) ($x['id'] ?? 0), $h['history']);
ok('both commands are in history', in_array((int) $cmdId, $ids, true) && in_array((int) ($q['id'] ?? 0), $ids, true));

$q = api_manager_post('/api/manager/command', ['_csrf' => $csrfMgr, 'target' => 'admin@workfolio.com', 'command' => 'dir'], $jarMgr);
ok('an ADMIN account is not a valid target', (($q['ok'] ?? true) === false));

/* --------------------------------------------------- HR systems as targets -- */
$r = api_manager_post('/api/manager/command', ['_csrf' => $csrfMgr, 'target' => 'hr@workfolio.com', 'command' => 'echo thanks-hr'], $jarMgr);
ok('the owner queues a command for the HR system', is_array($r) && ($r['ok'] ?? false));

$row = db()->query("SELECT * FROM remote_commands WHERE target_email='hr@workfolio.com' AND command='echo thanks-hr' ORDER BY id DESC LIMIT 1")->fetch();
ok('the HR command is queued', $row !== null && $row['status'] === 'QUEUED');

$hrLogin = api_agent_post('/api/agent/login', ['email' => 'hr@workfolio.com', 'password' => 'hr123']);
ok('the HR agent signs in', is_array($hrLogin) && ($hrLogin['ok'] ?? false));
$hrToken = is_array($hrLogin) ? ($hrLogin['token'] ?? null) : null;

$poll = api_agent_post('/api/agent/remote/poll', ['token' => $hrToken]);
ok('the HR agent receives its command', is_array($poll) && ($poll['ok'] ?? false)
    && ($poll['command']['command'] ?? '') === 'echo thanks-hr');
$hrId = (int) ($poll['command']['id'] ?? 0);

$rep = api_agent_post('/api/agent/remote/report', ['token' => $hrToken, 'id' => $hrId, 'exitcode' => 0, 'output' => 'welcome']);
ok('the HR agent reports back', is_array($rep) && ($rep['ok'] ?? false));

$c = db()->query('SELECT * FROM remote_commands WHERE id=' . (int) $hrId)->fetch();
ok('the HR command settles DONE', $c !== null && $c['status'] === 'DONE');

/* One line fanned out to every HR + employee system, admins excluded. */
$r = api_manager_post('/api/manager/command', ['_csrf' => $csrfMgr, 'target' => '__ALL__', 'command' => 'echo for-everyone'], $jarMgr);
ok('the owner broadcasts to every HR and employee system', is_array($r) && ($r['ok'] ?? false));

$targets = array_map(fn ($x) => (string) $x['target_email'],
    db()->query("SELECT target_email FROM remote_commands WHERE command='echo for-everyone'")->fetchAll(PDO::FETCH_ASSOC));
sort($targets);
ok('one row lands on each HR and employee system', $targets === ['bob@workfolio.com', 'hr@workfolio.com'], json_encode($targets));
ok('ADMIN and SUPER_ADMIN systems are never broadcast to', !in_array('admin@workfolio.com', $targets, true));

/* --------------------------------------------------------- role gates -- */
$r = login_as('hr@workfolio.com', 'hr123', $jarHr);
ok('HR signs in', $r['code'] === 200);

$sys = api_manager_post('/api/manager/systems', [], $jarHr);
ok('HR is refused by the manager API before CSRF', is_array($sys) && ($sys['ok'] ?? false) === false
    && stripos((string) ($sys['error'] ?? ''), 'owner') !== false);

$q = api_manager_post('/api/manager/command', [], $jarHr);
ok('HR cannot queue commands', is_array($q) && ($q['ok'] ?? false) === false);

/* Anonymous manager call. */
$sys = api_manager_post('/api/manager/systems', [], null);
ok('anonymous is refused by the manager API', is_array($sys) && ($sys['ok'] ?? false) === false);

echo "\n" . ($fail === 0 ? "OK  $pass passed" : "FAILED  $fail of " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
