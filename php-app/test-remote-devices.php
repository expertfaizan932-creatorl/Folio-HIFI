<?php
/**
 * Remote Control device-aware end-to-end test over real HTTP.
 *
 * Exercises device registration on agent login, multi-device visibility,
 * device-targeted queuing, device isolation for remote/shell/fs, fleet
 * selectors, legacy email fallback, and invalid device targeting.
 *
 *   php php-app/test-remote-devices.php
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-rc-dev-' . getmypid();
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
$jarMgr = $tmp . '/cookies-mgr.txt';

$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes,
    $root
);

register_shutdown_function(function () use ($server, $pipes, $tmp, $jarWeb, $jarMgr) {
    stop_server($server);
    foreach ([$jarWeb, $jarMgr] as $f) {
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
    $sep = strpos($raw, "\r\n\r\n");
    if ($sep === false) {
        return ['code' => $code, 'body' => $raw, 'loc' => ''];
    }
    $head = substr($raw, 0, $sep);
    $body = substr($raw, $sep + 4);
    $loc = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $head, $m)) {
        $loc = $m[1];
    }
    return ['code' => $code, 'body' => $body, 'loc' => $loc];
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
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : ['ok' => false, 'error' => substr($raw, 0, 80)];
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

/* Seed */
req('/', null, $jarWeb);
make_employee('bob@workfolio.com', 'EMPLOYEE', 'bob123');

