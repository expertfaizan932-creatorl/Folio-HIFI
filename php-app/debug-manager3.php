<?php
// Exact mirror of test-remote-command.php to isolate the 302 on /api/manager/systems
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-rc-debug3-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');
$root = __DIR__;
$port = 10300 + (getmypid() % 90);
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

$jar = $tmp . '/cookies.txt';
function req3(string $base, string $url, ?array $post, string $jar, bool $follow): array {
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : $base . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $head = substr($raw, 0, strpos($raw, "\r\n\r\n") ?: 0);
    $loc = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $head, $m)) $loc = $m[1];
    return ['code' => $code, 'body' => substr($raw, strpos($raw, "\r\n\r\n") + 4), 'loc' => $loc];
}
function csrf3(string $html): ?string {
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) === 1 ? $m[1] : null;
}

req3($base, '/', null, $jar, false);
req3($base, '/login', ['username' => 'admin@workfolio.com', 'password' => 'admin123'], $jar, true);
$r = req3($base, '/admin/remote', null, $jar, false);
$csrfW = csrf3($r['body']);
echo "web csrf: " . ($csrfW ?? 'NULL') . "\n";
$r = req3($base, '/admin/remote', ['_csrf' => $csrfW, 'target' => 'bob@x.com', 'command' => 'echo hi'], $jar, false);
echo "issue: " . $r['code'] . "\n";
req3($base, '/api/manager/login', null, $jar, false);

// manager login JSON
$ch = curl_init($base . '/api/manager/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['username' => 'admin@workfolio.com', 'password' => 'admin123']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$raw = (string) curl_exec($ch); curl_close($ch);
$mgr = json_decode($raw, true);
$csrfM = is_array($mgr) ? ($mgr['csrf'] ?? '') : '';
echo "mgr login: " . json_encode($mgr) . "\n";
echo "jar after mgr login:\n" . file_get_contents($jar) . "\n";

// systems with jar
$ch = curl_init($base . '/api/manager/systems');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['_csrf' => $csrfM]), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
echo "systems(code=$code): " . substr($raw, 0, 200) . "\n---\n";

// systems with header csrf instead of body, same jar (test does body; try header to compare)
$ch = curl_init($base . '/api/manager/systems');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode([]), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF: ' . $csrfM], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
$raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
echo "systems-XCSRF(code=$code): " . substr($raw, 0, 200) . "\n";