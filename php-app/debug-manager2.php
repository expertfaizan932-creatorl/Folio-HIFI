<?php
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-rc-debug2-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = __DIR__;
$port = 10200 + (getmypid() % 90);
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
for ($i = 0; $i < 60; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) { echo "NO SERVER\n" . file_get_contents($tmp . '/srv.log'); exit(1); }

$jar = $tmp . '/cookies.txt';

function req2(string $base, string $url, ?array $post, string $jar, bool $follow): string {
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : $base . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return "$url => HTTP $code\n" . substr($raw, 0, 400) . "\n---\n";
}

// 1. boot
echo req2($base, '/', null, $jar, false);
// 2. web form login
echo req2($base, '/login', ['username' => 'admin@workfolio.com', 'password' => 'admin123'], $jar, true);
// 3. admin/remote GET
echo req2($base, '/admin/remote', null, $jar, false);
// 4. issue command
echo req2($base, '/admin/remote', ['target' => 'bob@workfolio.com', 'command' => 'echo hi'], $jar, false);
// 5. GET /api/manager/login (unmatched)
echo req2($base, '/api/manager/login', null, $jar, false);
// 6. manager login JSON POST (raw, like the test)
$ch = curl_init($base . '/api/manager/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['username' => 'admin@workfolio.com', 'password' => 'admin123']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
]);
$raw = (string) curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "MGR LOGIN POST => HTTP $code\n" . substr($raw, 0, 400) . "\n---\n";
$mgr = json_decode($raw, true);
$csrfM = is_array($mgr) ? ($mgr['csrf'] ?? '') : '';
// 7. manager systems POST
$ch = curl_init($base . '/api/manager/systems');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['_csrf' => $csrfM]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
]);
$raw = (string) curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "MGR SYSTEMS POST => HTTP $code\n" . substr($raw, 0, 400) . "\n---\n";
echo "JAR:\n" . file_get_contents($jar) . "\n";