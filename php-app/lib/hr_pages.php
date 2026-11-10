<?php
/**
 * WorkFolio PHP - HR module screens (Phase 1).
 *
 * Kept in its own file and mounted through hr_route(), which index.php calls as a
 * single hook. That keeps the front controller's switch untouched except for two
 * lines: one require and one call. Every /hr URL (never /hrm) is answered here.
 *
 * Two audiences: HR roles drive the module; an employee sees only their own
 * self-service page. Row-level access reuses access_can_view()/access_visible_
 * employees exactly like the rest of the app, so the visibility rule cannot drift.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';

/* ------------------------------------------------------------------ helpers ---- */

function hr_is_manager(?array $u): bool {
    $r = $u['role'] ?? '';
    return $r === 'SUPER_ADMIN' || $r === 'ADMIN' || $r === 'HR';
}

function hr_tabs(string $active): void {
    $tabs = [
        ['/hr', 'Dashboard', 'bi-speedometer2'],
        ['/hr/org', 'Branches &amp; Departments', 'bi-diagram-3'],
        ['/hr/employees', 'Employees', 'bi-person-vcard'],
        ['/hr/recruitment', 'Recruitment', 'bi-briefcase'],
        ['/hr/onboarding', 'Onboarding', 'bi-person-plus'],
        ['/hr/attendance', 'Attendance', 'bi-calendar-check'],
        ['/hr/leave', 'Leave', 'bi-calendar2-x'],
        ['/hr/documents', 'Documents', 'bi-folder2-open'],
        ['/hr/performance', 'Performance', 'bi-graph-up-arrow'],
        ['/hr/tasks', 'Tasks', 'bi-list-task'],
        ['/hr/payroll', 'Payroll', 'bi-cash-stack'],
        ['/hr/assets', 'Assets', 'bi-box-seam'],
        ['/hr/offboarding', 'Offboarding', 'bi-person-dash'],
        ['/hr/reports', 'Reports', 'bi-clipboard-data'],
        ['/hr/approvals', 'Approvals', 'bi-check2-square'],
        ['/hr/announcements', 'Announcements', 'bi-megaphone'],
        ['/hr/notifications', 'Notifications', 'bi-bell'],
        ['/hr/settings', 'Settings', 'bi-gear'],
    ];
    echo '<ul class="nav nav-pills flex-wrap gap-1 mb-3">';
    foreach ($tabs as $t) {
        $cls = $t[0] === $active ? 'active' : '';
        echo '<li class="nav-item"><a class="nav-link py-1 px-2 small ' . $cls . '" href="' . esc(base_url($t[0])) . '">'
            . '<i class="bi ' . $t[2] . ' me-1"></i>' . $t[1] . '</a></li>';
    }
    echo '</ul>';
}

function hr_visible_emails(array $u): array {
    $out = [];
    foreach (access_visible_employees($u) as $e) $out[] = strtolower((string) $e['email']);
    return $out;
}

function hr_visible_employees(array $u): array {
    return access_visible_employees($u);
}

/** Confirm the target employee exists AND the viewer may see them. */
function hr_require_employee(array $u, int $id): array {
    $emp = employee_find($id);
    if ($emp === null) { http_response_code(404); echo 'Employee not found.'; exit; }
    if (!access_can_view($u, $emp)) { http_response_code(403); echo 'Not allowed.'; exit; }
    return $emp;
}

function hr_date_input($v): string { return esc((string) $v); }

/* ------------------------------------------------------------------- router ---- */

/**
 * Handle every /hr route. Returns true when the request was answered, so the
 * caller exits. /hrm and /hrm/... are deliberately NOT matched.
 */
function hr_route(string $method, string $uri): bool {
    if ($uri !== '/hr' && strpos($uri, '/hr/') !== 0) return false;
    $path = substr($uri, 3); /* '' or '/org' or '/employees/12' ... */

    /* Daily digest: fires once per calendar day on the first /hr page load.
       Needs the notifications table, so it runs only when the DB is seeded. */
    try { hr_notifications_daily(); } catch (Throwable $e) {}

    /* Phase 2: recruitment / ATS owns /hr/recruitment* (defined in its own file). */
    if ($path === '/recruitment' || strpos($path, '/recruitment/') === 0) {
        return hr_recruitment_route($method, $path);
    }

    /* Phase 2: onboarding owns /hr/onboarding* (defined in its own file). */
    if ($path === '/onboarding' || strpos($path, '/onboarding/') === 0) {
        return hr_onboarding_route($method, $path);
    }

    /* Phase 1: employee documents owns /hr/documents* (defined in its own file). */
    if ($path === '/documents' || strpos($path, '/documents/') === 0) {
        return hr_documents_route($method, $path);
    }

    /* Phase 2: performance owns /hr/performance* (defined in its own file). */
    if ($path === '/performance' || strpos($path, '/performance/') === 0) {
        return hr_performance_route($method, $path);
    }

    /* Phase 2: tasks owns /hr/tasks* (defined in its own file). */
    if ($path === '/tasks' || strpos($path, '/tasks/') === 0) {
        return hr_tasks_route($method, $path);
    }

    /* Phase 3: offboarding owns /hr/offboarding* (defined in its own file). */
    if ($path === '/offboarding' || strpos($path, '/offboarding/') === 0) {
        return hr_offboarding_route($method, $path);
    }

    /* Phase 3: assets owns /hr/assets* (defined in its own file). */
    if ($path === '/assets' || strpos($path, '/assets/') === 0) {
        return hr_assets_route($method, $path);
    }

    /* Phase 3: payroll owns /hr/payroll* (defined in its own file). */
    if ($path === '/payroll' || strpos($path, '/payroll/') === 0) {
        return hr_payroll_route($method, $path);
    }

    /* Phase 2: reports owns /hr/reports* (defined in its own file). */
    if ($path === '/reports' || strpos($path, '/reports/') === 0) {
        return hr_reports_route($method, $path);
    }

    /* Phase 3: settings owns /hr/settings* (defined in its own file). */
    if ($path === '/settings' || strpos($path, '/settings/') === 0) {
        return hr_settings_route($method, $path);
    }

    /* Phase 3: configurable approval workflows own /hr/approvals*. */
    if ($path === '/approvals' || strpos($path, '/approvals/') === 0) {
        return hr_approvals_route($method, $path);
    }

    /* GET pages */
    if ($method === 'GET') {
        switch (true) {
            case $path === '':                 page_hr_dashboard(); return true;
            case $path === '/org':             page_hr_org(); return true;
            case $path === '/employees':       page_hr_employees(); return true;
            case $path === '/attendance':      page_hr_attendance(); return true;
            case $path === '/leave':           page_hr_leave(); return true;
            case $path === '/leave/team':      page_hr_leave_team(); return true;
            case $path === '/announcements':   page_hr_announcements(); return true;
            case $path === '/notifications':   page_hr_notifications(); return true;
            case $path === '/me':              page_hr_me(); return true;
            case preg_match('#^/employees/(\d+)$#', $path, $m) === 1:
                page_hr_employee((int) $m[1]); return true;
            case $path === '/notifications/read-all':
                hr_handle_read_all(); return true;
            case preg_match('#^/notifications/read/(\d+)$#', $path, $m) === 1:
                hr_handle_read((int) $m[1]); return true;
        }
        return false;
    }

    /* POST actions */
    if ($method === 'POST') {
        switch (true) {
            case $path === '/org/save':           page_hr_org_save(); return true;
            case $path === '/org/delete':         page_hr_org_delete(); return true;
            case $path === '/holiday/save':       page_hr_holiday_save(); return true;
            case $path === '/holiday/delete':     page_hr_holiday_delete(); return true;
            case preg_match('#^/employees/(\d+)/profile$#', $path, $m) === 1:
                page_hr_employee_profile_save((int) $m[1]); return true;
            case preg_match('#^/employees/(\d+)/attendance$#', $path, $m) === 1:
                page_hr_attendance_save((int) $m[1]); return true;
            case $path === '/leave/apply':        page_hr_leave_apply(); return true;
            case $path === '/leave/type/save':    page_hr_leave_type_save(); return true;
            case $path === '/leave/type/delete':  page_hr_leave_type_delete(); return true;
            case $path === '/leave/balance/save': page_hr_leave_balance_save(); return true;
            case preg_match('#^/leave/(\d+)/decide$#', $path, $m) === 1:
                page_hr_leave_decide((int) $m[1]); return true;
            case preg_match('#^/leave/(\d+)/manager-decide$#', $path, $m) === 1:
                page_hr_leave_manager_decide((int) $m[1]); return true;
            case preg_match('#^/leave/(\d+)/cancel$#', $path, $m) === 1:
                page_hr_leave_cancel((int) $m[1]); return true;
            case $path === '/attendance/save':    page_hr_attendance_day_save(); return true;
            case $path === '/attendance/correction/request': page_hr_correction_request(); return true;
            case preg_match('#^/attendance/correction/(\d+)/decide$#', $path, $m) === 1:
                page_hr_correction_decide((int) $m[1]); return true;
            case $path === '/announcements/save': page_hr_announcement_save(); return true;
        }
        return false;
    }
    return false;
}

