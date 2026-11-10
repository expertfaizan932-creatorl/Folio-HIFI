<?php
/**
 * Employee Form (hiring intake) end-to-end test over real HTTP.
 *
 * The point of this suite is the pair of promises the sidebar makes: filling the
 * form in and submitting it actually stores something, and what was stored is
 * then visible on that same screen. Everything below is driven as a browser would
 * drive it - cookies, CSRF, multipart uploads, redirects - because the parts most
 * likely to break are exactly the ones a unit test cannot reach: the upload path,
 * the redirect after a POST, and the second read of the list.
 *
 *   php php-app/test-employee-form-e2e.php
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-ef-e2e-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
$port = 9000 + (getmypid() % 900);
$base = 'http://127.0.0.1:' . $port;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

/* A real one-page PDF, and a PHP file wearing a .pdf name. Both have real magic
   bytes, because the upload path sniffs content and not just the extension. */
$pdfPath = $tmp . '/cv.pdf';
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$evilPath = $tmp . '/evil.pdf';
file_put_contents($evilPath, "<?php echo 'pwned'; ?>\n");

$jar = $tmp . '/cookies.txt';

$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes, $root, null
);
register_shutdown_function(function () use ($server, $pipes, $tmp, $jar, $pdfPath, $evilPath) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ([$jar, $pdfPath, $evilPath] as $f) @unlink($f);
    foreach (glob($tmp . '/employee_form_cvs/*') ?: [] as $f) @unlink($f);
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp . '/employee_form_cvs');
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

