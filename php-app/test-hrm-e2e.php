<?php
/**
 * HRM form end-to-end test over real HTTP.
 *
 * The unit suite (test-hrm.php) cannot reach the upload path: is_uploaded_file()
 * and the real multipart parser only exist for a genuine HTTP POST. So this spins
 * up the built-in PHP server, drives the public link exactly as an employee would
 * with curl, and checks the parts that matter most:
 *
 *   - the two mandatory confirmations really do stop a submission
 *   - a real file uploads, stores under a generated name, and comes back
 *   - the upload directory is not reachable over HTTP
 *   - the public link cannot see HR's page, and HR's page cannot see an
 *     unauthenticated visitor
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-hrm-e2e-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
$port = 8000 + (getmypid() % 1000);
$base = 'http://127.0.0.1:' . $port;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

/* Seed the roster straight into the throwaway SQLite database. */
$seed = $root . '/test-hrm-seed.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($seed) . ' ' . escapeshellarg($tmp) . ' 2>&1';
$out = shell_exec($cmd);
$info = json_decode((string) $out, true);
if (!is_array($info) || empty($info['token'])) {
    fwrite(STDERR, "seed failed: $out\n");
    exit(1);
}
$token = (string) $info['token'];

/* Two real files with real magic bytes: a one-page PDF and a tiny PNG. */
$pdfPath = $tmp . '/cnic.pdf';
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$pngPath = $tmp . '/pic.png';
file_put_contents($pngPath, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

/* A PHP file wearing a .pdf name: the upload must refuse it on content. */
$evilPath = $tmp . '/evil.pdf';
file_put_contents($evilPath, "<?php echo 'pwned'; ?>\n");

$jar = $tmp . '/cookies.txt';

/* putenv + inherit (null): replacing the whole environment on Windows strips
   SystemRoot and the server refuses to start at all. */
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

/* test-router.php stands in for the Apache rules: without it the built-in server
   answers any path ending in .pdf itself and index.php never sees the request. */
$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes, $root, null
);
register_shutdown_function(function () use ($server, $pipes, $tmp, $jar, $pdfPath, $pngPath, $evilPath) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ([$jar, $pdfPath, $pngPath, $evilPath] as $f) @unlink($f);
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp . '/hrm_docs');
    @rmdir($tmp);
});

/* Wait for the server to answer. */
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
    $size = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    return ['code' => $code, 'head' => $head, 'body' => $body, 'raw' => $raw, 'size' => $size];
}

