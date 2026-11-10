<?php
// debug4: full test flow up to manager systems, WITH agent steps, follow redirects
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-rc-debug4-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');
$root = __DIR__;
$port = 10400 + (getmypid() % 90);
$base = 'http://127.0.0.1:' . $port;
$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes, $root, null
);
register_shutdown_function(function () use ($server, $tmp) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});
$up = false;
for ($i = 0; $i < 60; $i++) { usleep(150000); $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($s) { fclose($s); $up = true; break; } }
if (!$up) { echo "NO SERVER\n"; exit(1); }
echo "PORT=$port\n";

$jar = $tmp . '/cookies.txt';
function req4(string $base, string $url, ?array $post, ?string $jar, bool $follow, bool $head = false): string {
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : $base . $url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => $head, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 30];
    if ($jar) { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    if ($post !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $post; }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return "$url => $code " . ($head ? $raw : substr($raw, 0, 90)) . "\n";
}
function api4(string $base, string $path, array $body): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30]);
    $raw = (string) curl_exec($ch); curl_close($ch);
    return json_decode($raw, true) ?? ['raw' => $raw];
}

req4($base, '/', null, $jar, false);
req4($base, '/login', ['username' => 'admin@workfolio.com', 'password' => 'admin123'], $jar, true);
$r = req4($base, '/admin/remote', null, $jar, false);
$csrfW = (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r, $m) === 1) ? $m[1] : '';
req4($base, '/admin/remote', ['_csrf' => $csrfW, 'target' => 'bob@workfolio.com', 'command' => 'echo hello'], $jar, false);

// agent: bob exists? create
$pdo = new PDO('sqlite:' . $tmp . '/db.sqlite');
$pdo->query("INSERT INTO employees (first_name,last_name,email,`role`,password) VALUES ('Bob','T','bob@workfolio.com','EMPLOYEE','"
    . password_hash('bob123', PASSWORD_BCRYPT) . "')");

$login = api4($base, '/api/agent/login', ['email' => 'bob@workfolio.com', 'password' => 'bob123']);
echo "agent login: " . json_encode(isset($login['token'])) . "\n";
$token = $login['token'] ?? '';
echo "poll: " . json_encode(api4($base, '/api/agent/remote/poll', ['token' => $token])) . "\n";
echo "poll2: " . json_encode(api4($base, '/api/agent/remote/poll', ['token' => $token])) . "\n";

// manager login via jar
$mgr = api4($base, '/api/manager/login', ['username' => 'admin@workfolio.com', 'password' => 'admin123']);
echo "mgr login (nojar): " . json_encode($mgr) . "\n";
$ch = curl_init($base . '/api/manager/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['username' => 'admin@workfolio.com', 'password' => 'admin123']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$raw = (string) curl_exec($ch); curl_close($ch);
$mgrJar = json_decode($raw, true);
$csrfM = is_array($mgrJar) ? ($mgrJar['csrf'] ?? '') : '';
echo "mgr login (jar): " . json_encode($mgrJar) . "\n";

$ch = curl_init($base . '/api/manager/systems');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['_csrf' => $csrfM]), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
echo "systems FOLLOW (code=$code):\n" . substr($raw, 0, 500) . "\n";