function csrf_from(string $html): string {
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

/** A complete, valid application. */
function good_post(string $csrf, array $over = []): array {
    return array_merge([
        '_csrf' => $csrf,
        'full_name' => 'Sana Khan',
        'email' => 'sana@example.test',
        'phone' => '03001234567',
        'cnic' => '35202-1234567-1',
        'dob' => '1996-04-02',
        'gender' => 'Female',
        'marital_status' => 'Single',
        'current_address' => 'Gulberg, Lahore',
        'position_title' => 'Accounts Officer',
        'department' => 'Finance',
        'employment_type' => 'Full Time',
        'work_location' => 'Lahore - Head Office',
        /* Must be in the past: ef_errors() rejects a future joining date as a
           typo, and "future" is relative to whenever this suite runs. */
        'joining_date' => '2026-09-15',
        'salary_expected' => '65000',
        'highest_degree' => 'BS Accounting',
        'institute' => 'PU',
        'graduation_year' => '2017',
        'previous_company' => 'Acme',
        'total_experience' => '4 years',
        'skills' => "PHP\nSQL",
        'previous_experience' => '2 years at Beta',
        'linkedin' => 'https://linkedin.example/sana',
        'emergency_contact_name' => 'Adeel Khan',
        'emergency_contact_number' => '03007654321',
        'notes' => 'Available after one month notice.',
    ], $over);
}

/* Warm-up: the first request to a fresh database may be the one-time seeding
   redirect, which would otherwise be read as a failed login below. */
req('/login');

/* ================================================================ access gates = */

echo "=== access gates ===\n";

$r = req('/employee-form');
ok('the form refuses an anonymous visitor', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);

$r = req('/employee-form/1');
ok('a record refuses an anonymous visitor', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);

$r = req('/employee-form/cv/1');
ok('a CV refuses an anonymous visitor', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);

/* The login page now carries a CSRF field as well: a sign-in posted from some
   other origin cannot read this session's token, so a forged login is refused. */
$r = req('/login');
ok('the login page is reachable', $r['code'] === 200, 'code ' . $r['code']);
ok('the login page carries a CSRF field', strpos($r['body'], 'name="_csrf"') !== false);
$loginToken = csrf_from($r['body']);
$r = req('/login', ['username' => 'admin@workfolio.com', 'password' => 'admin123']);
ok('a sign-in without the CSRF token is refused', $r['code'] === 419, 'code ' . $r['code']);
$r = req('/login', ['username' => 'admin@workfolio.com', 'password' => 'admin123',
                    '_csrf' => $loginToken], true);
ok('the seeded admin can sign in', $r['code'] === 200 && stripos($r['body'], 'Employee Form') !== false,
    'code ' . $r['code']);

/* Now authenticated, so a missing CSRF is a real CSRF failure rather than the
   login redirect that would hide it. */
$r = req('/employee-form/1/status', ['_csrf' => '', 'status' => 'HIRED']);
ok('a status change without CSRF is refused', $r['code'] === 419, 'code ' . $r['code']);

/* ================================================================= the screen = */

echo "\n=== the Employee Form screen ===\n";

$r = req('/employee-form');
$html = $r['body'];
$csrf = csrf_from($html);
ok('the page loads for a supervisor', $r['code'] === 200, 'code ' . $r['code']);
ok('it carries a CSRF token', $csrf !== '');
ok('the sidebar now says Employee Form', stripos($html, '>Employee Form<') !== false);
ok('the old HRM sidebar label is gone', strpos($html, 'HRM') === false);
ok('the hiring form is on the page', strpos($html, 'name="full_name"') !== false
    && strpos($html, 'name="position_title"') !== false);
ok('the submissions panel is on the same page', stripos($html, 'Submissions') !== false);
ok('required fields are marked', preg_match('/name="full_name"[^>]*required/', $html) === 1);
ok('the CV input accepts only the three real formats',
    strpos($html, 'accept=".pdf,.doc,.docx"') !== false);
ok('an empty install says so rather than showing a bare table',
    stripos($html, 'No applications yet') !== false);

/* ================================================================ validation = */

echo "\n=== nothing invalid reaches the table ===\n";

$r = req('/employee-form/save', good_post($csrf, ['full_name' => '', 'email' => '']), true);
ok('a blank required field is refused', stripos($r['body'], 'was not saved') !== false);
ok('the refusal names the missing fields', stripos($r['body'], 'Full Name is required') !== false);
ok('and nothing was written', stripos($r['body'], 'No applications yet') !== false);

$r = req('/employee-form/save', good_post($csrf, ['email' => 'not-an-email']), true);
ok('a malformed email is refused', stripos($r['body'], 'not a valid email address') !== false);
ok('still nothing written', stripos($r['body'], 'No applications yet') !== false);

$r = req('/employee-form/save', good_post($csrf, ['phone' => '123']), true);
ok('a too-short phone is refused', stripos($r['body'], 'must be 7 to 20 digits') !== false);

$r = req('/employee-form/save', good_post($csrf, ['joining_date' => '2099-01-01']), true);
ok('a joining date in the future is refused', stripos($r['body'], 'cannot be in the future') !== false);

$r = req('/employee-form/save', good_post($csrf, ['graduation_year' => '1901']), true);
ok('an impossible graduation year is refused', stripos($r['body'], 'Graduation Year must be between') !== false);

$r = req('/employee-form/save', good_post($csrf, ['gender' => 'Alien']), true);
ok('a value outside the listed options is refused', stripos($r['body'], 'Gender is not one of') !== false);

$r = req('/employee-form/save', good_post($csrf, ['dob' => '2026-02-31']), true);
ok('a date that does not exist is refused', stripos($r['body'], 'not a real date') !== false);

/* A rejected submission must come back with what was typed still in it. */
$r = req('/employee-form/save', good_post($csrf, ['email' => 'bad-email']), true);
ok('a rejected submission keeps the typed values', strpos($r['body'], 'Sana Khan') !== false);
ok('and keeps the ones it did accept', strpos($r['body'], 'Accounts Officer') !== false);

$r = req('/employee-form/save', good_post($csrf, ['full_name' => str_repeat('A', 400),
    'position_title' => str_repeat('B', 400)]), true);
ok('an over-long value is trimmed rather than crashing the save',
    stripos($r['body'], 'could not be saved') === false);
$r = req('/employee-form');
ok('and that application really is stored', strpos($r['body'], str_repeat('A', 200)) !== false);
$id = 1;

/* ================================================================== the upload = */

echo "\n=== CV upload ===\n";

$r = req('/employee-form/cv/' . $id);
ok('the first application has no CV yet', $r['code'] === 404, 'code ' . $r['code']);

$r = req('/employee-form', null);
$csrf = csrf_from($r['body']);
$r = req('/employee-form/save', good_post($csrf, [
    'full_name' => 'Bilal Ahmed',
    'email' => 'bilal@example.test',
    'position_title' => 'Support Engineer',
    'skills' => "Networking\nLinux",
]) + ['cv' => new CURLFile($pdfPath, 'application/pdf', 'bilal-cv.pdf')], true);
ok('an application with a real PDF CV saves', stripos($r['body'], 'Bilal Ahmed') !== false);
ok('and the flash says so', stripos($r['body'], 'Application submitted for Bilal Ahmed') !== false);
$id = 2;

$r = req('/employee-form/cv/' . $id);
ok('the CV comes back over HTTP', $r['code'] === 200, 'code ' . $r['code']);
ok('it is served as a PDF', stripos($r['head'], 'Content-Type: application/pdf') !== false);
ok('under the name the applicant chose', strpos($r['head'], 'bilal-cv.pdf') !== false);
ok('and it is the real bytes', strpos($r['body'], '%PDF-1.4') === 0);
ok('it is marked no-sniff', stripos($r['head'], 'X-Content-Type-Options: nosniff') !== false);

$stored = glob($tmp . '/employee_form_cvs/*') ?: [];
ok('the file on disk is not the name the browser sent',
    count($stored) === 1 && strpos(basename($stored[0]), 'bilal-cv.pdf') === false,
    implode(',', array_map('basename', $stored)));
ok('the stored name is generated per submission',
    count($stored) === 1 && strpos(basename($stored[0]), 'cv-2-') === 0,
    implode(',', array_map('basename', $stored)));

$r = req('/employee-form', null);
$csrf = csrf_from($r['body']);
$r = req('/employee-form/save', good_post($csrf, [
    'full_name' => 'Omar Farooq',
    'email' => 'omar@example.test',
    'position_title' => 'Data Analyst',
]) + ['cv' => new CURLFile($evilPath, 'application/pdf', 'cv.pdf')], true);
ok('a PHP file wearing a .pdf name is refused on content',
    stripos($r['body'], 'not really a PDF file') !== false);
ok('but the application itself is still saved', stripos($r['body'], 'Omar Farooq') !== false);
$r = req('/employee-form/cv/3');
ok('and nothing executable was stored for it', $r['code'] === 404, 'code ' . $r['code']);
$r = req('/employee-form/cvs/cv-2');
ok('the storage directory is not reachable over HTTP', $r['code'] === 404, 'code ' . $r['code']);

/* =========================================================== the submissions = */

echo "\n=== submissions are visible ===\n";

$r = req('/employee-form');
$html = $r['body'];
ok('all three applications are listed', substr_count($html, 'Open</a>') === 3,
    'found ' . substr_count($html, 'Open</a>'));
ok('the newest is first', strpos($html, 'Omar Farooq') < strpos($html, 'Bilal Ahmed'));
ok('the pipeline status is shown on each row', substr_count($html, '>Submitted<') === 3,
    'found ' . substr_count($html, '>Submitted<'));
ok('the stored CV is linked from the list', strpos($html, '/employee-form/cv/2') !== false);

$r = req('/employee-form/2');
$html = $r['body'];
ok('a single application opens', $r['code'] === 200, 'code ' . $r['code']);
ok('it shows the submitted name', strpos($html, 'Bilal Ahmed') !== false);
ok('it shows the position applied for', strpos($html, 'Support Engineer') !== false);
ok('the multi-line skills survived the round trip', strpos($html, 'Networking') !== false
    && strpos($html, 'Linux') !== false);
ok('it shows the contact details', strpos($html, 'bilal@example.test') !== false);
ok('it records who entered it', strpos($html, 'admin@workfolio.com') !== false);
ok('the status control is on it', strpos($html, '/employee-form/2/status') !== false);
ok('an empty optional field shows as a dash, not as "null"', strpos($html, '>null<') === false);

$r = req('/employee-form/999');
ok('a missing application redirects to the list', $r['code'] === 302, 'code ' . $r['code']);

/* ================================================================== the status = */

echo "\n=== moving an application along ===\n";

$r = req('/employee-form/2', null);
$csrf = csrf_from($r['body']);
$r = req('/employee-form/2/status', ['_csrf' => $csrf, 'status' => 'SHORTLISTED',
    'remarks' => 'Strong SQL. Move to panel.']);
ok('the status change is accepted', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/employee-form/2');
ok('the new status is on the record', stripos($r['body'], '>Shortlisted<') !== false);
ok('the remarks are kept', strpos($r['body'], 'Strong SQL') !== false);

$r = req('/employee-form', null);
$csrf = csrf_from($r['body']);
$r = req('/employee-form/2/status', ['_csrf' => $csrf, 'status' => 'FIRED'], true);
ok('an invented status is refused', stripos($r['body'], 'not a hiring status') !== false);
$r = req('/employee-form/2');
ok('and the real status is untouched', stripos($r['body'], '>Shortlisted<') !== false);

$r = req('/employee-form', null);
$csrf = csrf_from($r['body']);
$r = req('/employee-form/2/status', ['_csrf' => str_repeat('f', 32), 'status' => 'HIRED']);
ok('a status change with a wrong CSRF is refused', $r['code'] === 419, 'code ' . $r['code']);

$r = req('/employee-form?status=SHORTLISTED');
ok('the status filter shows only that pipeline stage',
    substr_count($r['body'], 'Open</a>') === 1 && strpos($r['body'], 'Bilal Ahmed') !== false);
ok('and hides the others', strpos($r['body'], 'Omar Farooq') === false);

$r = req('/employee-form?status=NONSENSE');
ok('a nonsense filter falls back to the whole list',
    strpos($r['body'], 'Bilal Ahmed') !== false && strpos($r['body'], 'Omar Farooq') !== false);

$r = req('/employee-form');
ok('the counts on the filter chips reflect the pipeline',
    preg_match('/All\s*3/', $r['body']) === 1, 'All chip did not show 3');

echo "\n" . str_repeat('=', 60) . "\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES") . ": {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);