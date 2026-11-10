<?php
/**
 * HR module end-to-end smoke test for the Phase 2/3 additions: Performance,
 * Tasks, Assets, Payroll, Offboarding, Reports and Settings.
 *
 * Same harness as test-hr-e2e.php: a throwaway SQLite database behind the
 * built-in PHP server, signed in as the seeded owner. It proves the wiring for
 * every new screen (no fatal/warning in the body) and drives a representative
 * write path per module so the tables really persist.
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-hr-mods-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
$port = 9010 + (getmypid() % 800);
$base = 'http://127.0.0.1:' . $port;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? "  ($extra)" : '') . "\n"; }
}

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/test-hr-seed.php') . ' ' . escapeshellarg($tmp) . ' 2>&1';
$out = shell_exec($cmd);
$info = json_decode((string) $out, true);
if (!is_array($info) || empty($info['emp_id'])) {
    fwrite(STDERR, "seed failed: $out\n");
    exit(1);
}

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
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
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
/** A screen is healthy when it is 200 and free of PHP error leaks. */
function clean_page(array $r): bool {
    if ($r['code'] !== 200) return false;
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Uncaught', 'Deprecated:'] as $bad) {
        if (strpos($r['body'], $bad) !== false) return false;
    }
    return true;
}

/* ----------------------------------------------------------- boot + login ---- */
req('/login');
$r = login_as('admin@workfolio.com', 'admin123');
ok('owner signs in', $r['code'] === 302, 'code ' . $r['code']);
$csrf = csrf_from(req('/hr/org')['body']);
ok('a CSRF token is available', $csrf !== '');

/* ---------------------------------------------------------------- screens ---- */
echo "=== new module screens render cleanly ===\n";
$screens = [
    '/hr/performance', '/hr/performance/cycles', '/hr/performance/reviews', '/hr/performance/me',
    '/hr/tasks', '/hr/tasks/new', '/hr/tasks/me',
    '/hr/assets', '/hr/assets/new', '/hr/assets/categories', '/hr/assets/me',
    '/hr/payroll', '/hr/payroll/structures', '/hr/payroll/runs', '/hr/payroll/runs/new',
    '/hr/payroll/assignments', '/hr/payroll/me',
    '/hr/offboarding', '/hr/offboarding/new', '/hr/offboarding/plans', '/hr/offboarding/me',
    '/hr/reports', '/hr/settings',
];
foreach ($screens as $url) {
    $r = req($url);
    ok("GET $url renders cleanly", clean_page($r), 'code ' . $r['code']);
}

echo "=== a POST without CSRF is refused ===\n";
$r = req('/hr/settings/save', ['company_name' => 'Nope']);
ok('settings save without CSRF is 419', $r['code'] === 419, 'code ' . $r['code']);

