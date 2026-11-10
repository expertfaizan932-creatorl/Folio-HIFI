<?php
/**
 * HR module end-to-end smoke test over real HTTP.
 *
 * Spins up the built-in PHP server against a throwaway SQLite database, signs
 * in as the seeded owner, then walks every /hr screen and a representative set
 * of POST actions. The point is the wiring: the hr_route() hook answering
 * before the front controller's switch, the new nav group rendering, and the
 * org / leave / attendance flows actually persisting.
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-hr-e2e-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
$port = 8100 + (getmypid() % 900);
$base = 'http://127.0.0.1:' . $port;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

/* Seed the roster in a separate process: the live server keeps its own handle. */
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/test-hr-seed.php') . ' ' . escapeshellarg($tmp) . ' 2>&1';
$out = shell_exec($cmd);
$info = json_decode((string) $out, true);
if (!is_array($info) || empty($info['emp_id'])) {
    fwrite(STDERR, "seed failed: $out\n");
    exit(1);
}
$empId = (int) $info['emp_id'];

$jar = $tmp . '/cookies.txt';

$server = @proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root)
        . ' ' . escapeshellarg($root . '/test-router.php'),
    [1 => ['file', $tmp . '/srv.log', 'a'], 2 => ['file', $tmp . '/srv.log', 'a']],
    $pipes, $root, null
);
register_shutdown_function(function () use ($server, $tmp, $jar) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    @unlink($jar);
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
    fwrite(STDERR, "server did not start\n--- log ---\n" . (string) @file_get_contents($tmp . '/srv.log') . "\n");
    exit(1);
}

