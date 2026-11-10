<?php
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-rc-debug-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = __DIR__;
$port = 10100 + (getmypid() % 90);
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

// Boot schema (seeds admin@workfolio.com/admin123)
$ch = curl_init($base . '/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
curl_exec($ch);
curl_close($ch);

// Manager login (JSON)
$ch = curl_init($base . '/api/manager/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['username' => 'admin@workfolio.com', 'password' => 'admin123']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    CURLOPT_HEADER => true,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "login POST: $code\n" . substr($raw, 0, 500) . "\n---\n";
$json = json_decode(substr($raw, strrpos($raw, "\r\n\r\n") + 4), true);
$csrf = $json['csrf'] ?? '';

// Raw login GET with same jar (like the test)
$ch = curl_init($base . '/api/manager/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HEADER => true]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "login GET (unmatched): $code\n";

// Manager systems
$ch = curl_init($base . '/api/manager/systems');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['_csrf' => $csrf]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "systems POST: $code\nBODY: " . substr($raw, 0, 300) . "\n---\n";
echo "JAR:\n" . file_get_contents($jar) . "\n";