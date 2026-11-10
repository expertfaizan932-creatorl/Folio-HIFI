<?php
/**
 * Remote file manager end-to-end test over real HTTP.
 *
 * Exercises the full relay: the owner's console opens a browse session on a
 * chosen PC, queues JSON ops (drives, list, mkdir, write, read, ...), the agent
 * polls them, executes fake filesystem calls, and posts results back that the
 * console reads. Also asserts the role gates (HR/employee can neither drive nor
 * be an admin target), the per-session ownership on the agent side, and that
 * closing the session erases everything it passed through.
 *
 *   php php-app/test-remote-files.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-rf-e2e-' . getmypid();
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

/* People: seeded ADMIN, an EMPLOYEE with the agent, an HR account that must be
   refused as a driver. The first request boots the schema. */
req('/', null, $jarWeb);
make_employee('bob@workfolio.com', 'EMPLOYEE', 'bob123');
make_employee('hr@workfolio.com', 'HR', 'hr123');

/* ------------------------------------------------------------- owner login -- */
$r = login_as('admin@workfolio.com', 'admin123', $jarWeb);
ok('owner signs in', $r['code'] === 200 && stripos($r['body'], 'dashboard') !== false);

/* Manager API sign-in, mirroring the console's in-memory cookie handling. */
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
if (preg_match('/^Set-Cookie:\s*([^=\r\n]+)=([^;\r\n]+)/mi', $headMgr, $m)) {
    file_put_contents($jarMgr, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t0\t{$m[1]}\t{$m[2]}\n");
}

/* --------------------------------------------------------- open a session -- */
$open = api_manager_post('/api/manager/fs/open', ['_csrf' => $csrfMgr, 'target' => 'bob@workfolio.com'], $jarMgr);
ok('the console opens a browse session', is_array($open) && ($open['ok'] ?? false) && (int) ($open['fs'] ?? 0) > 0);
$fsId = (int) ($open['fs'] ?? 0);
ok('the session names the target', ($open['target'] ?? '') === 'bob@workfolio.com');

$again = api_manager_post('/api/manager/fs/open', ['_csrf' => $csrfMgr, 'target' => 'bob@workfolio.com'], $jarMgr);
ok('re-opening re-attaches to the same session', ($again['ok'] ?? false) && (int) ($again['fs'] ?? 0) === $fsId
    && ($again['resumed'] ?? false) === true);

/* An ADMIN account is never a valid target. */
$bad = api_manager_post('/api/manager/fs/open', ['_csrf' => $csrfMgr, 'target' => 'admin@workfolio.com'], $jarMgr);
ok('an ADMIN account is not a valid target', ($bad['ok'] ?? true) === false);

/* -------------------------------------------------------------- agent login -- */
$login = api_agent_post('/api/agent/login', ['email' => 'bob@workfolio.com', 'password' => 'bob123']);
ok('the employee signs the agent in', is_array($login) && ($login['ok'] ?? false));
$token = is_array($login) ? ($login['token'] ?? null) : null;

/* ------------------------------------------------------- drives op relay -- */
$drivesOp = json_encode(['rid' => 1, 'op' => 'drives']);
$w = api_manager_post('/api/manager/fs/write', ['_csrf' => $csrfMgr, 'fs' => $fsId, 'command' => $drivesOp], $jarMgr);
ok('the console queues a drives op', is_array($w) && ($w['ok'] ?? false));

$poll = api_agent_post('/api/agent/fs/poll', ['token' => $token]);
ok('the agent receives the session', is_array($poll) && ($poll['ok'] ?? false) && (int) ($poll['fs']['id'] ?? 0) === $fsId);
ok('the op is relayed verbatim', ($poll['fs']['inputs'][0] ?? '') === $drivesOp);

$poll2 = api_agent_post('/api/agent/fs/poll', ['token' => $token]);
ok('a second poll gets nothing', is_array($poll2) && ($poll2['ok'] ?? false)
    && (($poll2['fs']['inputs'] ?? []) === []));

$reply = json_encode(['rid' => 1, 'ok' => true, 'drives' => [['name' => 'C:\\', 'total' => 100, 'free' => 40]]]);
$out = api_agent_post('/api/agent/fs/out', ['token' => $token, 'fs' => $fsId, 'data' => $reply, 'cwd' => 'C:\\Users']);
ok('the agent posts a reply', is_array($out) && ($out['ok'] ?? false));

$rd = api_manager_post('/api/manager/fs/read', ['_csrf' => $csrfMgr, 'fs' => $fsId, 'cursor' => 0], $jarMgr);
ok('the console reads the reply', is_array($rd) && ($rd['ok'] ?? false) && count($rd['results'] ?? []) === 1);
$got = json_decode((string) ($rd['results'][0] ?? ''), true);
ok('the reply echoes the request id', (int) ($got['rid'] ?? 0) === 1 && ($got['ok'] ?? false) === true);
ok('the drive list survived the round trip', ($got['drives'][0]['name'] ?? '') === 'C:\\');
ok('the read advances the cursor', (int) ($rd['cursor'] ?? 0) > 0);

$rd2 = api_manager_post('/api/manager/fs/read', ['_csrf' => $csrfMgr, 'fs' => $fsId, 'cursor' => (int) $rd['cursor']], $jarMgr);
ok('a follow-up read returns nothing new', is_array($rd2) && ($rd2['ok'] ?? false) && count($rd2['results'] ?? []) === 0);

/* The incremented cursor now lives on the session row. */
$row = db()->query('SELECT * FROM remote_fs WHERE id=' . $fsId)->fetch();
ok('the agent-reported cwd was stored', $row !== null && $row['cwd'] === 'C:\\Users');

/* ----------------------------------------------------- a mutating op audits -- */
$mk = json_encode(['rid' => 2, 'op' => 'mkdir', 'path' => 'C:\\temp\\folio-new']);
$w = api_manager_post('/api/manager/fs/write', ['_csrf' => $csrfMgr, 'fs' => $fsId, 'command' => $mk], $jarMgr);
ok('the console queues a mkdir op', is_array($w) && ($w['ok'] ?? false));
$audit = db()->query("SELECT COUNT(*) FROM activity_log WHERE action='REMOTE_FS_MKDIR'")->fetchColumn();
ok('a mutating op is written to the audit trail', (int) $audit === 1);

/* ---------------------------------------------------------- malformed ops -- */
$w = api_manager_post('/api/manager/fs/write', ['_csrf' => $csrfMgr, 'fs' => $fsId, 'command' => 'not json'], $jarMgr);
ok('a malformed op is refused', ($w['ok'] ?? true) === false);
$w = api_manager_post('/api/manager/fs/write', ['_csrf' => $csrfMgr, 'fs' => $fsId, 'command' => ''], $jarMgr);
ok('an empty op is refused', ($w['ok'] ?? true) === false);

/* ---------------------------------------------- agent ownership / security -- */
$other = api_agent_post('/api/agent/fs/out', ['token' => $token, 'fs' => 999999, 'data' => '{}']);
ok('an unknown session id is rejected', ($other['ok'] ?? true) === false);

/* A different agent (HR) cannot post to bob's session. */
$hrLogin = api_agent_post('/api/agent/login', ['email' => 'hr@workfolio.com', 'password' => 'hr123']);
$hrToken = is_array($hrLogin) ? ($hrLogin['token'] ?? null) : null;
$intrude = api_agent_post('/api/agent/fs/out', ['token' => $hrToken, 'fs' => $fsId, 'data' => '{}']);
ok('another agent cannot write into this session', ($intrude['ok'] ?? true) === false);

/* ---------------------------------------------- manager-side role gates -- */
$hrSys = api_manager_post('/api/manager/fs/open', ['_csrf' => $csrfMgr, 'target' => 'hr@workfolio.com'], $jarMgr);
ok('HR is a valid target', ($hrSys['ok'] ?? false) && (int) ($hrSys['fs'] ?? 0) > 0);
$hrFs = (int) ($hrSys['fs'] ?? 0);
api_manager_post('/api/manager/fs/close', ['_csrf' => $csrfMgr, 'fs' => $hrFs], $jarMgr);

login_as('hr@workfolio.com', 'hr123', $jarHr);
$hrOpen = api_manager_post('/api/manager/fs/open', ['_csrf' => 'x', 'target' => 'bob@workfolio.com'], $jarHr);
ok('HR cannot open a browse session', ($hrOpen['ok'] ?? true) === false);
$anon = api_manager_post('/api/manager/fs/open', ['target' => 'bob@workfolio.com'], null);
ok('anonymous is refused by the manager API', ($anon['ok'] ?? true) === false);

/* --------------------------------------------------------------- close -- */
$cl = api_manager_post('/api/manager/fs/close', ['_csrf' => $csrfMgr, 'fs' => $fsId], $jarMgr);
ok('the console closes the session', is_array($cl) && ($cl['ok'] ?? false));
ok('the session and its queues are erased on close',
    (int) db()->query('SELECT COUNT(*) FROM remote_fs')->fetchColumn() === 0
    && (int) db()->query('SELECT COUNT(*) FROM remote_fs_in')->fetchColumn() === 0
    && (int) db()->query('SELECT COUNT(*) FROM remote_fs_out')->fetchColumn() === 0);

/* After the close the agent is told there is nothing to do. */
$poll = api_agent_post('/api/agent/fs/poll', ['token' => $token]);
ok('the agent sees no session after close', is_array($poll) && ($poll['ok'] ?? false) && ($poll['fs'] ?? null) === null);

echo "\n" . ($fail === 0 ? "OK  $pass passed" : "FAILED  $fail of " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