/* ----------------------------------------------------------------- dashboard ---- */

function page_hr_dashboard(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $maps = hr_org_maps();
    $fBranch = (int) ($_GET['branch_id'] ?? 0);
    $fDept = (int) ($_GET['department_id'] ?? 0);
    $fDate = (string) ($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate)) $fDate = '';
    $s = hr_dashboard_stats([
        'branch_id' => $fBranch,
        'department_id' => $fDept,
        'date' => $fDate !== '' ? $fDate : today(),
    ]);
    $cards = [
        ['Total Employees', $s['total'], 'bi-people-fill', 'primary', '/hr/employees'],
        ['Active Employees', $s['active'], 'bi-person-check-fill', 'success', '/hr/employees?status=ACTIVE'],
        ['Present Today', $s['present'], 'bi-calendar-check-fill', 'success', '/hr/attendance'],
        ['Absent Today', $s['absent'], 'bi-calendar-x-fill', 'danger', '/hr/attendance'],
        ['Late Employees', $s['late'], 'bi-clock-fill', 'warning', '/hr/attendance'],
        ['On Leave', $s['on_leave'], 'bi-airplane-fill', 'info', '/hr/leave'],
        ['New Joiners (30d)', $s['new_joiners'], 'bi-person-plus-fill', 'primary', '/hr/employees'],
        ['Pending Leave', $s['pending_leave'], 'bi-hourglass-split', 'warning', '/hr/leave?status=PENDING'],
        ['Pending Corrections', $s['pending_corrections'], 'bi-pencil-square', 'warning', '/hr/attendance'],
        ['Pending HR Actions', $s['pending_hr_actions'], 'bi-exclamation-square-fill', 'danger', '/hr/leave?status=PENDING'],
        ['Open Vacancies', $s['open_vacancies'], 'bi-briefcase-fill', 'primary', '/employee-form'],
        ['Active Onboarding', $s['onboarding_active'], 'bi-person-plus-fill', 'primary', '/hr/onboarding'],
        ['Birthdays (Month)', $s['birthdays'], 'bi-gift-fill', 'info', '/hr/employees'],
        ['Anniversaries (Month)', $s['anniversaries'], 'bi-star-fill', 'info', '/hr/employees'],
        ['Expiring Documents', $s['expiring_docs'], 'bi-file-earmark-excel-fill', 'warning', '/hr/documents?expiry=expiring'],
        ['Expiring Contracts', $s['expiring_contracts'], 'bi-file-earmark-x-fill', 'warning', '/hr/employees'],
        ['Pending Reviews', $s['pending_reviews'], 'bi-clipboard-check-fill', 'warning', '/hr/performance/reviews'],
        ['Open Tasks', $s['open_tasks'], 'bi-list-task', 'info', '/hr/tasks'],
        ['Active Offboarding', $s['offboarding_active'], 'bi-person-dash-fill', 'secondary', '/hr/offboarding'],
        ['Assets Assigned', $s['assets_assigned'], 'bi-box-seam-fill', 'info', '/hr/assets'],
        ['On Payroll', $s['payroll_assigned'], 'bi-cash-stack', 'success', '/hr/payroll'],
    ];
    layout_open('HR Dashboard', $u, '/hr');
    hr_tabs('/hr');
    ?>
<div class="mb-3">
  <h4 class="mb-0"><i class="bi bi-people-fill me-2 text-primary"></i>HR Dashboard</h4>
  <div class="small text-muted">Live employee, attendance and leave numbers.</div>
