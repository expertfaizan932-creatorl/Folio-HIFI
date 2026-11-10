<?php
/**
 * Landing page smoke test over real HTTP.
 *
 * The contract at the front door is one redirect: anonymously GET / is the
 * public landing page with the sign-in form on it, and signed in the same URL
 * goes straight to the dashboard so an employee's bookmark still works. This
 * drives that exact pair in a browser's shape - cookies, no auto-follow on the
 * redirect - because a broken front door is the first thing anyone notices.
 *
 *   php php-app/test-landing-smoke.php
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-landing-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
$port = 8100 + (getmypid() % 800);
$base = 'http://127.0.0.1:' . $port;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

$jar = $tmp . '/cookies.txt';

$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes, $root, null
);
register_shutdown_function(function () use ($server, $pipes, $tmp, $jar) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ([$jar] as $f) @unlink($f);
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
});

$up = false;
for ($i = 0; $i < 60; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    fwrite(STDERR, "server did not start\n--- log ---\n"
        . (string) @file_get_contents($tmp . '/srv.log') . "\n");
    exit(1);
}

function req(string $url, ?array $post = null, bool $follow = false): array {
    global $jar, $base;
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
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    return ['code' => $code, 'head' => $head, 'body' => $body];
}

function location_of(array $r): string {
    return preg_match('/^Location:\s*(.+)$/mi', $r['head'], $m) ? trim($m[1]) : '';
}

function csrf_from(string $html): string {
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

/* ------------------------------------------------------- anonymous visitor ---- */

echo "=== anonymous at the front door ===\n";

$r = req('/');
ok('GET / answers 200', $r['code'] === 200, 'code ' . $r['code']);
ok('it is the landing page', strpos($r['body'], 'lp-h1') !== false);
ok('with its own title', stripos($r['body'], 'Employee Activity, Time Tracking') !== false);
ok('and the sign-in form on it', stripos($r['body'], 'Sign in to your workspace') !== false
    || stripos($r['body'], '>Sign in<') !== false);
ok('no app shell leaks out to a visitor', strpos($r['body'], '>Employee Form<') === false
    && stripos($r['body'], 'Welcome back,') === false);
ok('its sign-in form posts to /login',
    preg_match('#<form[^>]*action="[^"]*/login"#', $r['body']) === 1);
ok('and carries the CSRF field a login now needs',
    preg_match('#name="_csrf"#', $r['body']) === 1);

$r = req('/landing');
ok('GET /landing is the same page', $r['code'] === 200 && strpos($r['body'], 'lp-h1') !== false,
    'code ' . $r['code']);

$r = req('/dashboard');
ok('the dashboard still refuses a visitor', in_array($r['code'], [302, 401, 403], true),
    'code ' . $r['code']);

/* ---------------------------------------------------------- signed in user ---- */

echo "\n=== signed in at the front door ===\n";

$r = req('/login');
ok('the login page renders', $r['code'] === 200, 'code ' . $r['code']);
$loginToken = csrf_from($r['body']);
ok('the login page exposes a CSRF token', $loginToken !== '');
$r = req('/login', ['username' => 'admin@workfolio.com', 'password' => 'admin123',
                    '_csrf' => $loginToken]);
ok('the seeded admin signs in', in_array($r['code'], [302, 303], true), 'code ' . $r['code']);
ok('and is sent to the dashboard', stripos(location_of($r), '/dashboard') !== false,
    location_of($r));

$r = req('/dashboard');
ok('the dashboard renders for them', $r['code'] === 200, 'code ' . $r['code']);
ok('it greets them by name', stripos($r['body'], 'Welcome back,') !== false);

$r = req('/');
ok('GET / now redirects instead of re-showing the landing page',
    in_array($r['code'], [302, 303], true), 'code ' . $r['code']);
ok('straight to the dashboard', stripos(location_of($r), '/dashboard') !== false, location_of($r));

$r = req('/', null, true);
ok('following it lands on the dashboard', $r['code'] === 200
    && stripos($r['body'], 'Welcome back,') !== false, 'code ' . $r['code']);
ok('and the app shell is there', strpos($r['body'], '>Employee Form<') !== false);

/* The landing page also exists at its own address while signed in. */
$r = req('/landing');
ok('/landing keeps working signed in', $r['code'] === 302 && stripos(location_of($r), '/dashboard') !== false,
    'code ' . $r['code'] . ' -> ' . location_of($r));

echo "\n" . str_repeat('=', 60) . "\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES") . ": {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