/* Manager sign-in */
$r = login_as('admin@workfolio.com', 'admin123', $jarWeb);
ok('owner signs in', $r['code'] === 200 && stripos($r['body'], 'dashboard') !== false);

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
ok('manager login hands out CSRF token', is_string($csrfMgr) && $csrfMgr !== '');
if (preg_match('/^Set-Cookie:\s*([^=\r\n]+)=([^;\r\n]+)/mi', $headMgr, $m)) {
    file_put_contents($jarMgr, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t0\t{$m[1]}\t{$m[2]}\n");
}

/* 1. Agent logs in with Android device details */
$androidLogin = api_agent_post('/api/agent/login', [
    'email' => 'bob@workfolio.com',
    'password' => 'bob123',
    'deviceType' => 'ANDROID',
    'deviceKey' => 'android-1',
    'osName' => 'Android',
    'osVersion' => '14',
    'manufacturer' => 'Samsung',
    'model' => 'SM-G991B',
    'appVersion' => '1.0',
    'hostName' => 'Pixel',
]);
ok('android agent login returns ok', is_array($androidLogin) && ($androidLogin['ok'] ?? false));
$androidDevId = isset($androidLogin['deviceId']) ? (int) $androidLogin['deviceId'] : 0;
ok('android agent gets deviceId', $androidDevId > 0);
$androidToken = is_array($androidLogin) ? ($androidLogin['token'] ?? null) : null;
ok('android agent gets token', is_string($androidToken) && $androidToken !== '');

/* 2. Same employee signs in PC agent with different device key/host */
$pcLogin = api_agent_post('/api/agent/login', [
    'email' => 'bob@workfolio.com',
    'password' => 'bob123',
    'deviceType' => 'PC',
    'deviceKey' => 'pc-1',
    'hostName' => 'OFFICE-PC',
]);
ok('pc agent login returns ok', is_array($pcLogin) && ($pcLogin['ok'] ?? false));
$pcDevId = isset($pcLogin['deviceId']) ? (int) $pcLogin['deviceId'] : 0;
ok('pc agent gets deviceId', $pcDevId > 0);
$pcToken = is_array($pcLogin) ? ($pcLogin['token'] ?? null) : null;
ok('pc agent gets token', is_string($pcToken) && $pcToken !== '');
ok('devices have different ids', $androidDevId !== $pcDevId);

/* 3. Manager systems shows one row per device for employee */
$sys = api_manager_post('/api/manager/systems', ['_csrf' => $csrfMgr], $jarMgr);
ok('manager systems returns ok', is_array($sys) && ($sys['ok'] ?? false) && is_array($sys['systems'] ?? null));
$bobDevices = [];
foreach ($sys['systems'] as $s) {
    if (strcasecmp((string) ($s['email'] ?? ''), 'bob@workfolio.com') === 0) {
        $bobDevices[] = $s;
    }
}
ok('two device rows for bob', count($bobDevices) === 2);
$hasAndroid = false;
$hasPc = false;
foreach ($bobDevices as $s) {
    $dt = strtoupper((string) ($s['device_type'] ?? ''));
    if ($dt === 'ANDROID') {
        $hasAndroid = true;
        ok('android row has model', isset($s['model']) && (string) $s['model'] === 'SM-G991B');
        ok('android row has device_id', isset($s['device_id']) && (int) $s['device_id'] === $androidDevId);
    }
    if ($dt === 'PC') {
        $hasPc = true;
        ok('pc row has device_id', isset($s['device_id']) && (int) $s['device_id'] === $pcDevId);
    }
}
ok('has android and pc rows', $hasAndroid && $hasPc);

/* 4. Queue command targeted at android device */
$qAndroid = api_manager_post('/api/manager/command', [
    '_csrf' => $csrfMgr,
    'target' => 'dev:' . $androidDevId,
    'command' => 'echo android-only',
], $jarMgr);
ok('queue android-targeted command ok', is_array($qAndroid) && ($qAndroid['ok'] ?? false));

$pollAndroid = api_agent_post('/api/agent/remote/poll', ['token' => $androidToken]);
ok('android agent receives targeted command', is_array($pollAndroid) && ($pollAndroid['ok'] ?? false) && is_array($pollAndroid['command'] ?? null));
$cmdAndroid = (string) ($pollAndroid['command']['command'] ?? '');
ok('android gets echo android-only', $cmdAndroid === 'echo android-only');

$pollPc = api_agent_post('/api/agent/remote/poll', ['token' => $pcToken]);
ok('pc agent gets null for android-targeted', is_array($pollPc) && ($pollPc['ok'] ?? false) && ($pollPc['command'] ?? null) === null);

/* Report android to clear queue */
$cidAndroid = (int) ($pollAndroid['command']['id'] ?? 0);
if ($cidAndroid > 0) {
    api_agent_post('/api/agent/remote/report', ['token' => $androidToken, 'id' => $cidAndroid, 'exitcode' => 0, 'output' => 'ok']);
}

/* 5. Queue command targeted at pc device */
$qPc = api_manager_post('/api/manager/command', [
    '_csrf' => $csrfMgr,
    'target' => 'dev:' . $pcDevId,
    'command' => 'echo pc-only',
], $jarMgr);
ok('queue pc-targeted command ok', is_array($qPc) && ($qPc['ok'] ?? false));

$pollPc2 = api_agent_post('/api/agent/remote/poll', ['token' => $pcToken]);
ok('pc agent receives targeted command', is_array($pollPc2) && ($pollPc2['ok'] ?? false) && is_array($pollPc2['command'] ?? null));
$cmdPc = (string) ($pollPc2['command']['command'] ?? '');
ok('pc gets echo pc-only', $cmdPc === 'echo pc-only');

$pollAndroid2 = api_agent_post('/api/agent/remote/poll', ['token' => $androidToken]);
ok('android agent gets null for pc-targeted', is_array($pollAndroid2) && ($pollAndroid2['ok'] ?? false) && ($pollAndroid2['command'] ?? null) === null);

$cidPc = (int) ($pollPc2['command']['id'] ?? 0);
if ($cidPc > 0) {
    api_agent_post('/api/agent/remote/report', ['token' => $pcToken, 'id' => $cidPc, 'exitcode' => 0, 'output' => 'ok']);
}

/* 6. Broadcast __ALL_ANDROID__ */
$qAllAndroid = api_manager_post('/api/manager/command', [
    '_csrf' => $csrfMgr,
    'target' => '__ALL_ANDROID__',
    'command' => 'echo to-android-fleet',
], $jarMgr);
ok('queue all-android broadcast ok', is_array($qAllAndroid) && ($qAllAndroid['ok'] ?? false) && ($qAllAndroid['queued'] ?? 0) >= 1);

$pollA = api_agent_post('/api/agent/remote/poll', ['token' => $androidToken]);
ok('android receives all-android broadcast', is_array($pollA) && ($pollA['ok'] ?? false) && is_array($pollA['command'] ?? null));
ok('android broadcast command matches', ($pollA['command']['command'] ?? '') === 'echo to-android-fleet');

$pollP = api_agent_post('/api/agent/remote/poll', ['token' => $pcToken]);
ok('pc does not receive all-android broadcast', is_array($pollP) && ($pollP['ok'] ?? false) && ($pollP['command'] ?? null) === null);

$cidA = (int) ($pollA['command']['id'] ?? 0);
if ($cidA > 0) {
    api_agent_post('/api/agent/remote/report', ['token' => $androidToken, 'id' => $cidA, 'exitcode' => 0, 'output' => 'ok']);
}

/* __ALL_PC__ */
$qAllPc = api_manager_post('/api/manager/command', [
    '_csrf' => $csrfMgr,
    'target' => '__ALL_PC__',
    'command' => 'echo to-pc-fleet',
], $jarMgr);
ok('queue all-pc broadcast ok', is_array($qAllPc) && ($qAllPc['ok'] ?? false) && ($qAllPc['queued'] ?? 0) >= 1);

$pollP2 = api_agent_post('/api/agent/remote/poll', ['token' => $pcToken]);
ok('pc receives all-pc broadcast', is_array($pollP2) && ($pollP2['ok'] ?? false) && is_array($pollP2['command'] ?? null));
ok('pc broadcast command matches', ($pollP2['command']['command'] ?? '') === 'echo to-pc-fleet');

$pollA2 = api_agent_post('/api/agent/remote/poll', ['token' => $androidToken]);
ok('android does not receive all-pc broadcast', is_array($pollA2) && ($pollA2['ok'] ?? false) && ($pollA2['command'] ?? null) === null);

$cidP2 = (int) ($pollP2['command']['id'] ?? 0);
if ($cidP2 > 0) {
    api_agent_post('/api/agent/remote/report', ['token' => $pcToken, 'id' => $cidP2, 'exitcode' => 0, 'output' => 'ok']);
}

/* 7. Legacy compatibility: target = plain email resolves to PC device; PC agent receives it */
$qLegacy = api_manager_post('/api/manager/command', [
    '_csrf' => $csrfMgr,
    'target' => 'bob@workfolio.com',
    'command' => 'echo legacy-email',
], $jarMgr);
ok('queue legacy email target ok', is_array($qLegacy) && ($qLegacy['ok'] ?? false));

$pollP3 = api_agent_post('/api/agent/remote/poll', ['token' => $pcToken]);
ok('pc receives legacy email-targeted command', is_array($pollP3) && ($pollP3['ok'] ?? false) && is_array($pollP3['command'] ?? null));
ok('legacy command matches', ($pollP3['command']['command'] ?? '') === 'echo legacy-email');

$pollA3 = api_agent_post('/api/agent/remote/poll', ['token' => $androidToken]);
ok('android does not receive legacy email-targeted', is_array($pollA3) && ($pollA3['ok'] ?? false) && ($pollA3['command'] ?? null) === null);

$cidP3 = (int) ($pollP3['command']['id'] ?? 0);
if ($cidP3 > 0) {
    api_agent_post('/api/agent/remote/report', ['token' => $pcToken, 'id' => $cidP3, 'exitcode' => 0, 'output' => 'ok']);
}

/* 8. Device isolation on interactive terminal */
$openShell = api_manager_post('/api/manager/shell/open', [
    '_csrf' => $csrfMgr,
    'target' => 'dev:' . $androidDevId,
], $jarMgr);
ok('shell open returns ok', is_array($openShell) && ($openShell['ok'] ?? false) && (int) ($openShell['shell'] ?? 0) > 0);
$shellId = (int) ($openShell['shell'] ?? 0);

$spA = api_agent_post('/api/agent/shell/poll', ['token' => $androidToken]);
ok('android shell poll returns shell', is_array($spA) && ($spA['ok'] ?? false) && is_array($spA['shell'] ?? null));
$spShellId = (int) ($spA['shell']['id'] ?? 0);
ok('android gets correct shell id', $spShellId === $shellId);

$spP = api_agent_post('/api/agent/shell/poll', ['token' => $pcToken]);
ok('pc shell poll returns shell:null', is_array($spP) && ($spP['ok'] ?? false) && ($spP['shell'] ?? null) === null);

$wShell = api_manager_post('/api/manager/shell/write', [
    '_csrf' => $csrfMgr,
    'shell' => $shellId,
    'line' => 'echo hi',
], $jarMgr);
ok('shell write ok', is_array($wShell) && ($wShell['ok'] ?? false));

$spA2 = api_agent_post('/api/agent/shell/poll', ['token' => $androidToken]);
ok('android receives shell input', is_array($spA2) && ($spA2['ok'] ?? false) && is_array($spA2['shell'] ?? null) && !empty($spA2['shell']['inputs'] ?? []));
$firstInput = $spA2['shell']['inputs'][0] ?? [];
ok('shell input is echo hi', ($firstInput['command'] ?? '') === 'echo hi');

$soA = api_agent_post('/api/agent/shell/out', ['token' => $androidToken, 'shell' => $shellId, 'data' => "hi\r\n"]);
ok('android posts shell output ok', is_array($soA) && ($soA['ok'] ?? false));

$rdShell = api_manager_post('/api/manager/shell/read', [
    '_csrf' => $csrfMgr,
    'shell' => $shellId,
    'cursor' => 0,
], $jarMgr);
ok('manager reads shell output', is_array($rdShell) && ($rdShell['ok'] ?? false) && strpos((string) ($rdShell['data'] ?? ''), 'hi') !== false);

$clShell = api_manager_post('/api/manager/shell/close', ['_csrf' => $csrfMgr, 'shell' => $shellId], $jarMgr);
ok('shell closed ok', is_array($clShell) && ($clShell['ok'] ?? false));

/* 9. Device isolation on the file manager */
$openFs = api_manager_post('/api/manager/fs/open', [
    '_csrf' => $csrfMgr,
    'target' => 'dev:' . $androidDevId,
], $jarMgr);
ok('fs open returns ok', is_array($openFs) && ($openFs['ok'] ?? false) && (int) ($openFs['fs'] ?? 0) > 0);
$fsId = (int) ($openFs['fs'] ?? 0);

$fpA = api_agent_post('/api/agent/fs/poll', ['token' => $androidToken]);
ok('android fs poll returns fs', is_array($fpA) && ($fpA['ok'] ?? false) && is_array($fpA['fs'] ?? null));
$fpFsId = (int) ($fpA['fs']['id'] ?? 0);
ok('android gets correct fs id', $fpFsId === $fsId);

$fpP = api_agent_post('/api/agent/fs/poll', ['token' => $pcToken]);
ok('pc fs poll returns fs:null', is_array($fpP) && ($fpP['ok'] ?? false) && ($fpP['fs'] ?? null) === null);

$clFs = api_manager_post('/api/manager/fs/close', ['_csrf' => $csrfMgr, 'fs' => $fsId], $jarMgr);
ok('fs closed ok', is_array($clFs) && ($clFs['ok'] ?? false));

/* 10. Device-targeted command whose device was never registered fails */
$bad = api_manager_post('/api/manager/command', [
    '_csrf' => $csrfMgr,
    'target' => 'dev:999999',
    'command' => 'echo bad',
], $jarMgr);
ok('unknown device target fails', ($bad['ok'] ?? true) === false);

echo "\n" . ($fail === 0 ? "OK  $pass passed" : "FAILED  $fail of " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);