</div>
<form method="get" action="<?= esc(base_url('/hr')) ?>" class="card p-3 mb-4">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1">Branch</label>
      <select class="form-select form-select-sm" name="branch_id">
        <option value="">All branches</option>
        <?php foreach ($maps['branches'] as $bid => $n): ?>
          <option value="<?= (int) $bid ?>" <?= $fBranch === (int) $bid ? 'selected' : '' ?>><?= esc($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1">Department</label>
      <select class="form-select form-select-sm" name="department_id">
        <option value="">All departments</option>
        <?php foreach ($maps['departments'] as $did => $n): ?>
          <option value="<?= (int) $did ?>" <?= $fDept === (int) $did ? 'selected' : '' ?>><?= esc($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1">Date</label>
      <input type="date" class="form-control form-control-sm" name="date" value="<?= esc($fDate !== '' ? $fDate : today()) ?>">
    </div>
    <div class="col-12 col-md-4">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Apply filters</button>
      <?php if ($fBranch || $fDept): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('/hr')) ?>">Clear</a>
      <?php endif; ?>
      <span class="small text-muted ms-1"><?= esc(dt_fmt(today(), 'd M Y')) ?></span>
    </div>
  </div>
</form>
<div class="row g-3 mb-4">
  <?php foreach ($cards as $c): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <a class="text-decoration-none" href="<?= esc(base_url($c[4])) ?>">
      <div class="card p-3 h-100">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="text-muted small"><?= esc($c[0]) ?></div>
            <div class="fs-3 fw-bold"><?= (int) $c[1] ?></div>
          </div>
          <i class="bi <?= esc($c[2]) ?> text-<?= esc($c[3]) ?> fs-3"></i>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if (function_exists('hr_report_headcount_trend') && function_exists('hr_chart_line')):
  $hcTrend = hr_report_headcount_trend(6);
  $attRate = hr_report_attendance_rate(date('Y-m-01'), today());
  $attr = hr_report_attrition(date('Y-m-01'), today());
  $hcLast = $hcTrend ? $hcTrend[count($hcTrend) - 1] : ['hires' => 0, 'exits' => 0];
  $rateLast = $attRate ? (float) $attRate[count($attRate) - 1]['value'] : null;
?>
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-8">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <div class="sec-title mb-0"><i class="bi bi-activity me-1 text-primary"></i>Headcount trend (6 months)</div>
        <a class="small text-decoration-none" href="<?= esc(base_url('/hr/reports')) ?>">Full reports &rarr;</a>
      </div>
      <?= hr_chart_line(array_map(static fn($p) => ['label' => $p['label'], 'value' => $p['headcount']], $hcTrend), '#0d6efd', 150, false, static fn($v) => (string) (int) round((float) $v)) ?>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">This month</div>
      <div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted small">Attendance rate</span><span class="fw-semibold"><?= $rateLast !== null ? esc(rtrim(rtrim(number_format($rateLast, 1), '0'), '.')) . '%' : '&mdash;' ?></span></div>
      <div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted small">Attrition rate</span><span class="fw-semibold"><?= esc(number_format($attr['rate'], 1)) ?>%</span></div>
      <div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted small">New joiners</span><span class="fw-semibold"><?= (int) $hcLast['hires'] ?></span></div>
      <div class="d-flex justify-content-between py-1"><span class="text-muted small">Exits</span><span class="fw-semibold"><?= (int) $hcLast['exits'] ?></span></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2"><i class="bi bi-hourglass-split me-1 text-warning"></i>Pending HR Actions</div>
      <?php $pend = hr_leave_requests(['status' => 'PENDING', 'branch_id' => $fBranch, 'department_id' => $fDept, 'limit' => 8]); ?>
      <?php if ($pend === []): ?>
        <div class="small text-muted">Nothing waiting.</div>
      <?php else: ?>
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pend as $r): $emp = employee_find_by_email((string) $r['email']); ?>
            <tr>
              <td class="small"><?= esc($emp ? employee_full_name($emp) : (string) $r['email']) ?></td>
              <td class="small"><?= esc((string) $r['type_name']) ?></td>
              <td class="small"><?= esc((string) $r['start_date']) ?> &rarr; <?= esc((string) $r['end_date']) ?></td>
              <td class="small"><?= (float) $r['days'] ?></td>
              <td><a class="small" href="<?= esc(base_url('/hr/leave')) ?>">Review</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2"><i class="bi bi-megaphone me-1 text-primary"></i>Recent Announcements</div>
      <?php $anns = hr_announcements(4); ?>
      <?php if ($anns === []): ?>
        <div class="small text-muted">No announcements yet. <a href="<?= esc(base_url('/hr/announcements')) ?>">Post one</a>.</div>
      <?php else: ?>
        <?php foreach ($anns as $a): ?>
          <div class="mb-2">
            <div class="fw-semibold"><?= esc((string) $a['title']) ?></div>
            <div class="small text-muted"><?= esc(dt_fmt((string) $a['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

/* ----------------------------------------------------------------------- org ---- */

function page_hr_org(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $tab = (string) ($_GET['tab'] ?? 'branches');
    layout_open('Branches & Departments', $u, '/hr/org');
    hr_tabs('/hr/org');
    $maps = hr_org_maps();
    ?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0"><i class="bi bi-diagram-3 me-2 text-primary"></i>Organisation</h4>
</div>
<ul class="nav nav-tabs mb-3">
  <?php foreach (['branches' => 'Branches', 'departments' => 'Departments', 'teams' => 'Teams', 'holidays' => 'Holidays'] as $k => $lbl): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= esc($k) ?>"><?= esc($lbl) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'branches'): ?>
  <div class="card p-3">
    <form method="post" action="<?= esc(base_url('/hr/org/save')) ?>" class="row g-2 align-items-end mb-3">
      <?= csrf_field() ?><input type="hidden" name="kind" value="branch">
      <div class="col-12 col-md-3"><label class="form-label small mb-1">Branch Name</label><input class="form-control form-control-sm" name="name" required></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">City</label><input class="form-control form-control-sm" name="city"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Phone</label><input class="form-control form-control-sm" name="phone"></div>
      <div class="col-12 col-md-3"><label class="form-label small mb-1">Address</label><input class="form-control form-control-sm" name="address"></div>
      <div class="col-12 col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg"></i> Add</button></div>
    </form>
    <table class="table table-sm align-middle">
      <thead class="table-light"><tr><th>Name</th><th>City</th><th>Phone</th><th>Address</th><th></th></tr></thead>
      <tbody>
      <?php foreach (hr_branches_all() as $b): ?>
        <tr>
          <td class="fw-semibold"><?= esc((string) $b['name']) ?></td>
          <td class="small"><?= esc((string) $b['city']) ?></td>
          <td class="small"><?= esc((string) $b['phone']) ?></td>
          <td class="small"><?= esc((string) $b['address']) ?></td>
          <td class="text-end">
            <form method="post" action="<?= esc(base_url('/hr/org/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this branch? Employees keep their profile but lose the branch link.')">
              <?= csrf_field() ?><input type="hidden" name="kind" value="branch"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'departments'): ?>
  <div class="card p-3">
    <form method="post" action="<?= esc(base_url('/hr/org/save')) ?>" class="row g-2 align-items-end mb-3">
      <?= csrf_field() ?><input type="hidden" name="kind" value="department">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Department Name</label><input class="form-control form-control-sm" name="name" required></div>
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Head (email)</label><input class="form-control form-control-sm" name="head_email" placeholder="optional"></div>
      <div class="col-12 col-md-4"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add</button></div>
    </form>
    <table class="table table-sm align-middle">
      <thead class="table-light"><tr><th>Name</th><th>Head</th><th>Teams</th><th></th></tr></thead>
      <tbody>
      <?php foreach (hr_departments_all() as $d): $teams = hr_teams_all((int) $d['id']); ?>
        <tr>
          <td class="fw-semibold"><?= esc((string) $d['name']) ?></td>
          <td class="small"><?= esc((string) ($d['head_email'] ?? '')) ?></td>
          <td class="small"><?= count($teams) ?></td>
          <td class="text-end">
            <form method="post" action="<?= esc(base_url('/hr/org/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this department and its teams?')">
              <?= csrf_field() ?><input type="hidden" name="kind" value="department"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'teams'): ?>
  <div class="card p-3">
    <form method="post" action="<?= esc(base_url('/hr/org/save')) ?>" class="row g-2 align-items-end mb-3">
      <?= csrf_field() ?><input type="hidden" name="kind" value="team">
      <div class="col-12 col-md-3"><label class="form-label small mb-1">Team Name</label><input class="form-control form-control-sm" name="name" required></div>
      <div class="col-12 col-md-3"><label class="form-label small mb-1">Department</label>
        <select class="form-select form-select-sm" name="department_id"><option value="">&mdash;</option>
          <?php foreach (hr_departments_all() as $d): ?><option value="<?= (int) $d['id'] ?>"><?= esc((string) $d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-12 col-md-3"><label class="form-label small mb-1">Lead (email)</label><input class="form-control form-control-sm" name="lead_email"></div>
      <div class="col-12 col-md-3"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add</button></div>
    </form>
    <table class="table table-sm align-middle">
      <thead class="table-light"><tr><th>Name</th><th>Department</th><th>Lead</th><th></th></tr></thead>
      <tbody>
      <?php foreach (hr_teams_all() as $t): $dep = (int) ($t['department_id'] ?? 0); ?>
        <tr>
          <td class="fw-semibold"><?= esc((string) $t['name']) ?></td>
          <td class="small"><?= esc($maps['departments'][$dep] ?? '-') ?></td>
          <td class="small"><?= esc((string) ($t['lead_email'] ?? '')) ?></td>
          <td class="text-end">
            <form method="post" action="<?= esc(base_url('/hr/org/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this team?')">
              <?= csrf_field() ?><input type="hidden" name="kind" value="team"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
  <div class="card p-3">
    <form method="post" action="<?= esc(base_url('/hr/holiday/save')) ?>" class="row g-2 align-items-end mb-3">
      <?= csrf_field() ?>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Date</label><input type="date" class="form-control form-control-sm" name="holiday_date" required></div>
      <div class="col-6 col-md-4"><label class="form-label small mb-1">Holiday Name</label><input class="form-control form-control-sm" name="name" required></div>
      <div class="col-12 col-md-3"><label class="form-label small mb-1">Branch (blank = all)</label>
        <select class="form-select form-select-sm" name="branch_id"><option value="">All branches</option>
          <?php foreach (hr_branches_all() as $b): ?><option value="<?= (int) $b['id'] ?>"><?= esc((string) $b['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-12 col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg"></i> Add</button></div>
    </form>
    <table class="table table-sm align-middle">
      <thead class="table-light"><tr><th>Date</th><th>Name</th><th>Branch</th><th></th></tr></thead>
      <tbody>
      <?php foreach (hr_holidays_all(hr_year()) as $h): $bid = (int) ($h['branch_id'] ?? 0); ?>
        <tr>
          <td class="fw-semibold"><?= esc((string) $h['holiday_date']) ?></td>
          <td><?= esc((string) $h['name']) ?></td>
          <td class="small"><?= esc($bid ? ($maps['branches'][$bid] ?? '-') : 'All') ?></td>
          <td class="text-end">
            <form method="post" action="<?= esc(base_url('/hr/holiday/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this holiday?')">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
    <?php
    layout_close();
}

function page_hr_org_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $kind = (string) ($_POST['kind'] ?? '');
    if ($kind === 'branch') {
        hr_branch_save(null, (string) ($_POST['name'] ?? ''), (string) ($_POST['city'] ?? ''), (string) ($_POST['address'] ?? ''), (string) ($_POST['phone'] ?? ''));
        activity_log($u, 'HR_BRANCH_SAVED', (string) ($_POST['name'] ?? ''));
    } elseif ($kind === 'department') {
        hr_department_save(null, (string) ($_POST['name'] ?? ''), (string) ($_POST['head_email'] ?? ''));
        activity_log($u, 'HR_DEPARTMENT_SAVED', (string) ($_POST['name'] ?? ''));
    } elseif ($kind === 'team') {
        hr_team_save(null, (string) ($_POST['name'] ?? ''), hr_int_or_null($_POST['department_id'] ?? null), (string) ($_POST['lead_email'] ?? ''));
        activity_log($u, 'HR_TEAM_SAVED', (string) ($_POST['name'] ?? ''));
    }
    flash('success', 'Saved.');
    redirect(base_url('/hr/org?tab=' . ($kind === 'team' ? 'teams' : $kind . 's')));
}

function page_hr_org_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $kind = (string) ($_POST['kind'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($kind === 'branch') hr_branch_delete($id);
    elseif ($kind === 'department') hr_department_delete($id);
    elseif ($kind === 'team') hr_team_delete($id);
    activity_log($u, 'HR_ORG_DELETED', $kind . ' #' . $id);
    flash('success', 'Deleted.');
    redirect(base_url('/hr/org'));
}

function page_hr_holiday_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    hr_holiday_save(null, (string) ($_POST['holiday_date'] ?? ''), (string) ($_POST['name'] ?? ''), hr_int_or_null($_POST['branch_id'] ?? null));
    activity_log($u, 'HR_HOLIDAY_SAVED', (string) ($_POST['name'] ?? ''));
    flash('success', 'Holiday added.');
    redirect(base_url('/hr/org?tab=holidays'));
}

function page_hr_holiday_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    hr_holiday_delete((int) ($_POST['id'] ?? 0));
    flash('success', 'Holiday removed.');
    redirect(base_url('/hr/org?tab=holidays'));
}

/* ----------------------------------------------------------------- employees ---- */

function page_hr_employees(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $profiles = hr_profiles_by_email();
    $maps = hr_org_maps();
    $fDept = (int) ($_GET['department_id'] ?? 0);
    $fBranch = (int) ($_GET['branch_id'] ?? 0);
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $q = strtolower(trim((string) ($_GET['q'] ?? '')));

    $list = [];
    foreach (hr_visible_employees($u) as $e) {
        $email = strtolower((string) $e['email']);
        $p = $profiles[$email] ?? [];
        if ($fDept && (int) ($p['department_id'] ?? 0) !== $fDept) continue;
        if ($fBranch && (int) ($p['branch_id'] ?? 0) !== $fBranch) continue;
        $st = strtoupper((string) ($p['employment_status'] ?? 'ACTIVE'));
        if ($fStatus && $st !== $fStatus) continue;
        if ($q !== '') {
            $hay = $email . ' ' . employee_full_name($e) . ' ' . (string) ($e['designation'] ?? '');
            if (strpos(strtolower($hay), $q) === false) continue;
        }
        $list[] = ['emp' => $e, 'p' => $p];
    }

    layout_open('Employees', $u, '/hr/employees');
    hr_tabs('/hr/employees');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h4 class="mb-0"><i class="bi bi-person-vcard me-2 text-primary"></i>Employee Records</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/employees')) ?>"><i class="bi bi-person-plus me-1"></i>Add via Employees</a>
</div>
<form class="card p-3 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= esc($q) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Department</label>
      <select class="form-select form-select-sm" name="department_id"><option value="">All</option>
        <?php foreach ($maps['departments'] as $id => $n): ?><option value="<?= (int) $id ?>" <?= $fDept === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Branch</label>
      <select class="form-select form-select-sm" name="branch_id"><option value="">All</option>
        <?php foreach ($maps['branches'] as $id => $n): ?><option value="<?= (int) $id ?>" <?= $fBranch === (int) $id ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><option value="">All</option>
        <?php foreach (hr_employment_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12 col-md-3"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('/hr/employees')) ?>">Reset</a></div>
  </div>
</form>
<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Employee</th><th>Code</th><th>Department</th><th>Branch</th><th>Designation</th><th>Status</th><th>Manager</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $row): $e = $row['emp']; $p = $row['p']; $dep = (int) ($p['department_id'] ?? 0); $br = (int) ($p['branch_id'] ?? 0); ?>
      <tr>
        <td><div class="fw-semibold"><?= esc(employee_full_name($e)) ?></div><div class="small text-muted"><?= esc((string) $e['email']) ?></div></td>
        <td class="small"><?= esc((string) ($p['employee_code'] ?? '-')) ?></td>
        <td class="small"><?= esc($maps['departments'][$dep] ?? ((string) ($e['department'] ?? '-') ?: '-')) ?></td>
        <td class="small"><?= esc($maps['branches'][$br] ?? '-') ?></td>
        <td class="small"><?= esc((string) ($e['designation'] ?? '-')) ?></td>
        <td><?= hr_status_badge((string) ($p['employment_status'] ?? 'ACTIVE')) ?></td>
        <td class="small"><?= esc((string) ($p['manager_email'] ?? '-')) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/employees/' . (int) $e['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list === []): ?><tr><td colspan="8" class="text-center text-muted py-3">No employees match.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function page_hr_employee(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $emp = hr_require_employee($u, $id);
    $email = strtolower((string) $emp['email']);
    $p = hr_profile($email);
    $maps = hr_org_maps();
    $month = (string) ($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

    layout_open('Employee - ' . employee_full_name($emp), $u, '/hr/employees');
    hr_tabs('/hr/employees');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc(employee_full_name($emp)) ?></h4>
    <div class="small text-muted"><?= esc($email) ?> &middot; <?= esc((string) ($emp['designation'] ?? '-')) ?></div>
  </div>
  <div class="d-flex gap-2">
    <?= hr_status_badge((string) ($p['employment_status'] ?? 'ACTIVE')) ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/employees')) ?>">Back</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hrm/' . $id)) ?>">HRM Form</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-6">
    <div class="card p-3 h-100">
      <div class="sec-title mb-3"><i class="bi bi-person-badge me-1 text-primary"></i>HR Profile</div>
      <form method="post" action="<?= esc(base_url('/hr/employees/' . $id . '/profile')) ?>">
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Employee Code</label><input class="form-control form-control-sm" name="employee_code" value="<?= esc((string) ($p['employee_code'] ?? '')) ?>"></div>
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Employment Type</label>
            <select class="form-select form-select-sm" name="employment_type"><option value="">&mdash;</option>
              <?php foreach (hr_employment_types() as $t): ?><option <?= (string) ($p['employment_type'] ?? '') === $t ? 'selected' : '' ?>><?= esc($t) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Status</label>
            <select class="form-select form-select-sm" name="employment_status">
              <?php foreach (hr_employment_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) ($p['employment_status'] ?? 'ACTIVE')) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Branch</label>
            <select class="form-select form-select-sm" name="branch_id"><option value="">&mdash;</option>
              <?php foreach ($maps['branches'] as $bid => $n): ?><option value="<?= (int) $bid ?>" <?= (int) ($p['branch_id'] ?? 0) === (int) $bid ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Department</label>
            <select class="form-select form-select-sm" name="department_id"><option value="">&mdash;</option>
              <?php foreach ($maps['departments'] as $did => $n): ?><option value="<?= (int) $did ?>" <?= (int) ($p['department_id'] ?? 0) === (int) $did ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6 col-md-4"><label class="form-label small mb-1">Team</label>
            <select class="form-select form-select-sm" name="team_id"><option value="">&mdash;</option>
              <?php foreach ($maps['teams'] as $tid => $n): ?><option value="<?= (int) $tid ?>" <?= (int) ($p['team_id'] ?? 0) === (int) $tid ? 'selected' : '' ?>><?= esc($n) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-12 col-md-6"><label class="form-label small mb-1">Reporting Manager (email)</label><input class="form-control form-control-sm" name="manager_email" value="<?= esc((string) ($p['manager_email'] ?? '')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Date of Birth</label><input type="date" class="form-control form-control-sm" name="date_of_birth" value="<?= esc((string) ($p['date_of_birth'] ?? '')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Gender</label><input class="form-control form-control-sm" name="gender" value="<?= esc((string) ($p['gender'] ?? '')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Marital Status</label><input class="form-control form-control-sm" name="marital_status" value="<?= esc((string) ($p['marital_status'] ?? '')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">CNIC</label><input class="form-control form-control-sm" name="cnic" value="<?= esc((string) ($p['cnic'] ?? '')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Contract End</label><input type="date" class="form-control form-control-sm" name="contract_end" value="<?= esc((string) ($p['contract_end'] ?? '')) ?>"></div>
        </div>
        <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-save me-1"></i>Save profile</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-xl-6">
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0"><i class="bi bi-calendar-check me-1 text-primary"></i>Attendance</div>
        <form class="d-flex gap-1" method="get">
          <input type="month" class="form-control form-control-sm" name="month" value="<?= esc($month) ?>" onchange="this.form.submit()">
        </form>
      </div>
      <?php $att = hr_attendance_month($email, $month); $sumWork = 0; foreach ($att as $a) $sumWork += (int) $a['worked_seconds']; ?>
      <div class="small text-muted mb-2"><?= count($att) ?> day(s) recorded &middot; total <?= esc(fmt_dur($sumWork)) ?></div>
      <div class="table-responsive" style="max-height:320px;overflow:auto">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Date</th><th>In</th><th>Out</th><th>Worked</th><th>Late</th><th>OT</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($att as $a): ?>
          <tr>
            <td class="small"><?= esc((string) $a['work_date']) ?></td>
            <td class="small"><?= esc(hhmm_a((string) ($a['check_in'] ?? ''))) ?></td>
            <td class="small"><?= esc(hhmm_a((string) ($a['check_out'] ?? ''))) ?></td>
            <td class="small"><?= esc(fmt_dur((int) $a['worked_seconds'])) ?></td>
            <td class="small"><?= (int) $a['late_minutes'] ? (int) $a['late_minutes'] . 'm' : '-' ?></td>
            <td class="small"><?= (int) $a['overtime_minutes'] ? (int) $a['overtime_minutes'] . 'm' : '-' ?></td>
            <td><?= hr_attendance_status_badge((string) $a['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <details class="mt-2">
        <summary class="small">Manual attendance entry / correction</summary>
        <form method="post" action="<?= esc(base_url('/hr/employees/' . $id . '/attendance')) ?>" class="row g-2 mt-1">
          <?= csrf_field() ?>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Date</label><input type="date" class="form-control form-control-sm" name="work_date" value="<?= esc(today()) ?>" required></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
            <select class="form-select form-select-sm" name="status"><?php foreach (['PRESENT','WFH','HALF_DAY','LEAVE','ABSENT'] as $s): ?><option><?= esc($s) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Check In</label><input type="datetime-local" class="form-control form-control-sm" name="check_in"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Check Out</label><input type="datetime-local" class="form-control form-control-sm" name="check_out"></div>
          <div class="col-12"><label class="form-label small mb-1">Note</label><input class="form-control form-control-sm" name="note"></div>
          <div class="col-12"><button class="btn btn-outline-primary btn-sm">Save attendance</button></div>
        </form>
      </details>
    </div>

    <div class="card p-3">
      <div class="sec-title mb-2"><i class="bi bi-calendar2-x me-1 text-primary"></i>Leave Balances <?= date('Y') ?></div>
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Type</th><th>Entitled</th><th>Used</th><th>Remaining</th></tr></thead>
        <tbody>
        <?php foreach (hr_leave_balances($email) as $b): $ent = (float) $b['balance']['entitled_days']; $used = (float) $b['balance']['used_days']; ?>
          <tr>
            <td class="small"><?= esc((string) $b['type']['name']) ?></td>
            <td class="small"><?= $ent ?></td>
            <td class="small"><?= $used ?></td>
            <td class="small fw-semibold"><?= max(0, $ent - $used) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="small text-muted mt-2">Manual adjustment</div>
      <form method="post" action="<?= esc(base_url('/hr/leave/balance/save')) ?>" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="email" value="<?= esc($email) ?>">
        <div class="col-5"><select class="form-select form-select-sm" name="leave_type_id">
          <?php foreach (hr_leave_types_all() as $t): ?><option value="<?= (int) $t['id'] ?>"><?= esc((string) $t['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-4"><input class="form-control form-control-sm" name="entitled_days" placeholder="Entitled days" type="number" step="0.5"></div>
        <div class="col-3"><button class="btn btn-outline-secondary btn-sm w-100">Set</button></div>
      </form>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

function page_hr_employee_profile_save(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $emp = hr_require_employee($u, $id);
    hr_profile_save((string) $emp['email'], $_POST, (string) $u['email']);
    activity_log($u, 'HR_PROFILE_SAVED', 'Updated HR profile for ' . $emp['email']);
    flash('success', 'Profile saved.');
    redirect(base_url('/hr/employees/' . $id));
}

function page_hr_attendance_save(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $emp = hr_require_employee($u, $id);
    $in = (string) ($_POST['check_in'] ?? '');
    $out = (string) ($_POST['check_out'] ?? '');
    if ($in !== '') $in = str_replace('T', ' ', $in) . (strlen($in) === 16 ? ':00' : '');
    if ($out !== '') $out = str_replace('T', ' ', $out) . (strlen($out) === 16 ? ':00' : '');
    hr_attendance_save((string) $emp['email'], (string) ($_POST['work_date'] ?? today()), [
        'status' => (string) ($_POST['status'] ?? 'PRESENT'),
        'check_in' => $in, 'check_out' => $out, 'note' => (string) ($_POST['note'] ?? ''),
    ], (string) $u['email']);
    activity_log($u, 'HR_ATTENDANCE_SAVED', $emp['email'] . ' ' . (string) ($_POST['work_date'] ?? ''));
    flash('success', 'Attendance saved.');
    redirect(base_url('/hr/employees/' . $id . '?month=' . substr((string) ($_POST['work_date'] ?? today()), 0, 7)));
}

/* ------------------------------------------------------------------- attendance ---- */

function page_hr_attendance(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $date = (string) ($_GET['date'] ?? today());
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = today();
    $emails = hr_visible_emails($u);
    hr_attendance_sync_day_all($date, $emails);
    $dayRows = hr_attendance_by_date($date);
    $maps = hr_org_maps();
    $statCounts = ['PRESENT' => 0, 'ABSENT' => 0, 'LATE' => 0, 'LEAVE' => 0];
    foreach ($dayRows as $r) {
        $st = strtoupper((string) $r['status']);
        if (in_array($st, ['PRESENT', 'WFH', 'HALF_DAY'], true)) $statCounts['PRESENT']++;
        if ($st === 'ABSENT') $statCounts['ABSENT']++;
        if ($st === 'LEAVE') $statCounts['LEAVE']++;
        if ((int) $r['late_minutes'] > 0) $statCounts['LATE']++;
    }
    $corrections = hr_attendance_corrections(['status' => 'PENDING']);

    layout_open('Attendance', $u, '/hr/attendance');
    hr_tabs('/hr/attendance');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-calendar-check me-2 text-primary"></i>Attendance</h4>
  <form method="get" class="d-flex gap-2">
    <input type="date" class="form-control form-control-sm" name="date" value="<?= esc($date) ?>" onchange="this.form.submit()">
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/attendance')) ?>">Today</a>
  </form>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Present</div><div class="fs-4 fw-bold text-success"><?= $statCounts['PRESENT'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Absent</div><div class="fs-4 fw-bold text-danger"><?= $statCounts['ABSENT'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Late</div><div class="fs-4 fw-bold text-warning"><?= $statCounts['LATE'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">On Leave</div><div class="fs-4 fw-bold text-info"><?= $statCounts['LEAVE'] ?></div></div></div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Daily Attendance &mdash; <?= esc($date) ?></div>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Employee</th><th>Department</th><th>In</th><th>Out</th><th>Worked</th><th>Late</th><th>Early</th><th>OT</th><th>Status</th><th>Source</th></tr></thead>
    <tbody>
    <?php foreach ($dayRows as $r): $dep = (int) ($r['department_id'] ?? 0); $name = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']); ?>
      <tr>
        <td class="small fw-semibold"><?= esc($name) ?><div class="text-muted"><?= esc((string) $r['email']) ?></div></td>
        <td class="small"><?= esc($maps['departments'][$dep] ?? '-') ?></td>
        <td class="small"><?= esc(hhmm_a((string) ($r['check_in'] ?? ''))) ?></td>
        <td class="small"><?= esc(hhmm_a((string) ($r['check_out'] ?? ''))) ?></td>
        <td class="small"><?= esc(fmt_dur((int) $r['worked_seconds'])) ?></td>
        <td class="small"><?= (int) $r['late_minutes'] ? (int) $r['late_minutes'] . 'm' : '-' ?></td>
        <td class="small"><?= (int) $r['early_minutes'] ? (int) $r['early_minutes'] . 'm' : '-' ?></td>
        <td class="small"><?= (int) $r['overtime_minutes'] ? (int) $r['overtime_minutes'] . 'm' : '-' ?></td>
        <td><?= hr_attendance_status_badge((string) $r['status']) ?></td>
        <td class="small text-muted"><?= esc((string) $r['source']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($dayRows === []): ?><tr><td colspan="10" class="text-center text-muted py-3">No attendance recorded for this date.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card p-3">
  <div class="sec-title mb-2"><i class="bi bi-pencil-square me-1 text-warning"></i>Pending Correction Requests</div>
  <?php if ($corrections === []): ?>
    <div class="small text-muted">No pending corrections.</div>
  <?php else: ?>
    <table class="table table-sm align-middle">
      <thead class="table-light"><tr><th>Employee</th><th>Date</th><th>Requested In</th><th>Requested Out</th><th>Reason</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($corrections as $c): $name = trim((string) ($c['first_name'] ?? '') . ' ' . (string) ($c['last_name'] ?? '')); ?>
        <tr>
          <td class="small"><?= esc($name ?: (string) $c['email']) ?></td>
          <td class="small"><?= esc((string) $c['work_date']) ?></td>
          <td class="small"><?= esc((string) $c['requested_check_in']) ?></td>
          <td class="small"><?= esc((string) $c['requested_check_out']) ?></td>
          <td class="small"><?= esc((string) $c['reason']) ?></td>
          <td class="text-end">
            <form method="post" action="<?= esc(base_url('/hr/attendance/correction/' . (int) $c['id'] . '/decide')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="approve">
              <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i></button>
            </form>
            <form method="post" action="<?= esc(base_url('/hr/attendance/correction/' . (int) $c['id'] . '/decide')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="reject">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
    <?php
    layout_close();
}

function page_hr_attendance_day_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($email === '') { flash('danger', 'Missing employee.'); redirect(base_url('/hr/attendance')); }
    $in = (string) ($_POST['check_in'] ?? '');
    $out = (string) ($_POST['check_out'] ?? '');
    if ($in !== '') $in = str_replace('T', ' ', $in) . (strlen($in) === 16 ? ':00' : '');
    if ($out !== '') $out = str_replace('T', ' ', $out) . (strlen($out) === 16 ? ':00' : '');
    hr_attendance_save($email, (string) ($_POST['work_date'] ?? today()), [
        'status' => (string) ($_POST['status'] ?? 'PRESENT'), 'check_in' => $in, 'check_out' => $out,
        'note' => (string) ($_POST['note'] ?? ''),
    ], (string) $u['email']);
    activity_log($u, 'HR_ATTENDANCE_SAVED', $email);
    flash('success', 'Attendance saved.');
    redirect(base_url('/hr/attendance'));
}

function page_hr_correction_request(): void {
    $u = require_login();
    require_csrf();
    hr_attendance_correction_request((string) $u['email'], (string) ($_POST['work_date'] ?? today()),
        (string) ($_POST['check_in'] ?? ''), (string) ($_POST['check_out'] ?? ''), (string) ($_POST['reason'] ?? ''));
    hr_notify_roles(['HR', 'ADMIN', 'SUPER_ADMIN'], 'ATTENDANCE_CORRECTION', 'Attendance correction requested',
        employee_full_name($u) . ' requested a correction for ' . (string) ($_POST['work_date'] ?? ''), '/hr/attendance', (string) $u['email']);
    flash('success', 'Correction request submitted.');
    redirect(base_url('/hr/me'));
}

function page_hr_correction_decide(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $approve = (string) ($_POST['action'] ?? '') === 'approve';
    $err = hr_attendance_correction_decide($id, $approve, (string) $u['email'], (string) ($_POST['note'] ?? ''));
    if ($err !== '') flash('danger', $err); else flash('success', $approve ? 'Correction approved.' : 'Correction rejected.');
    redirect(base_url('/hr/attendance'));
}

/* ----------------------------------------------------------------------- leave ---- */

function page_hr_leave(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $fBranch = (int) ($_GET['branch_id'] ?? 0);
    $fDept = (int) ($_GET['department_id'] ?? 0);
    $requests = hr_leave_requests(['status' => $fStatus, 'branch_id' => $fBranch, 'department_id' => $fDept, 'limit' => 200]);
    $types = hr_leave_types_all();
    $maps = hr_org_maps();

    layout_open('Leave Management', $u, '/hr/leave');
    hr_tabs('/hr/leave');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-calendar2-x me-2 text-primary"></i>Leave Management</h4>
  <div class="btn-group btn-group-sm">
    <?php foreach (['' => 'All', 'PENDING' => 'Pending', 'MANAGER_APPROVED' => 'Manager OK', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected'] as $k => $lbl): ?>
      <a class="btn btn-outline-secondary <?= $fStatus === $k ? 'active' : '' ?>" href="?status=<?= esc($k) ?><?= $fBranch ? '&amp;branch_id=' . (int) $fBranch : '' ?><?= $fDept ? '&amp;department_id=' . (int) $fDept : '' ?>"><?= esc($lbl) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<form method="get" action="<?= esc(base_url('/hr/leave')) ?>" class="card p-3 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1">Branch</label>
      <select class="form-select form-select-sm" name="branch_id">
        <option value="">All branches</option>
        <?php foreach ($maps['branches'] as $bid => $n): ?>
          <option value="<?= (int) $bid ?>" <?= $fBranch === (int) $bid ? 'selected' : '' ?>><?= esc($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1">Department</label>
      <select class="form-select form-select-sm" name="department_id">
        <option value="">All departments</option>
        <?php foreach ($maps['departments'] as $did => $n): ?>
          <option value="<?= (int) $did ?>" <?= $fDept === (int) $did ? 'selected' : '' ?>><?= esc($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-6">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Apply filters</button>
      <?php if ($fBranch || $fDept): ?><a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('/hr/leave')) ?>">Clear</a><?php endif; ?>
    </div>
  </div>
</form>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Leave Requests</div>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Applied</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): $emp = employee_find_by_email((string) $r['email']); $name = $emp ? employee_full_name($emp) : (string) $r['email']; ?>
      <tr>
        <td class="small fw-semibold"><?= esc($name) ?></td>
        <td class="small"><?= esc((string) $r['type_name']) ?></td>
        <td class="small"><?= esc((string) $r['start_date']) ?></td>
        <td class="small"><?= esc((string) $r['end_date']) ?></td>
        <td class="small"><?= (float) $r['days'] ?></td>
        <td class="small text-truncate" style="max-width:220px"><?= esc((string) $r['reason']) ?></td>
        <td><?= hr_leave_status_badge((string) $r['status']) ?></td>
        <td class="small text-muted"><?= esc(dt_fmt((string) $r['applied_at'], 'd M')) ?></td>
        <td class="text-end">
          <?php $st = strtoupper((string) $r['status']); if ($st === 'PENDING' || $st === 'MANAGER_APPROVED'): ?>
            <form method="post" action="<?= esc(base_url('/hr/leave/' . (int) $r['id'] . '/decide')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="approve">
              <button class="btn btn-sm btn-success" title="Approve"><i class="bi bi-check-lg"></i></button>
            </form>
            <form method="post" action="<?= esc(base_url('/hr/leave/' . (int) $r['id'] . '/decide')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="reject">
              <button class="btn btn-sm btn-outline-danger" title="Reject"><i class="bi bi-x-lg"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($requests === []): ?><tr><td colspan="9" class="text-center text-muted py-3">No leave requests.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Leave Types</div>
      <form method="post" action="<?= esc(base_url('/hr/leave/type/save')) ?>" class="row g-2 align-items-end mb-3">
        <?= csrf_field() ?>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" required></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Code</label><input class="form-control form-control-sm" name="code" required></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Days/Year</label><input class="form-control form-control-sm" name="annual_quota" type="number" step="0.5" value="0"></div>
        <div class="col-6 col-md-2"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="paid" value="1" checked id="ltPaid"><label class="form-check-label small" for="ltPaid">Paid</label></div></div>
        <div class="col-12 col-md-3"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add type</button></div>
      </form>
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Type</th><th>Code</th><th>Quota</th><th>Paid</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($types as $t): ?>
          <tr>
            <td class="small fw-semibold"><?= esc((string) $t['name']) ?></td>
            <td class="small"><?= esc((string) $t['code']) ?></td>
            <td class="small"><?= (float) $t['annual_quota'] ?></td>
            <td class="small"><?= (int) $t['paid'] ? 'Yes' : 'No' ?></td>
            <td class="small"><?= (int) $t['active'] ? 'Yes' : 'No' ?></td>
            <td class="text-end">
              <form method="post" action="<?= esc(base_url('/hr/leave/type/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this leave type and all its requests?')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0">Holidays <?= hr_year() ?></div>
        <a class="small" href="<?= esc(base_url('/hr/org?tab=holidays')) ?>">Manage</a>
      </div>
      <?php $hs = hr_holidays_all(hr_year()); ?>
      <?php if ($hs === []): ?><div class="small text-muted">No holidays set.</div><?php else: ?>
        <table class="table table-sm mb-0"><tbody>
        <?php foreach ($hs as $h): ?><tr><td class="small"><?= esc((string) $h['holiday_date']) ?></td><td class="small"><?= esc((string) $h['name']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

function page_hr_leave_apply(): void {
    $u = require_login();
    hr_seed();
    require_csrf();
    $typeId = (int) ($_POST['leave_type_id'] ?? 0);
    $start = (string) ($_POST['start_date'] ?? '');
    $end = (string) ($_POST['end_date'] ?? '');
    if ($typeId <= 0 || $start === '' || $end === '' || strtotime($end) < strtotime($start)) {
        flash('danger', 'Choose a leave type and a valid date range.');
        redirect(base_url('/hr/me'));
    }
    $p = hr_profile((string) $u['email']);
    hr_leave_apply((string) $u['email'], $typeId, $start, $end, (string) ($_POST['reason'] ?? ''), (string) ($p['manager_email'] ?? ''));
    hr_notify_roles(['HR', 'ADMIN', 'SUPER_ADMIN'], 'LEAVE_REQUESTED', 'New leave request',
        employee_full_name($u) . ' applied for leave (' . $start . ' to ' . $end . ')', '/hr/leave', (string) $u['email']);
    flash('success', 'Leave request submitted for approval.');
    redirect(base_url('/hr/me'));
}

function page_hr_leave_type_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    hr_leave_type_save(null, (string) ($_POST['name'] ?? ''), (string) ($_POST['code'] ?? ''),
        (float) ($_POST['annual_quota'] ?? 0), !empty($_POST['paid']), true);
    activity_log($u, 'HR_LEAVE_TYPE_SAVED', (string) ($_POST['name'] ?? ''));
    flash('success', 'Leave type added.');
    redirect(base_url('/hr/leave'));
}

function page_hr_leave_type_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    hr_leave_type_delete((int) ($_POST['id'] ?? 0));
    activity_log($u, 'HR_LEAVE_TYPE_DELETED', (string) ($_POST['id'] ?? ''));
    flash('success', 'Leave type removed.');
    redirect(base_url('/hr/leave'));
}

function page_hr_leave_balance_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $typeId = (int) ($_POST['leave_type_id'] ?? 0);
    $days = (float) ($_POST['entitled_days'] ?? 0);
    if ($email !== '' && $typeId > 0) {
        $b = hr_leave_balance($email, $typeId);
        if ($b !== null) q('UPDATE hr_leave_balances SET entitled_days=?, updated_at=? WHERE id=?', [$days, now_sql(), (int) $b['id']]);
    }
    $emp = employee_find_by_email($email);
    flash('success', 'Leave balance updated.');
    redirect(base_url('/hr/employees/' . ($emp ? (int) $emp['id'] : 0)));
}

function page_hr_leave_decide(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $err = hr_leave_decide($id, $action, (string) $u['email'], (string) ($_POST['note'] ?? ''));
    if ($err !== '') { flash('danger', $err); redirect(base_url('/hr/leave')); }
    $r = hr_leave_request($id);
    if ($r !== null) {
        hr_notify((string) $r['email'], 'LEAVE_DECISION', 'Leave ' . strtolower($action) . 'd',
            'Your leave request for ' . (string) $r['start_date'] . ' was ' . strtolower($action) . 'd.', '/hr/me', (string) $u['email']);
    }
    activity_log($u, 'HR_LEAVE_' . strtoupper($action), 'Request #' . $id);
    flash('success', 'Leave request ' . ($action === 'reject' ? 'rejected' : 'approved') . '.');
    redirect(base_url('/hr/leave'));
}

function page_hr_leave_cancel(int $id): void {
    $u = require_login();
    require_csrf();
    $err = hr_leave_cancel($id, (string) $u['email']);
    if ($err !== '') flash('danger', $err); else flash('success', 'Request cancelled.');
    redirect(base_url('/hr/me'));
}

/* One-step decision taken by the reporting manager on their team's leave. */
function page_hr_leave_manager_decide(int $id): void {
    $u = require_login();
    require_csrf();
    $action = strtolower((string) ($_POST['action'] ?? ''));
    $err = hr_leave_manager_decide($id, $action, (string) $u['email'], (string) ($_POST['note'] ?? ''));
    if ($err !== '') { flash('danger', $err); redirect(base_url('/hr/leave/team')); }
    $r = hr_leave_request($id);
    if ($r !== null) {
        if ($action === 'approve') {
            hr_notify_roles(['HR', 'ADMIN', 'SUPER_ADMIN'], 'LEAVE_MANAGER_APPROVED',
                'Leave now awaits HR approval', employee_full_name($u) . ' approved ' .
                (string) $r['email'] . '\'s leave (' . (string) $r['start_date'] . ' to ' . (string) $r['end_date'] . ').',
                '/hr/leave?id=' . (int) $r['id'], (string) $u['email']);
        }
        hr_notify((string) $r['email'], 'LEAVE_DECISION', 'Leave ' . $action . 'd by manager',
            'Your leave request for ' . (string) $r['start_date'] . ' was ' . $action . 'd by your manager.', '/hr/me', (string) $u['email']);
    }
    activity_log($u, 'HR_LEAVE_MANAGER_' . strtoupper($action), 'Request #' . $id);
    flash('success', 'Leave request ' . ($action === 'reject' ? 'rejected' : 'approved at manager step') . '.');
    redirect(base_url('/hr/leave/team'));
}

/* Team-leave review page: stage-1 approvals owned by the current (manager) user. */
function page_hr_leave_team(): void {
    $u = require_login();
    hr_seed();
    $email = (string) $u['email'];
    $queue = hr_leave_manager_queue($email, true);
    $types = hr_leave_types_all();

    layout_open('Team Leave Approvals', $u, '/hr/leave/team');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Team Leave Approvals</h4>
  <div class="small text-muted"><?= esc(employee_full_name($u)) ?></div>
</div>

<?php if (!$queue && !hr_is_profiled_manager($email)): ?>
  <div class="alert alert-info small">You are not set as a reporting manager for anyone, so there is nothing to approve here.</div>
<?php else: ?>
<div class="card p-3">
  <div class="sec-title mb-2">Waiting on you (or already sent onward to HR)</div>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Step</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($queue as $r): $emp = employee_find_by_email((string) $r['email']); $name = $emp ? employee_full_name($emp) : (string) $r['email'];
        $step = strtoupper((string) $r['status']) === 'MANAGER_APPROVED' ? '<span class="badge bg-info text-dark">Manager OK &middot; awaiting HR</span>' : '<span class="badge bg-warning text-dark">Needs your approval</span>'; ?>
      <tr>
        <td class="small fw-semibold"><?= esc($name) ?></td>
        <td class="small"><?= esc((string) $r['type_name']) ?></td>
        <td class="small"><?= esc((string) $r['start_date']) ?></td>
        <td class="small"><?= esc((string) $r['end_date']) ?></td>
        <td class="small"><?= (float) $r['days'] ?></td>
        <td class="small text-truncate" style="max-width:200px"><?= esc((string) $r['reason']) ?></td>
        <td><?= $step ?></td>
        <td class="text-end">
          <?php if (strtoupper((string) $r['status']) === 'PENDING'): ?>
            <form method="post" action="<?= esc(base_url('/hr/leave/' . (int) $r['id'] . '/manager-decide')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="approve">
              <button class="btn btn-sm btn-success" title="Approve at manager step"><i class="bi bi-check-lg"></i></button>
            </form>
            <form method="post" action="<?= esc(base_url('/hr/leave/' . (int) $r['id'] . '/manager-decide')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="reject">
              <button class="btn btn-sm btn-outline-danger" title="Reject"><i class="bi bi-x-lg"></i></button>
            </form>
          <?php else: ?>
            <span class="small text-muted">With HR</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($queue === []): ?><tr><td colspan="8" class="text-center text-muted py-3">No team leave requests right now.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

    <?php
    layout_close();
}

/* ----------------------------------------------------------------- announcements ---- */

function page_hr_announcements(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $maps = hr_org_maps();
    layout_open('Announcements', $u, '/hr/announcements');
    hr_tabs('/hr/announcements');
    ?>
<h4 class="mb-3"><i class="bi bi-megaphone me-2 text-primary"></i>Announcements</h4>
<div class="card p-3 mb-3">
  <form method="post" action="<?= esc(base_url('/hr/announcements/save')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Title</label><input class="form-control form-control-sm" name="title" required></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Audience</label>
        <select class="form-select form-select-sm" name="audience">
          <option value="ALL">Everyone</option><option value="BRANCH">Branch</option><option value="DEPARTMENT">Department</option>
        </select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Branch</label>
        <select class="form-select form-select-sm" name="branch_id"><option value="">&mdash;</option>
          <?php foreach ($maps['branches'] as $id => $n): ?><option value="<?= (int) $id ?>"><?= esc($n) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Department</label>
        <select class="form-select form-select-sm" name="department_id"><option value="">&mdash;</option>
          <?php foreach ($maps['departments'] as $id => $n): ?><option value="<?= (int) $id ?>"><?= esc($n) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-12"><label class="form-label small mb-1">Message</label><textarea class="form-control form-control-sm" name="body" rows="2"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Post announcement</button></div>
    </div>
  </form>
</div>
<div class="card p-3">
  <?php $anns = hr_announcements(50); ?>
  <?php if ($anns === []): ?><div class="small text-muted">No announcements yet.</div><?php else: ?>
    <?php foreach ($anns as $a): ?>
      <div class="border-bottom pb-2 mb-2">
        <div class="d-flex justify-content-between">
          <div class="fw-semibold"><?= esc((string) $a['title']) ?></div>
          <div class="small text-muted"><?= esc(dt_fmt((string) $a['created_at'])) ?></div>
        </div>
        <div class="small"><?= nl2br(esc((string) $a['body'])) ?></div>
        <div class="small text-muted">Audience: <?= esc((string) $a['audience']) ?> &middot; by <?= esc((string) $a['created_by']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
    <?php
    layout_close();
}

function page_hr_announcement_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title !== '') {
        hr_announcement_save($title, (string) ($_POST['body'] ?? ''), (string) ($_POST['audience'] ?? 'ALL'),
            hr_int_or_null($_POST['branch_id'] ?? null), hr_int_or_null($_POST['department_id'] ?? null), (string) $u['email']);
        hr_notify_roles(['EMPLOYEE', 'HR', 'ADMIN', 'SUPER_ADMIN'], 'ANNOUNCEMENT', $title, (string) ($_POST['body'] ?? ''), '/hr/notifications', (string) $u['email']);
        activity_log($u, 'HR_ANNOUNCEMENT', $title);
    }
    flash('success', 'Announcement posted.');
    redirect(base_url('/hr/announcements'));
}

/* ----------------------------------------------------------------- notifications ---- */

function page_hr_notifications(): void {
    $u = require_login();
    $items = hr_notifications((string) $u['email'], 50);
    layout_open('Notifications', $u, '/hr/notifications');
    if (hr_is_manager($u)) hr_tabs('/hr/notifications');
    ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-bell me-2 text-primary"></i>Notifications</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/notifications/read-all')) ?>">Mark all read</a>
</div>
<div class="card p-3">
  <?php if ($items === []): ?><div class="small text-muted">You have no notifications.</div><?php else: ?>
    <?php foreach ($items as $n): $unread = empty($n['read_at']); ?>
      <a class="d-block text-decoration-none border-bottom py-2 <?= $unread ? 'bg-body-tertiary' : '' ?>"
         href="<?= esc(base_url('/hr/notifications/read/' . (int) $n['id'])) ?>">
        <div class="d-flex justify-content-between">
          <div class="fw-semibold <?= $unread ? '' : 'text-muted' ?>"><?= esc((string) $n['title']) ?></div>
          <div class="small text-muted"><?= esc(dt_fmt((string) $n['created_at'])) ?></div>
        </div>
        <div class="small text-muted"><?= esc((string) $n['body']) ?></div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
    <?php
    layout_close();
}

function hr_handle_read(int $id): void {
    $u = require_login();
    $n = one('SELECT * FROM hr_notifications WHERE id=? AND email=?', [$id, strtolower((string) $u['email'])]);
    hr_notification_read($id, (string) $u['email']);
    $link = ($n !== null && !empty($n['link'])) ? (string) $n['link'] : '/hr/notifications';
    redirect(base_url($link));
}

function hr_handle_read_all(): void {
    $u = require_login();
    hr_notifications_read_all((string) $u['email']);
    flash('success', 'All notifications marked read.');
    redirect(base_url('/hr/notifications'));
}

/* ---------------------------------------------------------------- self service ---- */

function page_hr_me(): void {
    $u = require_login();
    hr_seed();
    $email = (string) $u['email'];
    $p = hr_profile($email);
    $maps = hr_org_maps();
    $month = (string) ($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $att = hr_attendance_month($email, $month);
    $myRequests = hr_leave_requests(['email' => $email, 'limit' => 30]);
    $balances = hr_leave_balances($email);
    $types = hr_leave_types_all(true);
    $anns = hr_announcements(5);

    layout_open('My HR', $u, '/hr/me');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-person-vcard me-2 text-primary"></i>My HR</h4>
    <div class="small text-muted"><?= esc(employee_full_name($u)) ?> &middot; <?= esc($email) ?></div>
  </div>
  <?= hr_status_badge((string) ($p['employment_status'] ?? 'ACTIVE')) ?>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">My Details</div>
      <?php $dep = (int) ($p['department_id'] ?? 0); $br = (int) ($p['branch_id'] ?? 0); ?>
      <div class="small"><span class="text-muted">Code:</span> <?= esc((string) ($p['employee_code'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Department:</span> <?= esc($maps['departments'][$dep] ?? ((string) ($u['department'] ?? '-') ?: '-')) ?></div>
      <div class="small"><span class="text-muted">Branch:</span> <?= esc($maps['branches'][$br] ?? '-') ?></div>
      <div class="small"><span class="text-muted">Designation:</span> <?= esc((string) ($u['designation'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Manager:</span> <?= esc((string) ($p['manager_email'] ?? '-')) ?></div>
    </div>
    <div class="card p-3">
      <div class="sec-title mb-2">Apply for Leave</div>
      <form method="post" action="<?= esc(base_url('/hr/leave/apply')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><select class="form-select form-select-sm" name="leave_type_id" required>
          <option value="">Select leave type</option>
          <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"><?= esc((string) $t['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6"><input type="date" class="form-control form-control-sm" name="start_date" required></div>
        <div class="col-6"><input type="date" class="form-control form-control-sm" name="end_date" required></div>
        <div class="col-12"><textarea class="form-control form-control-sm" name="reason" rows="2" placeholder="Reason (optional)"></textarea></div>
        <div class="col-12"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-send me-1"></i>Submit request</button></div>
      </form>
    </div>
    <?php if (function_exists('hr_onboarding_me_card')) hr_onboarding_me_card($email); ?>
    <?php if (function_exists('hr_documents_me_card')) hr_documents_me_card($email); ?>
    <?php if (function_exists('hr_performance_me_card')) hr_performance_me_card($email); ?>
    <?php if (function_exists('hr_tasks_me_card')) hr_tasks_me_card($email); ?>
    <?php if (function_exists('hr_offboarding_me_card')) hr_offboarding_me_card($email); ?>
    <?php if (function_exists('hr_assets_me_card')) hr_assets_me_card($email); ?>
    <?php if (function_exists('hr_payroll_me_card')) hr_payroll_me_card($email); ?>
  </div>

  <div class="col-12 col-lg-8">
    <?php $notifs = hr_notifications($email, 8); $unread = hr_notifications_unread_count($email); ?>
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0"><i class="bi bi-bell me-1 text-warning"></i>Notifications
          <?php if ($unread > 0): ?><span class="badge bg-danger ms-1"><?= (int) $unread ?></span><?php endif; ?>
        </div>
        <div class="d-flex gap-2">
          <?php if ($unread > 0): ?><a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/notifications/read-all')) ?>">Mark all read</a><?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/notifications')) ?>">View all</a>
        </div>
      </div>
      <?php if ($notifs === []): ?>
        <div class="small text-muted">You have no notifications.</div>
      <?php else: ?>
        <?php foreach ($notifs as $n): $isUnread = empty($n['read_at']); ?>
          <a class="d-block text-decoration-none border-bottom py-1" href="<?= esc(base_url('/hr/notifications/read/' . (int) $n['id'])) ?>">
            <div class="fw-semibold small <?= $isUnread ? '' : 'text-muted' ?>"><?= esc((string) $n['title']) ?></div>
            <div class="small text-muted"><?= esc((string) $n['body']) ?> &middot; <?= esc(dt_fmt((string) $n['created_at'])) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0">My Leave Balances <?= date('Y') ?></div>
      </div>
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Type</th><th>Entitled</th><th>Used</th><th>Remaining</th></tr></thead>
        <tbody>
        <?php foreach ($balances as $b): $ent = (float) $b['balance']['entitled_days']; $used = (float) $b['balance']['used_days']; ?>
          <tr><td class="small"><?= esc((string) $b['type']['name']) ?></td><td class="small"><?= $ent ?></td><td class="small"><?= $used ?></td><td class="small fw-semibold"><?= max(0, $ent - $used) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">My Leave Requests</div>
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($myRequests as $r): $st = strtoupper((string) $r['status']); ?>
          <tr>
            <td class="small"><?= esc((string) $r['type_name']) ?></td>
            <td class="small"><?= esc((string) $r['start_date']) ?></td>
            <td class="small"><?= esc((string) $r['end_date']) ?></td>
            <td class="small"><?= (float) $r['days'] ?></td>
            <td><?= hr_leave_status_badge((string) $r['status']) ?></td>
            <td class="text-end"><?php if ($st === 'PENDING' || $st === 'MANAGER_APPROVED'): ?>
              <form method="post" action="<?= esc(base_url('/hr/leave/' . (int) $r['id'] . '/cancel')) ?>" class="d-inline" onsubmit="return confirm('Cancel this request?')">
                <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Cancel</button>
              </form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($myRequests === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No leave requests yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0">My Attendance</div>
        <form method="get"><input type="month" class="form-control form-control-sm" name="month" value="<?= esc($month) ?>" onchange="this.form.submit()"></form>
      </div>
      <div class="table-responsive" style="max-height:320px;overflow:auto">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Date</th><th>In</th><th>Out</th><th>Worked</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($att as $a): ?>
          <tr><td class="small"><?= esc((string) $a['work_date']) ?></td>
              <td class="small"><?= esc(hhmm_a((string) ($a['check_in'] ?? ''))) ?></td>
              <td class="small"><?= esc(hhmm_a((string) ($a['check_out'] ?? ''))) ?></td>
              <td class="small"><?= esc(fmt_dur((int) $a['worked_seconds'])) ?></td>
              <td><?= hr_attendance_status_badge((string) $a['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($att === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No attendance this month.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
      <details class="mt-2">
        <summary class="small">Request an attendance correction</summary>
        <form method="post" action="<?= esc(base_url('/hr/attendance/correction/request')) ?>" class="row g-2 mt-1">
          <?= csrf_field() ?>
          <div class="col-6 col-md-3"><input type="date" class="form-control form-control-sm" name="work_date" required></div>
          <div class="col-6 col-md-3"><input type="datetime-local" class="form-control form-control-sm" name="check_in"></div>
          <div class="col-6 col-md-3"><input type="datetime-local" class="form-control form-control-sm" name="check_out"></div>
          <div class="col-6 col-md-3"><input class="form-control form-control-sm" name="reason" placeholder="Reason"></div>
          <div class="col-12"><button class="btn btn-outline-primary btn-sm">Submit correction</button></div>
        </form>
      </details>
    </div>

    <?php if ($anns !== []): ?>
    <div class="card p-3">
      <div class="sec-title mb-2"><i class="bi bi-megaphone me-1 text-primary"></i>Announcements</div>
      <?php foreach ($anns as $a): ?>
        <div class="border-bottom pb-1 mb-1">
          <div class="fw-semibold small"><?= esc((string) $a['title']) ?></div>
          <div class="small text-muted"><?= nl2br(esc((string) $a['body'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
    <?php
    layout_close();
}