/* ------------------------------------------------------------ performance ---- */
echo "=== performance ===\n";
$r = req('/hr/performance/cycle/save', ['_csrf' => $csrf, 'name' => 'H1 2026',
    'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'status' => 'OPEN']);
ok('cycle save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/performance/cycles');
ok('the cycle is listed', strpos($r['body'], 'H1 2026') !== false);
$cycleId = preg_match('#/hr/performance/cycles/(\d+)#', $r['body'], $m) ? (int) $m[1] : 0;
ok('a cycle id was found', $cycleId > 0);
$r = req('/hr/performance/review/generate', ['_csrf' => $csrf, 'cycle_id' => $cycleId]);
ok('review generation redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/performance/reviews');
ok('generated reviews are listed', clean_page($r) && stripos($r['body'], 'ali@folio.test') !== false);

/* ----------------------------------------------------------------- tasks ---- */
echo "=== tasks ===\n";
$r = req('/hr/tasks/save', ['_csrf' => $csrf, 'title' => 'Prepare monthly report',
    'assignee_email' => 'ali@folio.test', 'priority' => 'HIGH', 'status' => 'TODO', 'due_date' => '2026-04-10']);
ok('task save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/tasks');
ok('the task is listed', strpos($r['body'], 'Prepare monthly report') !== false);

/* ---------------------------------------------------------------- assets ---- */
echo "=== assets ===\n";
$r = req('/hr/assets/categories/save', ['_csrf' => $csrf, 'name' => 'Computers', 'code' => 'COMP']);
ok('asset category save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/assets/save', ['_csrf' => $csrf, 'name' => 'Dell Laptop', 'asset_tag' => 'WF-LAP-01',
    'status' => 'AVAILABLE', 'asset_condition' => 'NEW', 'purchase_cost' => '150000']);
ok('asset save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/assets');
ok('the asset is listed', strpos($r['body'], 'WF-LAP-01') !== false);
$assetId = preg_match('#/hr/assets/(\d+)"#', $r['body'], $m) ? (int) $m[1] : 0;
ok('an asset id was found', $assetId > 0);
$r = req('/hr/assets/assign', ['_csrf' => $csrf, 'asset_id' => $assetId, 'email' => 'ali@folio.test', 'condition_out' => 'NEW']);
ok('asset assign redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/assets/' . $assetId);
ok('the asset shows its holder', strpos($r['body'], 'ali@folio.test') !== false);
$r = req('/hr/assets/return', ['_csrf' => $csrf, 'asset_id' => $assetId, 'condition_in' => 'GOOD']);
ok('asset return redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/assets/' . $assetId);
ok('a returned asset is parked as RETURNED', strpos($r['body'], 'Returned') !== false);
ok('HR still sees the assignment history', strpos($r['body'], 'Assignment History') !== false);

/* --------------------------------------------------------------- payroll ---- */
echo "=== payroll ===\n";
$r = req('/hr/payroll/structures/save', ['_csrf' => $csrf, 'name' => 'Standard PK',
    'basic' => '50000', 'house_rent' => '20000', 'medical' => '5000', 'transport' => '5000',
    'other_allowance' => '0', 'tax_percent' => '10', 'eobi' => '500', 'lwp_day_rate' => '2000', 'active' => '1']);
ok('structure save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/payroll/structures');
ok('the structure is listed', strpos($r['body'], 'Standard PK') !== false);
$structId = preg_match('#name="id" value="(\d+)"#', $r['body'], $m) ? (int) $m[1] : 0;
if ($structId === 0) { $structId = 1; }
$r = req('/hr/payroll/assign', ['_csrf' => $csrf, 'email' => 'ali@folio.test', 'structure_id' => $structId]);
ok('salary assignment redirects', $r['code'] === 302, 'code ' . $r['code']);

/* Grant an advance: it must be recovered on the next run. */
$r = req('/hr/payroll/advances/grant', ['_csrf' => $csrf, 'email' => 'ali@folio.test', 'amount' => '5000', 'reason' => 'medical']);
ok('advance grant redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/payroll/advances?status=OUTSTANDING');
ok('the advance is outstanding', strpos($r['body'], 'OUTSTANDING') !== false && strpos($r['body'], 'medical') !== false);
ok('the advances screen renders', $r['code'] === 200 && strpos($r['body'], 'Salary Advances') !== false);

/* Record an absence so the LWP attendance deduction kicks in. */
$r = req('/hr/attendance/save', ['_csrf' => $csrf, 'email' => 'ali@folio.test',
    'work_date' => '2026-03-05', 'status' => 'ABSENT', 'note' => 'sick']);
ok('absence recorded for LWP', $r['code'] === 302, 'code ' . $r['code']);

$r = req('/hr/payroll/runs/generate', ['_csrf' => $csrf, 'period' => '2026-03']);
ok('payroll run generation redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/payroll');
ok('the run is listed', strpos($r['body'], 'March 2026') !== false);
$runId = preg_match('#/hr/payroll/runs/(\d+)#', $r['body'], $m) ? (int) $m[1] : 0;
ok('a run id was found', $runId > 0);
$r = req('/hr/payroll/runs/' . $runId);
ok('the payslip shows an advance recovery column', strpos($r['body'], 'Advance') !== false);
ok('the payslip shows an attendance deduction column', strpos($r['body'], 'Attend') !== false);

/* The Meezan Bank salary file for this run. */
ok('the run offers the Meezan salary file', strpos($r['body'], 'Meezan') !== false);
ok('employees without a bank account are flagged',
    stripos($r['body'], 'no account number on file') !== false);
$r = req('/hr/payroll/runs/' . $runId . '/meezan.csv');
ok('the Meezan salary file downloads', $r['code'] === 200 && strpos($r['body'], 'Beneficiary Name') !== false,
    'code ' . $r['code']);
ok('it narrates the salary period', strpos($r['body'], 'SALARY 2026-03') !== false);
ok('it totals the payments', strpos($r['body'], 'TOTAL') !== false);
/* 5000 advance must have been recovered and marked RECOVERED. */
$r = req('/hr/payroll/advances?status=RECOVERED');
ok('the advance was recovered by the run', strpos($r['body'], 'RECOVERED') !== false && strpos($r['body'], 'medical') !== false);

/* Payroll self-service exposes the detail to the employee. */
$r = req('/hr/payroll/me');
ok('self-service payslip history renders', $r['code'] === 200 && strpos($r['body'], 'Payslip History') !== false);

/* ------------------------------------------------------------ offboarding ---- */
echo "=== offboarding ===\n";
$r = req('/hr/offboarding/plan/create', ['_csrf' => $csrf, 'email' => 'sara@folio.test',
    'offboard_type' => 'RESIGNATION', 'last_working_date' => '2026-04-30']);
ok('offboarding plan create redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/offboarding/plans');
ok('the plan is listed', strpos($r['body'], 'sara@folio.test') !== false);
$planId = preg_match('#/hr/offboarding/plans/(\d+)#', $r['body'], $m) ? (int) $m[1] : 0;
ok('a plan id was found', $planId > 0);
$r = req('/hr/offboarding/plans/' . $planId);
ok('the plan shows its clearance checklist', stripos($r['body'], 'Revoke system access') !== false);

/* -------------------------------------------------------- reports/settings ---- */
echo "=== reports + settings ===\n";
$r = req('/hr/reports');
ok('reports renders its headline', strpos($r['body'], 'Reports &amp; Analytics') !== false || strpos($r['body'], 'Reports & Analytics') !== false);
ok('reports renders the trends section with charts', strpos($r['body'], 'Trends') !== false && strpos($r['body'], '<svg') !== false);
$r = req('/hr');
ok('the dashboard renders a trend chart', strpos($r['body'], '<svg') !== false && stripos($r['body'], 'Headcount trend') !== false);
$r = req('/hr/reports/export?report=analytics_headcount');
ok('the headcount trend export streams CSV', $r['code'] === 200 && strpos($r['body'], 'headcount') !== false, 'code ' . $r['code']);
$r = req('/hr/settings/save', ['_csrf' => $csrf, 'company_name' => 'Workfolio Inc', 'grace_minutes' => '15']);
ok('settings save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/settings');
ok('the setting persisted', strpos($r['body'], 'Workfolio Inc') !== false);

/* The employee self-service view must not leak assignment history, and an
   employee can file a resignation that HR then approves (cross-feature flow). */
echo "=== employee self-service + resignation workflow ===\n";
@unlink($jar);
req('/login');
$r = login_as('ali@folio.test', 'secret123');
ok('the employee signs in', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/assets/me');
ok('the employee asset page renders', clean_page($r), 'code ' . $r['code']);
ok('the employee sees no assignment history', strpos($r['body'], 'Assignment History') === false && strpos($r['body'], '>History<') === false);

$r = req('/hr/offboarding/me');
ok('the employee offboarding page offers a resignation form', $r['code'] === 200 && stripos($r['body'], 'Submit resignation') !== false, 'code ' . $r['code']);
$r = req('/hr/offboarding/me/request', ['_csrf' => csrf_from($r['body']), 'resignation_date' => '2026-05-01',
    'notice_period_days' => '30', 'reason' => 'relocating']);
ok('the employee resignation request is filed', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/offboarding/me');
ok('the employee sees the request awaiting HR', strpos($r['body'], 'with HR for approval') !== false);

/* Back to HR to approve it. */
@unlink($jar);
req('/login');
$r = login_as('admin@workfolio.com', 'admin123');
ok('HR signs back in', $r['code'] === 302, 'code ' . $r['code']);
$csrf = csrf_from(req('/hr/offboarding')['body']);
$r = req('/hr/offboarding');
ok('the overview shows the pending resignation queue', strpos($r['body'], 'Resignation Requests Awaiting Approval') !== false && strpos($r['body'], 'ali@folio.test') !== false);
$r = req('/hr/offboarding/plans?status=REQUESTED');
$reqPlanId = strpos($r['body'], 'ali@folio.test') !== false && preg_match('#/hr/offboarding/plans/(\d+)#', $r['body'], $m) ? (int) $m[1] : 0;
ok('the requested plan id was found', $reqPlanId > 0);
$r = req('/hr/offboarding/plan/approve', ['_csrf' => $csrf, 'id' => $reqPlanId, 'notice_period_days' => '30', 'last_working_date' => '2026-05-31']);
ok('HR approves the resignation', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/offboarding/plans/' . $reqPlanId);
ok('the plan is now in progress', strpos($r['body'], 'In Progress') !== false);
ok('approval stamped the clearance checklist', stripos($r['body'], 'Revoke system access') !== false);
ok('the notice period is shown', strpos($r['body'], '30 days') !== false);

/* A second employee resigns and is rejected. */
@unlink($jar);
req('/login');
login_as('lead@folio.test', 'secret123');
$r = req('/hr/offboarding/me');
$r = req('/hr/offboarding/me/request', ['_csrf' => csrf_from($r['body']), 'resignation_date' => '2026-05-02', 'notice_period_days' => '15', 'reason' => 'new role']);
ok('a second employee files a resignation', $r['code'] === 302, 'code ' . $r['code']);

@unlink($jar);
req('/login');
login_as('admin@workfolio.com', 'admin123');
$csrf = csrf_from(req('/hr/offboarding')['body']);
$r = req('/hr/offboarding/plans?status=REQUESTED');
$leadPlanId = strpos($r['body'], 'lead@folio.test') !== false && preg_match('#/hr/offboarding/plans/(\d+)#', $r['body'], $m) ? (int) $m[1] : 0;
ok('the second requested plan id was found', $leadPlanId > 0);
$r = req('/hr/offboarding/plan/reject', ['_csrf' => $csrf, 'id' => $leadPlanId, 'decision_note' => 'need coverage']);
ok('HR rejects the resignation', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/offboarding/plans/' . $leadPlanId);
ok('the plan shows as rejected with the note', strpos($r['body'], 'Rejected') !== false && strpos($r['body'], 'need coverage') !== false);

/* --------------------------------------------------- approval workflows ---- */
echo "=== configurable approval workflows ===\n";
$csrf = csrf_from(req('/hr/approvals/new')['body']);
$r = req('/hr/approvals');
ok('the approvals inbox renders', clean_page($r) && strpos($r['body'], 'Approvals') !== false, 'code ' . $r['code']);
$r = req('/hr/approvals/workflows/save', ['_csrf' => $csrf, 'name' => 'Expense Approval',
    'request_type' => 'EXPENSE', 'description' => 'Two-step expense signoff', 'active' => '1']);
ok('workflow save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals/workflows');
ok('the workflow is listed', strpos($r['body'], 'Expense Approval') !== false);
$wfId = preg_match('#/hr/approvals/workflows/(\d+)#', $r['body'], $m) ? (int) $m[1] : 0;
ok('a workflow id was found', $wfId > 0);
$r = req('/hr/approvals/workflows/' . $wfId . '/step/save', ['_csrf' => $csrf,
    'approver_kind' => 'EMAIL', 'approver_value' => 'admin@workfolio.com', 'label' => 'Admin review', 'step_no' => '1']);
ok('first step save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals/workflows/' . $wfId . '/step/save', ['_csrf' => $csrf,
    'approver_kind' => 'EMAIL', 'approver_value' => 'admin@workfolio.com', 'label' => 'Final signoff', 'step_no' => '2']);
ok('second step save redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals/workflows/' . $wfId);
ok('the workflow shows both steps in order', strpos($r['body'], 'Admin review') !== false && strpos($r['body'], 'Final signoff') !== false);

/* Raise a request and approve it through both steps. */
$r = req('/hr/approvals/request/create', ['_csrf' => $csrf, 'workflow_id' => (string) $wfId,
    'title' => 'Client visit reimbursement', 'amount' => '25000', 'body' => 'Karachi client visit']);
ok('request create redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals');
ok('the request awaits the approver', strpos($r['body'], 'Client visit reimbursement') !== false);
$reqId = preg_match('#/hr/approvals/(\d+)#', $r['body'], $m) ? (int) $m[1] : 0;
ok('a request id was found', $reqId > 0, 'html had no request link');
$r = req('/hr/approvals/' . $reqId);
ok('the request detail shows its chain', strpos($r['body'], 'Approval chain') !== false && strpos($r['body'], 'Admin review') !== false);
$r = req('/hr/approvals/request/' . $reqId . '/decide', ['_csrf' => $csrf, 'action' => 'approve', 'note' => 'ok']);
ok('first-step approve redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals/' . $reqId);
ok('the request is still pending after step one', strpos($r['body'], 'Pending') !== false);
$r = req('/hr/approvals/request/' . $reqId . '/decide', ['_csrf' => $csrf, 'action' => 'approve', 'note' => 'done']);
ok('final approve redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals/' . $reqId);
ok('the request is now approved', strpos($r['body'], 'Approved') !== false);

/* A second request is rejected at the first step. */
$r = req('/hr/approvals/request/create', ['_csrf' => $csrf, 'workflow_id' => (string) $wfId,
    'title' => 'Team dinner', 'amount' => '9000', 'body' => 'Quarter close']);
ok('the second request create redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals');
$reqId2 = 0;
if (preg_match_all('#/hr/approvals/(\d+)#', $r['body'], $mm)) { $reqId2 = max(array_map('intval', $mm[1])); }
ok('the second request id was found', $reqId2 > 0 && $reqId2 !== $reqId);
$r = req('/hr/approvals/request/' . $reqId2 . '/decide', ['_csrf' => $csrf, 'action' => 'reject', 'note' => 'over budget']);
ok('reject redirects', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/hr/approvals/' . $reqId2);
ok('the request shows as rejected with the note', strpos($r['body'], 'Rejected') !== false && strpos($r['body'], 'over budget') !== false);

/* CSRF gate. */
$r = req('/hr/approvals/workflows/save', ['name' => 'No CSRF']);
ok('workflow save without CSRF is 419', $r['code'] === 419, 'code ' . $r['code']);

/* ------------------------------------------------------- database backup ---- */
echo "=== database backup ===\n";
$r = req('/admin/backups');
ok('the backup screen renders', $r['code'] === 200 && strpos($r['body'], 'Database Backups') !== false,
    'code ' . $r['code']);
$backupCsrf = csrf_from($r['body']);
ok('the backup screen carries a CSRF token', $backupCsrf !== '');
$r = req('/admin/backups/create', []);
ok('create without CSRF is 419', $r['code'] === 419, 'code ' . $r['code']);
$r = req('/admin/backups/create', ['_csrf' => $backupCsrf]);
ok('a backup is created', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/admin/backups');
ok('the new backup is listed',
    preg_match('/wf-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}\.sqlite/', $r['body']) === 1);
$backupName = preg_match('/(wf-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}\.sqlite)/', $r['body'], $bm) ? $bm[1] : '';
ok('its file name was read back', $backupName !== '');
$r = req('/admin/backups/download?name=' . rawurlencode($backupName));
ok('the backup downloads', $r['code'] === 200 && strlen($r['body']) > 1000, 'code ' . $r['code']);
ok('a traversal name is refused',
    req('/admin/backups/download?name=..%2F..%2Fdb.sqlite')['code'] === 404);
$r = req('/admin/backups/restore', ['_csrf' => $backupCsrf, 'name' => $backupName]);
ok('restore is reserved for the owner', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/admin/backups/delete', ['_csrf' => $backupCsrf, 'name' => $backupName]);
ok('the backup deletes', $r['code'] === 302, 'code ' . $r['code']);
$r = req('/admin/backups');
ok('and it is gone from the list', strpos($r['body'], $backupName) === false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