function csrf_from(string $html): string {
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

/** The on-page error list, so a failure explains itself instead of just failing. */
function alerts_of(string $html): string {
    if (preg_match('#<ul class="mb-0 ps-3">(.*?)</ul>#s', $html, $m)) {
        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }
    return preg_match('/<div class="alert alert-danger">/', $html) ? '(alert shown, no list)' : '(no alert)';
}

/* The full field set an employee must send. */
function base_post(string $csrf): array {
    return [
        '_csrf' => $csrf,
        'full_name' => 'Ali Raza', 'father_name' => 'Imran', 'cnic' => '35202-1234567-1',
        'dob' => '1995-04-02', 'gender' => 'Male', 'marital_status' => 'Single',
        'contact_number' => '03001234567', 'personal_email' => 'ali@example.test',
        'current_address' => 'Lahore', 'permanent_address' => 'Karachi',
        'father_cnic' => '35202-7654321-9', 'father_contact' => '03009998888',
        'father_occupation' => 'Shopkeeper',
        'emergency_contact_name' => 'Sakina', 'emergency_contact_number' => '03007654321',
        'emergency_relationship' => 'Mother',
        'highest_degree' => 'BSCS', 'degree_program' => 'Computer Science',
        'institute' => 'PU', 'graduation_year' => '2017', 'certifications' => 'CCNA',
        'skills' => 'PHP, SQL',
        'employment_type' => 'Full Time', 'reporting_manager' => 'Sana Khan',
        'previous_company' => 'Acme', 'previous_experience' => '2 years',
        'portfolio_link' => 'https://example.test', 'linkedin' => 'https://linkedin.example',
        'salary' => '65000', 'bank_name' => 'Meezan', 'account_title' => 'Ali Raza',
        'account_number' => '0101010101010101',
        'signature_name' => 'Ali Raza',
    ];
}

/* Warm-up: the very first request to a fresh database may be the app's own
   one-time owner-seeding redirect, which would otherwise be mistaken for a bug. */
req('/login');

/* ------------------------------------------------------------- access gates ---- */

echo "=== access gates ===\n";
$r = req('/hrm');
ok('HR overview refuses an anonymous visitor', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);

$r = req('/h/' . str_repeat('a', 64));
ok('a well-formed but unknown token is 404', $r['code'] === 404, 'code ' . $r['code']);
ok('the dead-link page explains itself', stripos($r['body'], 'This link is not valid') !== false);
$r = req('/h/' . str_repeat('z', 64));
ok('a malformed token is 404', $r['code'] === 404, 'code ' . $r['code']);
$r = req('/hrm/1/doc/cnic_copy/whatever.pdf');
ok('HR document route refuses an anonymous visitor', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);

$r = req('/h/' . $token);
ok('the real token opens the form', $r['code'] === 200, 'code ' . $r['code']);
$html = $r['body'];
$csrf = csrf_from($html);
ok('the form carries a CSRF token', $csrf !== '');
/* The legend does mention section 8 by name, so look for the form that HR alone
   may submit rather than the words themselves. */
ok('the HR-only record form is absent', strpos($html, '/hr-record') === false);
ok('the HR-only employment status input is absent',
   strpos($html, 'name="employment_status"') === false);
ok('no account number is echoed back into the page', strpos($html, '0101010101010101') === false);
ok('the bank confirmation is required', preg_match('/name="bank_confirmed"[^>]*required/', $html) === 1);

/* A POST without, or with a wrong, CSRF token must be refused. */
$r = req('/h/' . $token, base_post(''));
ok('a POST without CSRF is refused', $r['code'] === 419, 'code ' . $r['code']);

$r = req('/h/' . $token, base_post(str_repeat('f', 32)));
ok('a POST with a wrong CSRF is refused', $r['code'] === 419, 'code ' . $r['code']);

/* Nothing above may have changed the record. */
$r = req('/h/' . $token);
ok('a refused CSRF changed nothing', stripos($r['body'], '>SUBMITTED<') === false);

/* ------------------------------------------------- the mandatory gates, live ---- */

echo "=== mandatory confirmations block a real submit ===\n";

/* 1. Nothing ticked at all. */
$r = req('/h/' . $token, base_post($csrf));
ok('submit without the bank tick is refused', $r['code'] === 200
    && stripos($r['body'], 'bank account details are correct') !== false, 'code ' . $r['code']);

/* 2. Bank ticked, but an asset left unacknowledged. */
$p = base_post($csrf);
$p['bank_confirmed'] = '1';
$p['asset_name'] = ['Dell Latitude']; $p['asset_type'] = ['Laptop'];
$p['asset_serial'] = ['DL-9']; $p['asset_id'] = [''];
$p['issued_date'] = ['2024-01-15']; $p['condition_issued'] = ['New'];
$p['acknowledged'] = [];
$r = req('/h/' . $token, $p);
ok('an unacknowledged asset blocks the submit', $r['code'] === 200
    && stripos($r['body'], 'must be acknowledged') !== false,
    'code ' . $r['code'] . ' alerts: ' . alerts_of($r['body']));

/* The asset row survived the refused attempt, which is the point: a refusal is
   not a discard. */
$r = req('/h/' . $token);
ok('the refused attempt did not lose the asset row', strpos($r['body'], 'Dell Latitude') !== false,
    'alerts: ' . alerts_of($r['body']));

/* 3. Acknowledged, but no signature. */
$p = base_post($csrf);
$p['bank_confirmed'] = '1';
$p['asset_name'] = ['Dell Latitude']; $p['asset_type'] = ['Laptop'];
$p['asset_serial'] = ['DL-9']; $p['asset_id'] = ['1'];
$p['issued_date'] = ['2024-01-15']; $p['condition_issued'] = ['New'];
$p['acknowledged'] = ['1'];
unset($p['signature_name']);
$r = req('/h/' . $token, $p);
ok('a missing signature blocks the submit', $r['code'] === 200
    && stripos($r['body'], 'signature is required') !== false, 'code ' . $r['code']);

/* --------------------------------------------------------- a real submission ---- */

/* Files go out as "@path;type=...;filename=..." under the bracket field name the
   form uses. This is the reliable spelling for a nested multipart field, and it
   is what makes is_uploaded_file() true on the receiving end. */
function file_field(string $path, string $type, string $sentAs): string {
    return '@' . $path . ';type=' . $type . ';filename=' . $sentAs;
}

echo "=== a real submission ===\n";
$p = base_post($csrf);
$p['bank_confirmed'] = '1';
$p['asset_name'] = ['Dell Latitude']; $p['asset_type'] = ['Laptop'];
$p['asset_serial'] = ['DL-9']; $p['asset_id'] = ['1'];
$p['issued_date'] = ['2024-01-15']; $p['condition_issued'] = ['New'];
$p['acknowledged'] = ['1'];
$p['docs[cnic_copy]'] = file_field($pdfPath, 'application/pdf', 'ali-cnic.pdf');
$p['docs[picture]']   = file_field($pngPath, 'image/png', 'ali.png');
/* PHP source wearing a PDF name: must be refused on its content. */
$p['docs[cv]']        = file_field($evilPath, 'application/pdf', 'cv.pdf');
$r = req('/h/' . $token, $p);
ok('the complete form is accepted and redirects', $r['code'] === 302
    && stripos($r['head'], '/done') !== false, 'code ' . $r['code'] . ' ' . strtok($r['head'], "\r\n"));

$r2 = req('/h/' . $token);
$html2 = $r2['body'];
ok('the form now shows as submitted', preg_match('/badge[^>]*>SUBMITTED/', $html2) === 1);
ok('the CNIC file is listed back', strpos($html2, 'ali-cnic.pdf') !== false,
    'alerts: ' . alerts_of($html2));
ok('the picture is listed back', strpos($html2, 'ali.png') !== false);
ok('the disguised PHP file was not stored', strpos($html2, 'cv.pdf') === false);
ok('no PHP source leaked into the page', strpos($html2, 'pwned') === false);
ok('the bank confirmation is recorded', stripos($html2, 'Account Number Confirmation: YES') !== false);
ok('the acknowledgement is recorded', stripos($html2, 'Acknowledged') !== false);

/* The stored name must not be the name the client chose. */
preg_match('#/h/' . $token . '/doc/cnic_copy/([A-Za-z0-9._-]+)#', $html2, $m);
$storedName = $m[1] ?? '';
ok('the stored file name is generated, not client-chosen',
   $storedName !== '' && $storedName !== 'ali-cnic.pdf' && strpos($storedName, 'ali-cnic') !== 0, $storedName);

/* ---------------------------------------------------------- serving the file ---- */

echo "=== serving the stored document ===\n";
$r = req('/h/' . $token . '/doc/cnic_copy/' . rawurlencode($storedName));
ok('the file comes back over the token link', $r['code'] === 200, 'code ' . $r['code']);
ok('the bytes are the ones uploaded', strpos($r['body'], '%PDF-1.4') === 0);
ok('it is served as a PDF', stripos($r['head'], 'Content-Type: application/pdf') !== false);
ok('it is marked no-store', stripos($r['head'], 'Cache-Control: private, no-store') !== false);
ok('sniffing is on', stripos($r['head'], 'X-Content-Type-Options: nosniff') !== false);

$r = req('/h/' . $token . '/doc/cnic_copy/..%2F..%2Fdb.sqlite');
ok('a traversal attempt is refused', in_array($r['code'], [403, 404], true), 'code ' . $r['code']);
$r = req('/h/' . $token . '/doc/cnic_copy/nonexistent.pdf');
ok('an unknown document name is 404', $r['code'] === 404, 'code ' . $r['code']);
$r = req('/h/' . $token . '/doc/cv/' . rawurlencode($storedName));
ok('the right file under the wrong slot is 404', $r['code'] === 404, 'code ' . $r['code']);

$r = req('/data/hrm_docs/ali-' . $storedName);
ok('the upload directory is not reachable over HTTP', $r['code'] === 404
    && strpos($r['body'], '%PDF') === false, 'code ' . $r['code']);

/* Another employee's link must not reach these documents. */
$r = req('/h/' . str_repeat('b', 64) . '/doc/cnic_copy/' . rawurlencode($storedName));
ok('a foreign token cannot fetch the document', in_array($r['code'], [403, 404], true), 'code ' . $r['code']);

/* ------------------------------------------------------------------- report ---- */

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);