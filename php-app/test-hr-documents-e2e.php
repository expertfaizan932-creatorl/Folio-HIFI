<?php
/**
 * HR Employee Documents end-to-end smoke test over real HTTP.
 *
 * Spins up the built-in PHP server against a throwaway SQLite database and walks
 * the documents module: category seeding, upload, view, download, replace,
 * expiry edit, delete, and the role rules that matter most - a sensitive
 * category is readable by HR but NOT by the employee it is about, and an
 * employee can always read their own non-sensitive documents.
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-hrdoc-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
$port = 8300 + (getmypid() % 600);
$base = 'http://127.0.0.1:' . $port;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

/* Seed the roster in its own process, same helper the HR e2e test uses. */
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/test-hr-seed.php') . ' ' . escapeshellarg($tmp) . ' 2>&1';
$out = shell_exec($cmd);
$info = json_decode((string) $out, true);
if (!is_array($info) || empty($info['emp_id'])) { fwrite(STDERR, "seed failed: $out\n"); exit(1); }
$empId = (int) $info['emp_id'];

$adminJar = $tmp . '/admin.txt';
$empJar   = $tmp . '/emp.txt';

$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes, $root, null
);
register_shutdown_function(function () use ($server, $tmp) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    foreach (glob($tmp . '/hr_docs/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp . '/hr_docs');
    @rmdir($tmp);
});

$up = false;
for ($i = 0; $i < 60; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) { fwrite(STDERR, "server did not start\n" . (string) @file_get_contents($tmp . '/srv.log') . "\n"); exit(1); }

function req(string $url, $post = null, string $jar = 'admin', array $files = []): array {
    global $base, $tmp;
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : $base . $url);
    $cookie = $tmp . '/' . $jar . '.txt';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($files) {
            foreach ($files as $field => $spec) $post[$field] = new CURLFile($spec[0], $spec[1], $spec[2]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    return ['code' => $code, 'head' => $head, 'body' => $body];
}

function csrf_from(string $html): string {
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

/* Sign-in now carries a CSRF token, so fetch the form on the same jar first. */
function login_as(string $email, string $pass, string $jar = 'admin'): array {
    $form = req('/login', null, $jar);
    return req('/login', ['username' => $email, 'password' => $pass, '_csrf' => csrf_from($form['body'])], $jar);
}

/* Two tiny real files to upload. */
$fileA = $tmp . '/cnic.txt';  file_put_contents($fileA, "CNIC-CONTENT-AAA\n");
$fileB = $tmp . '/cnic2.txt'; file_put_contents($fileB, "CNIC-CONTENT-BBB\n");
$fileW = $tmp . '/warn.txt';  file_put_contents($fileW, "WARNING-CONTENT\n");

req('/login');   /* warm up + seed owner */
$r = req('/hr/documents');
ok('anonymous /hr/documents is refused', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);

$r = login_as('admin@workfolio.com', 'admin123', 'admin');
ok('owner signs in', $r['code'] === 302, 'code ' . $r['code']);

echo "=== screens ===\n";
$r = req('/hr/documents');
$docsLanding = $r['body'];
ok('GET /hr/documents renders', $r['code'] === 200 && strpos($r['body'], 'Employee Documents') !== false, 'code ' . $r['code']);
$csrf = csrf_from($r['body']);
ok('a CSRF token is present', $csrf !== '');
ok('seeded category is offered', strpos($r['body'], 'CNIC / Identity') !== false);
ok('sensitive category is offered', strpos($r['body'], 'Warning Letter') !== false);

$r = req('/hr/documents/categories');
ok('GET /hr/documents/categories renders', $r['code'] === 200 && strpos($r['body'], 'Document Categories') !== false, 'code ' . $r['code']);

/* Category ids straight from the upload form's select on the landing page. */
preg_match('#<option value="(\d+)">CNIC / Identity#', $docsLanding, $cm1);
preg_match('#<option value="(\d+)">Warning Letter#', $docsLanding, $cm2);
$catCnic = (int) ($cm1[1] ?? 0);
$catWarn = (int) ($cm2[1] ?? 0);
ok('category ids were found', $catCnic > 0 && $catWarn > 0, "cnic=$catCnic warn=$catWarn");

echo "=== a POST without CSRF is refused ===\n";
$r = req('/hr/documents/upload', ['email' => 'ali@folio.test'], 'admin', ['document' => [$fileA, 'text/plain', 'cnic.txt']]);
ok('upload without CSRF is 419', $r['code'] === 419, 'code ' . $r['code']);

echo "=== upload + view + download ===\n";
$r = req('/hr/documents/upload', [
    '_csrf' => $csrf, 'email' => 'ali@folio.test', 'category_id' => (string) $catCnic,
    'title' => 'Ali CNIC', 'issued_date' => '2024-01-01', 'expiry_date' => '2030-01-01', 'notes' => 'front and back',
], 'admin', ['document' => [$fileA, 'text/plain', 'cnic.txt']]);
ok('upload redirects', $r['code'] === 302, 'code ' . $r['code']);
preg_match('#Location:\s*(\S+)#i', $r['head'], $lm);
$loc = (string) ($lm[1] ?? '');
preg_match('#/hr/documents/view/(\d+)#', $loc, $dm);
$docId = (int) ($dm[1] ?? 0);
ok('a document id was found', $docId > 0, 'location ' . $loc);

$r = req('/hr/documents/view/' . $docId);
ok('document detail renders the title', $r['code'] === 200 && strpos($r['body'], 'Ali CNIC') !== false, 'code ' . $r['code']);
ok('history shows the upload', strpos($r['body'], 'UPLOADED') !== false);

$r = req('/hr/documents/file/' . $docId);
ok('HR can download the file', $r['code'] === 200 && strpos($r['body'], 'CNIC-CONTENT-AAA') !== false, 'code ' . $r['code']);
ok('served with a private no-store cache header', stripos($r['head'], 'no-store') !== false);

echo "=== replace + expiry edit ===\n";
$r = req('/hr/documents/' . $docId . '/replace', ['_csrf' => $csrf], 'admin', ['document' => [$fileB, 'text/plain', 'cnic2.txt']]);
ok('replace redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/documents/file/' . $docId);
ok('the replacement bytes are served', strpos($r['body'], 'CNIC-CONTENT-BBB') !== false);
$r = req('/hr/documents/view/' . $docId);
ok('history shows the replacement', strpos($r['body'], 'REPLACED') !== false);

$r = req('/hr/documents/' . $docId . '/update', ['_csrf' => $csrf, 'category_id' => (string) $catCnic,
    'title' => 'Ali CNIC (updated)', 'expiry_date' => '2031-06-30', 'notes' => 'verified'], 'admin');
ok('detail edit redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/documents/view/' . $docId);
ok('the edit is persisted', strpos($r['body'], 'Ali CNIC (updated)') !== false && strpos($r['body'], '2031-06-30') !== false);

echo "=== a sensitive category is HR-only ===\n";
$r = req('/hr/documents/upload', [
    '_csrf' => $csrf, 'email' => 'ali@folio.test', 'category_id' => (string) $catWarn, 'title' => 'Ali Warning',
], 'admin', ['document' => [$fileW, 'text/plain', 'warn.txt']]);
ok('sensitive upload redirects', $r['code'] === 302, 'code ' . $r['code']);
preg_match('#Location:\s*(\S+)#i', $r['head'], $wm);
preg_match('#/hr/documents/view/(\d+)#', (string) ($wm[1] ?? ''), $wmm);
$warnId = (int) ($wmm[1] ?? 0);
ok('a sensitive document id was found', $warnId > 0);

/* The employee signs in. */
$r = login_as('ali@folio.test', 'secret123', 'emp');
ok('employee signs in', $r['code'] === 302, 'code ' . $r['code']);

$r = req('/hr/me', null, 'emp');
ok('employee /hr/me renders My Documents', $r['code'] === 200 && strpos($r['body'], 'My Documents') !== false, 'code ' . $r['code']);
ok('employee sees their non-sensitive document', strpos($r['body'], 'Ali CNIC') !== false);
ok('employee does NOT see the sensitive document', strpos($r['body'], 'Ali Warning') === false);

$r = req('/hr/documents/file/' . $docId, null, 'emp');
ok('employee can download their own non-sensitive file', $r['code'] === 200 && strpos($r['body'], 'CNIC-CONTENT-BBB') !== false, 'code ' . $r['code']);

$r = req('/hr/documents/file/' . $warnId, null, 'emp');
ok('employee is refused the sensitive file', $r['code'] === 403, 'code ' . $r['code']);

$r = req('/hr/documents', null, 'emp');
ok('employee cannot open the HR documents list', in_array($r['code'], [302, 403], true), 'code ' . $r['code']);

echo "=== delete ===\n";
$r = req('/hr/documents/' . $docId . '/delete', ['_csrf' => $csrf], 'admin');
ok('delete redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/documents');
ok('the deleted document is gone', strpos($r['body'], 'Ali CNIC') === false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