function req(string $url, ?array $post = null): array {
    global $jar, $base;
    $ch = curl_init(strpos($url, 'http') === 0 ? $url : $base . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
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

/* Sign-in now carries a CSRF token, so fetch the form first and replay it. */
function login_as(string $email, string $pass): array {
    $form = req('/login');
    return req('/login', ['username' => $email, 'password' => $pass, '_csrf' => csrf_from($form['body'])]);
}

/* Warm up (owner seeding) then confirm the module is gated. */
req('/login');
$r = req('/hr');
ok('anonymous /hr is refused', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);

/* Sign in as the seeded owner. */
$r = login_as('admin@workfolio.com', 'admin123');
ok('owner signs in', $r['code'] === 302, 'code ' . $r['code']);

/* Fetch a fresh CSRF token from a page that carries a form. */
$r = req('/hr/org');
$csrf = csrf_from($r['body']);
ok('a signed-in HR page returns a CSRF token', $csrf !== '');

echo "=== GET screens ===\n";
$pages = [
    '/hr'                => 'HR Dashboard',
    '/hr/org'            => 'Organisation',
    '/hr/employees'      => 'Employee Records',
    '/hr/employees/' . $empId => 'HR Profile',
    '/hr/attendance'     => 'Daily Attendance',
    '/hr/leave'          => 'Leave Management',
    '/hr/announcements'  => 'Announcements',
    '/hr/notifications'  => 'Notifications',
    '/hr/me'             => 'My HR',
];
foreach ($pages as $url => $marker) {
    $r = req($url);
    ok("GET $url renders", $r['code'] === 200 && strpos($r['body'], $marker) !== false,
        'code ' . $r['code'] . ' marker "' . $marker . '"');
}

echo "=== the new HR nav group ===\n";
$r = req('/hr');
ok('sidebar shows the HR group', strpos($r['body'], '>HR</div>') !== false || strpos($r['body'], '>HR<') !== false);
ok('sidebar links to the HR dashboard', strpos($r['body'], 'href="/hr"') !== false);

echo "=== organisation CRUD ===\n";
$r = req('/hr/org/save', ['_csrf' => $csrf, 'kind' => 'branch', 'name' => 'Head Office', 'city' => 'Lahore', 'phone' => '042', 'address' => 'Main Blvd']);
ok('branch save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/org?tab=branches');
ok('the branch is listed', strpos($r['body'], 'Head Office') !== false);

$r = req('/hr/org/save', ['_csrf' => $csrf, 'kind' => 'department', 'name' => 'Engineering', 'head_email' => 'lead@folio.test']);
ok('department save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/org?tab=departments');
ok('the department is listed', strpos($r['body'], 'Engineering') !== false);

$r = req('/hr/org/save', ['_csrf' => $csrf, 'kind' => 'team', 'name' => 'Platform', 'department_id' => '1', 'lead_email' => 'lead@folio.test']);
ok('team save redirects', $r['code'] === 302, 'code ' . $r['code']);

$r = req('/hr/holiday/save', ['_csrf' => $csrf, 'holiday_date' => '2026-03-20', 'name' => 'Spring Day', 'branch_id' => '']);
ok('holiday save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/org?tab=holidays');
ok('the holiday is listed', strpos($r['body'], 'Spring Day') !== false);

echo "=== a POST without CSRF is refused ===\n";
$r = req('/hr/org/save', ['kind' => 'branch', 'name' => 'Nope']);
ok('org save without CSRF is 419', $r['code'] === 419, 'code ' . $r['code']);

echo "=== employee profile + attendance ===\n";
$r = req('/hr/employees/' . $empId . '/profile', [
    '_csrf' => $csrf, 'employee_code' => 'WF-001', 'employment_type' => 'Full Time',
    'employment_status' => 'ACTIVE', 'department_id' => '1', 'branch_id' => '1',
    'manager_email' => 'lead@folio.test', 'date_of_birth' => '1995-04-02', 'gender' => 'Male',
]);
ok('profile save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/employees/' . $empId);
ok('the profile is shown', strpos($r['body'], 'WF-001') !== false);

$r = req('/hr/employees/' . $empId . '/attendance', [
    '_csrf' => $csrf, 'work_date' => '2026-03-02', 'status' => 'PRESENT',
    'check_in' => '2026-03-02T09:00', 'check_out' => '2026-03-02T18:00', 'note' => 'on site',
]);
ok('attendance save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/attendance?date=2026-03-02');
ok('the attendance row is listed', strpos($r['body'], 'Ali') !== false, 'no employee name row');

echo "=== leave type + announcement ===\n";
$r = req('/hr/leave/type/save', ['_csrf' => $csrf, 'name' => 'Study Leave', 'code' => 'STUDY', 'annual_quota' => '5', 'paid' => '1']);
ok('leave type save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/leave');
ok('the leave type is listed', strpos($r['body'], 'STUDY') !== false);

$r = req('/hr/announcements/save', ['_csrf' => $csrf, 'title' => 'Town Hall', 'body' => 'All hands Friday', 'audience' => 'ALL']);
ok('announcement save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/announcements');
ok('the announcement is listed', strpos($r['body'], 'Town Hall') !== false);

echo "=== self-service leave request + approval ===\n";
/* The owner may apply for their own leave; the request should land in PENDING. */
$r = req('/hr/me');
$r = req('/hr/leave/apply', ['_csrf' => $csrf, 'leave_type_id' => '1', 'start_date' => '2026-04-01', 'end_date' => '2026-04-03', 'reason' => 'family']);
ok('leave apply redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/leave?status=PENDING');
ok('the leave request is pending', strpos($r['body'], 'family') !== false || strpos($r['body'], 'Apr') !== false);

echo "=== manager-approval workflow (employee -> manager -> HR) ===\n";
/* Sign in as Ali (EMPLOYEE, reports to lead@folio.test) and apply. */
$r = req('/logout');
$r = login_as('ali@folio.test', 'secret123');
ok('employee signs in', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/me');
$csrf2 = csrf_from($r['body']);
$r = req('/hr/leave/apply', ['_csrf' => $csrf2, 'leave_type_id' => '1', 'start_date' => '2026-05-11', 'end_date' => '2026-05-12', 'reason' => 'wedding']);
ok('employee leave apply redirects', $r['code'] === 302, 'code ' . $r['code']);
/* An employee cannot open the HR leave-management page... */
$r = req('/hr/leave');
ok('employee is refused the HR leave page', in_array($r['code'], [302, 401, 403], true), 'code ' . $r['code']);
/* ...but can reach their team-leave approvals if they are a manager. */
$r = req('/hr/leave/team');
ok('employee without reports sees the empty-manager notice', $r['code'] === 200 && strpos($r['body'], 'not set as a reporting manager') !== false, 'code ' . $r['code']);

/* Sign in as lead@folio.test (Ali's reporting manager). */
$r = req('/logout');
$r = login_as('lead@folio.test', 'secret123');
ok('manager signs in', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/me');
$csrf3 = csrf_from($r['body']);
$r = req('/hr/leave/team');
ok('manager sees the pending request', strpos($r['body'], 'wedding') !== false, 'no wedding row');
preg_match('#/hr/leave/(\d+)/manager-decide#', $r['body'], $lm);
$lmId = (int) ($lm[1] ?? 0);
ok('a manager-decide form is rendered', $lmId > 0);
$r = req('/hr/leave/' . $lmId . '/manager-decide', ['_csrf' => $csrf3, 'action' => 'approve', 'note' => 'ok from me']);
ok('manager approve redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/leave/team');
ok('the request now awaits HR', strpos($r['body'], 'awaiting HR') !== false, 'not in stage 2');

/* HR/Admin signs in and gives the final approval. */
$r = req('/logout');
$r = login_as('admin@workfolio.com', 'admin123');
ok('HR/admin signs in', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/me');
$csrf4 = csrf_from($r['body']);
$r = req('/hr/leave?status=MANAGER_APPROVED');
ok('the request shows as manager-approved', strpos($r['body'], 'Manager OK') !== false, 'no stage-2 row');
$r = req('/hr/leave/' . $lmId . '/decide', ['_csrf' => $csrf4, 'action' => 'approve', 'note' => 'hq ok']);
ok('HR final approval redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/leave?status=APPROVED');
ok('the request is now approved', strpos($r['body'], 'wedding') !== false, 'no approved row');

/* Ali should find a notification about the decision. */
$r = req('/logout');
$r = login_as('ali@folio.test', 'secret123');
$r = req('/hr/notifications');
ok('employee was notified of the decision', strpos($r['body'], 'Your leave request') !== false, 'no leave decision notice');

/* Back to the HR seat for the rest of the suite. */
$r = req('/logout');
$r = login_as('admin@workfolio.com', 'admin123');
ok('suite returns to the HR seat', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/org');
$csrf = csrf_from($r['body']);
ok('fresh CSRF token is available', $csrf !== '');

echo "=== Phase 2: recruitment / ATS ===\n";
foreach (['/hr/recruitment' => 'Recruitment', '/hr/recruitment/openings' => 'Job Vacancies',
          '/hr/recruitment/candidates' => 'Candidates', '/hr/recruitment/interviews' => 'Interviews'] as $url => $marker) {
    $r = req($url);
    ok("GET $url renders", $r['code'] === 200 && strpos($r['body'], $marker) !== false,
        'code ' . $r['code'] . ' marker "' . $marker . '"');
}

/* Open a vacancy. */
$r = req('/hr/recruitment/opening/save', [
    '_csrf' => $csrf, 'title' => 'Backend Engineer', 'vacancies' => '2', 'status' => 'OPEN',
    'employment_type' => 'Permanent', 'work_location' => 'Lahore', 'salary_min' => '100000', 'salary_max' => '150000',
]);
ok('vacancy save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/openings');
ok('the vacancy is listed', strpos($r['body'], 'Backend Engineer') !== false);
preg_match('#/hr/recruitment/openings/(\d+)#', $r['body'], $om);
$oid = (int) ($om[1] ?? 0);
ok('the vacancy detail opens', $oid > 0 && req('/hr/recruitment/openings/' . $oid)['code'] === 200);

/* A candidate arrives through the existing intake form. */
$r = req('/employee-form/save', [
    '_csrf' => $csrf, 'full_name' => 'Bilal Ahmed', 'email' => 'bilal@folio.test',
    'phone' => '03001234567', 'position_title' => 'Backend Engineer',
]);
ok('candidate intake redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates');
ok('the candidate appears on the board', strpos($r['body'], 'Bilal Ahmed') !== false);
preg_match('#/hr/recruitment/candidates/(\d+)#', $r['body'], $cm);
$cid = (int) ($cm[1] ?? 0);
ok('a candidate id was found', $cid > 0, 'html had no candidate link');

/* Attach, move stage, schedule, offer. */
$r = req('/hr/recruitment/candidate/' . $cid . '/link', ['_csrf' => $csrf, 'opening_id' => (string) $oid]);
ok('candidate link redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidate/' . $cid . '/stage', ['_csrf' => $csrf, 'status' => 'INTERVIEW', 'remarks' => 'good cv']);
ok('stage move redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates/' . $cid);
ok('the candidate shows as In Interview', strpos($r['body'], 'Interview</span>') !== false);
$r = req('/hr/recruitment/candidates?opening_id=' . $oid);
ok('the candidate is attached to the vacancy', strpos($r['body'], 'Bilal Ahmed') !== false);

$r = req('/hr/recruitment/candidate/' . $cid . '/interview', [
    '_csrf' => $csrf, 'round_no' => '1', 'scheduled_at' => '2026-05-01T10:00', 'mode' => 'VIDEO',
    'interviewer' => 'lead@folio.test', 'location' => 'https://meet.example/x', 'notes' => 'round one',
]);
ok('interview scheduling redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates/' . $cid);
file_put_contents(__DIR__ . '/dbg-cand1.html', $r['body']);
ok('the interview is listed', strpos($r['body'], '2026-05-01') !== false);
preg_match('#/hr/recruitment/interview/(\d+)/update#', $r['body'], $im);
$iid = (int) ($im[1] ?? 0);
ok('an interview id was found', $iid > 0, 'html had no interview action');

$r = req('/hr/recruitment/candidate/' . $cid . '/offer', [
    '_csrf' => $csrf, 'position_title' => 'Backend Engineer', 'offered_salary' => '120000',
    'joining_date' => '2026-06-01', 'status' => 'SENT', 'notes' => 'standard package',
]);
ok('offer save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates/' . $cid);
file_put_contents(__DIR__ . '/dbg-cand2.html', $r['body']);
ok('the offer shows as Sent', strpos($r['body'], 'Sent</span>') !== false);

/* Sourcing details: where the candidate came from and who owns them. */
$r = req('/hr/recruitment/candidate/' . $cid . '/meta', [
    '_csrf' => $csrf, 'source' => 'REFERRAL', 'recruiter_email' => 'lead@folio.test',
]);
ok('candidate sourcing save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates/' . $cid);
ok('the candidate shows its source', strpos($r['body'], 'Referral') !== false);
ok('the candidate shows its recruiter', strpos($r['body'], 'lead@folio.test') !== false);
$r = req('/hr/recruitment/candidates?source=REFERRAL');
ok('the source filter lists the candidate', strpos($r['body'], 'Bilal Ahmed') !== false);
$r = req('/hr/recruitment/candidates?source=AGENCY');
ok('the source filter excludes other sources', strpos($r['body'], 'Bilal Ahmed') === false);
$r = req('/hr/recruitment/candidates?recruiter=lead@folio.test');
ok('the recruiter filter lists the candidate', strpos($r['body'], 'Bilal Ahmed') !== false);

/* Communication history. */
$r = req('/hr/recruitment/candidate/' . $cid . '/comm', [
    '_csrf' => $csrf, 'channel' => 'CALL', 'direction' => 'OUT',
    'summary' => 'Intro call', 'body' => 'Discussed the role and notice period.',
]);
ok('communication log redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates/' . $cid);
ok('the communication appears in the history', strpos($r['body'], 'Intro call') !== false);
ok('the comm direction is shown', strpos($r['body'], 'Communication History') !== false);
preg_match('#/hr/recruitment/comm/(\d+)/delete#', $r['body'], $km);
$commId = (int) ($km[1] ?? 0);
ok('a communication id was found', $commId > 0);

/* Close the interview and check the interviews screen picks it up. */
$r = req('/hr/recruitment/interview/' . $iid . '/update', ['_csrf' => $csrf, 'stage' => 'DONE', 'feedback' => 'strong', 'rating' => '5']);
ok('interview update redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates/' . $cid);
ok('interview feedback is shown on the candidate', strpos($r['body'], 'strong') !== false);
$r = req('/hr/recruitment/interviews');
ok('the interviews screen lists the candidate', strpos($r['body'], 'Bilal Ahmed') !== false);

/* Delete the logged communication. */
$r = req('/hr/recruitment/comm/' . $commId . '/delete', ['_csrf' => $csrf]);
ok('communication delete redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/candidates/' . $cid);
ok('the communication is gone', strpos($r['body'], 'Intro call') === false);

/* Vacancy status change. */
$r = req('/hr/recruitment/opening/status', ['_csrf' => $csrf, 'id' => (string) $oid, 'status' => 'CLOSED']);
ok('vacancy status change redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/recruitment/openings/' . $oid);
file_put_contents(__DIR__ . '/dbg-open.html', $r['body']);
ok('the vacancy shows as Closed', strpos($r['body'], 'Closed</span>') !== false);

/* CSRF gate on the new endpoints too. */
$r = req('/hr/recruitment/opening/save', ['title' => 'No CSRF']);
ok('vacancy save without CSRF is 419', $r['code'] === 419, 'code ' . $r['code']);

echo "=== Phase 2: onboarding ===\n";
foreach (['/hr/onboarding' => 'Onboarding', '/hr/onboarding/templates' => 'Onboarding Templates',
          '/hr/onboarding/plans' => 'Onboarding Plans', '/hr/onboarding/new' => 'Start an Onboarding Plan',
          '/hr/onboarding/me' => 'My Onboarding'] as $url => $marker) {
    $r = req($url);
    ok("GET $url renders", $r['code'] === 200 && strpos($r['body'], $marker) !== false,
        'code ' . $r['code'] . ' marker "' . $marker . '"');
}

/* A template carrying one checklist item. */
$r = req('/hr/onboarding/template/save', ['_csrf' => $csrf, 'name' => 'Engineering Onboarding',
    'active' => '1', 'employment_type' => 'Permanent']);
ok('template save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/onboarding/templates');
ok('the template is listed', strpos($r['body'], 'Engineering Onboarding') !== false);
preg_match('#/hr/onboarding/templates/(\d+)#', $r['body'], $tm);
$tid = (int) ($tm[1] ?? 0);
ok('a template id was found', $tid > 0, 'html had no template link');

$r = req('/hr/onboarding/template/task/save', ['_csrf' => $csrf, 'template_id' => (string) $tid,
    'title' => 'Sign contract', 'category' => 'DOCUMENTS', 'owner_role' => 'HR', 'due_offset_days' => '0']);
ok('template item save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/onboarding/templates/' . $tid);
ok('the template item is listed', strpos($r['body'], 'Sign contract') !== false);

/* Starting a plan copies the template's items onto the new hire. */
$r = req('/hr/onboarding/plan/create', ['_csrf' => $csrf, 'email' => 'newhire@folio.test',
    'full_name' => 'New Hire', 'template_id' => (string) $tid, 'joining_date' => '2026-06-01',
    'designation' => 'Engineer', 'employment_type' => 'Permanent']);
ok('plan create redirects', $r['code'] === 302, 'code ' . $r['code']);
preg_match('#Location:\s*(\S+)#i', $r['head'], $lm);
$pid = 0;
if (preg_match('#/hr/onboarding/plans/(\d+)#', (string) ($lm[1] ?? ''), $pm)) $pid = (int) $pm[1];
ok('a plan id was found', $pid > 0, 'location ' . (string) ($lm[1] ?? ''));
$r = req('/hr/onboarding/plans/' . $pid);
ok('the plan shows the copied item', strpos($r['body'], 'Sign contract') !== false);

/* Closing the only item completes the plan on its own. */
preg_match('#/hr/onboarding/plan/task/toggle"[^>]*>\s*<input type="hidden" name="_csrf" value="[a-f0-9]+"><input type="hidden" name="id" value="(\d+)"#s',
    $r['body'], $im);
$taskId = (int) ($im[1] ?? 0);
ok('a plan task id was found', $taskId > 0, 'html had no toggle form');
$r = req('/hr/onboarding/plan/task/toggle', ['_csrf' => $csrf, 'id' => (string) $taskId,
    'plan_id' => (string) $pid, 'status' => 'DONE']);
ok('task toggle redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/onboarding/plans/' . $pid);
ok('the task shows as Done', strpos($r['body'], 'Done</span>') !== false);
ok('the plan auto-completed', strpos($r['body'], 'Completed</span>') !== false);

/* CSRF gate on the new endpoints. */
$r = req('/hr/onboarding/template/save', ['name' => 'No CSRF']);
ok('onboarding save without CSRF is 419', $r['code'] === 419, 'code ' . $r['code']);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
