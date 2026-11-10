<?php
/**
 * WorkFolio PHP - HR module screens: Payroll.
 *
 * Mounted through hr_payroll_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/payroll* path. HR/Admin manage structures,
 * assignments and runs; the employee sees their own payslips at /hr/payroll/me.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';
require_once __DIR__ . '/hr_payroll.php';

/* ------------------------------------------------------------------- router ---- */

function hr_payroll_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/payroll':               hr_page_payroll_overview(); return true;
            case $path === '/payroll/structures':    hr_page_payroll_structures(); return true;
            case $path === '/payroll/runs':          hr_page_payroll_runs(); return true;
            case $path === '/payroll/runs/new':      hr_page_payroll_run_new(); return true;
            case $path === '/payroll/assignments':   hr_page_payroll_assignments(); return true;
            case $path === '/payroll/advances':      hr_page_payroll_advances(); return true;
            case $path === '/payroll/me':            hr_page_payroll_me(); return true;
            case preg_match('#^/payroll/runs/(\d+)/meezan\.csv$#', $path, $m) === 1: hr_payroll_meezan_export((int) $m[1]); return true;
            case preg_match('#^/payroll/runs/(\d+)$#', $path, $m) === 1: hr_page_payroll_run((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/payroll/structures/save':   hr_post_payroll_structure_save(); return true;
            case $path === '/payroll/structures/delete': hr_post_payroll_structure_delete(); return true;
            case $path === '/payroll/assign':            hr_post_payroll_assign(); return true;
            case $path === '/payroll/assign/clear':      hr_post_payroll_assign_clear(); return true;
            case $path === '/payroll/advances/grant':    hr_post_payroll_advance_grant(); return true;
            case $path === '/payroll/advances/cancel':   hr_post_payroll_advance_cancel(); return true;
            case $path === '/payroll/runs/generate':     hr_post_payroll_run_generate(); return true;
            case $path === '/payroll/runs/status':       hr_post_payroll_run_status(); return true;
            case $path === '/payroll/runs/delete':       hr_post_payroll_run_delete(); return true;
            case $path === '/payroll/payslip/save':      hr_post_payroll_payslip_save(); return true;
        }
        return false;
    }
    return false;
}

/* ---------------------------------------------------------------- helpers ---- */

function hp_fmt(string $v): string { return number_format((float) $v, 2); }

function hp_period_label(string $period): string {
    $ts = strtotime($period . '-01');
    return $ts ? date('F Y', $ts) : $period;
}

/** Self-service card for /hr/me: the employee's latest payslip. */
function hr_payroll_me_card(string $email): void {
    $slips = hr_payslips_for_email($email);
    if ($slips === []) return;
    $last = $slips[0];
    ?>
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2"><i class="bi bi-cash-stack me-1 text-primary"></i>My Payroll</div>
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="small text-muted">Latest payslip - <?= esc(hp_period_label((string) $last['period'])) ?></div>
          <div class="fs-5 fw-bold"><?= esc(hp_fmt((string) $last['net'])) ?></div>
        </div>
        <?= hr_payroll_status_badge((string) $last['run_status']) ?>
      </div>
      <a class="btn btn-sm btn-outline-primary mt-2" href="<?= esc(base_url('/hr/payroll/me')) ?>">Open my payslips</a>
    </div>
    <?php
}

/* ---------------------------------------------------------------- overview ---- */

function hr_page_payroll_overview(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $stats = hr_payroll_stats();
    $runs = hr_payroll_runs(['limit' => 8]);

    layout_open('Payroll', $u, '/hr/payroll');
    hr_tabs('/hr/payroll');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-cash-stack me-2 text-primary"></i>Payroll</h4>
    <div class="small text-muted">Salary structures, assignments and monthly runs.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/payroll/runs/new')) ?>"><i class="bi bi-plus-lg me-1"></i>New run</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll/structures')) ?>">Structures</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll/assignments')) ?>">Assignments</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll/advances')) ?>">Advances</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Structures</div><div class="fs-4 fw-bold"><?= (int) $stats['structures'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Employees Assigned</div><div class="fs-4 fw-bold text-primary"><?= (int) $stats['assigned'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Paid Runs</div><div class="fs-4 fw-bold text-success"><?= (int) $stats['paid_runs'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Last Net (<?= esc((string) ($stats['last_period'] ?: '-')) ?>)</div><div class="fs-5 fw-bold"><?= esc(hp_fmt((string) $stats['last_net'])) ?></div></div></div>
</div>

<div class="card p-3 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
    <div class="sec-title mb-0">Salary Advances</div>
    <a class="small" href="<?= esc(base_url('/hr/payroll/advances')) ?>"><?= (int) $stats['advances'] ?> outstanding &middot; <?= esc(hp_fmt((string) $stats['advances_total'])) ?> &middot; Manage</a>
  </div>
  <?php $adv = hr_payroll_advances(['status' => 'OUTSTANDING', 'limit' => 6]); ?>
  <?php if ($adv === []): ?><div class="small text-muted">No outstanding advances. Advances are deducted automatically from the next payroll run.</div><?php else: ?>
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Employee</th><th>Amount</th><th>Reason</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($adv as $a): $an = trim(employee_full_name(['first_name' => (string) ($a['first_name'] ?? ''), 'last_name' => (string) ($a['last_name'] ?? '')])); ?>
        <tr>
          <td class="small"><?= esc($an ?: (string) $a['email']) ?></td>
          <td class="small fw-semibold"><?= esc(hp_fmt((string) $a['amount'])) ?></td>
          <td class="small text-truncate" style="max-width:220px"><?= esc((string) ($a['reason'] ?? '')) ?></td>
          <td><span class="badge bg-warning text-dark">OUTSTANDING</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card p-3">
  <div class="sec-title mb-2">Recent Runs</div>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Period</th><th>Headcount</th><th>Gross</th><th>Net</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td class="small fw-semibold"><?= esc(hp_period_label((string) $r['period'])) ?></td>
        <td class="small"><?= (int) $r['headcount'] ?></td>
        <td class="small"><?= esc(hp_fmt((string) $r['total_gross'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $r['total_net'])) ?></td>
        <td><?= hr_payroll_status_badge((string) $r['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/payroll/runs/' . (int) $r['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($runs === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No runs yet. <a href="<?= esc(base_url('/hr/payroll/runs/new')) ?>">Generate one</a>.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_payroll_structures(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $list = hr_payroll_structures();

    layout_open('Salary Structures', $u, '/hr/payroll');
    hr_tabs('/hr/payroll');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-diagram-3 me-2 text-primary"></i>Salary Structures</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll')) ?>">Back to Payroll</a>
</div>
<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card p-3">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Name</th><th>Basic</th><th>Allowances</th><th>Tax %</th><th>Gross</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $s): $b = hr_payroll_breakdown($s); ?>
          <tr>
            <td class="small fw-semibold"><?= esc((string) $s['name']) ?><?php if (!(int) $s['active']): ?> <span class="badge bg-secondary">inactive</span><?php endif; ?></td>
            <td class="small"><?= esc(hp_fmt((string) $s['basic'])) ?></td>
            <td class="small"><?= esc(hp_fmt((string) $b['allowances'])) ?></td>
            <td class="small"><?= esc((string) $s['tax_percent']) ?></td>
            <td class="small"><?= esc(hp_fmt((string) $b['gross'])) ?></td>
            <td class="text-end">
              <form method="post" action="<?= esc(base_url('/hr/payroll/structures/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this structure? Assignments to it are cleared.')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($list === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No structures yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card p-3">
      <div class="sec-title mb-2">Add structure</div>
      <form method="post" action="<?= esc(base_url('/hr/payroll/structures/save')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" required></div>
        <div class="col-6"><label class="form-label small mb-1">Basic</label><input class="form-control form-control-sm" name="basic" value="0"></div>
        <div class="col-6"><label class="form-label small mb-1">House rent</label><input class="form-control form-control-sm" name="house_rent" value="0"></div>
        <div class="col-6"><label class="form-label small mb-1">Medical</label><input class="form-control form-control-sm" name="medical" value="0"></div>
        <div class="col-6"><label class="form-label small mb-1">Transport</label><input class="form-control form-control-sm" name="transport" value="0"></div>
        <div class="col-6"><label class="form-label small mb-1">Other allowance</label><input class="form-control form-control-sm" name="other_allowance" value="0"></div>
        <div class="col-6"><label class="form-label small mb-1">Tax %</label><input class="form-control form-control-sm" name="tax_percent" value="0"></div>
        <div class="col-6"><label class="form-label small mb-1">EOBI / deduction</label><input class="form-control form-control-sm" name="eobi" value="0"></div>
        <div class="col-6"><label class="form-label small mb-1">LWP day rate (deducted per ABSENT day)</label><input class="form-control form-control-sm" name="lwp_day_rate" value="0"></div>
        <div class="col-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked id="stactive"><label class="form-check-label small" for="stactive">Active</label></div></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
      </form>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_payroll_assignments(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $structures = hr_payroll_structures(true);
    $assigned = hr_payroll_assigned_employees();
    $assignedMap = [];
    foreach ($assigned as $a) $assignedMap[strtolower((string) $a['email'])] = $a;

    $emps = access_visible_employees($u);
    layout_open('Payroll Assignments', $u, '/hr/payroll');
    hr_tabs('/hr/payroll');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Salary Assignments</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll')) ?>">Back to Payroll</a>
</div>
<?php if ($structures === []): ?>
  <div class="alert alert-warning small">Create at least one <a href="<?= esc(base_url('/hr/payroll/structures')) ?>">salary structure</a> first.</div>
<?php endif; ?>
<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Employee</th><th>Current Structure</th><th style="min-width:260px">Set</th></tr></thead>
    <tbody>
    <?php foreach ($emps as $e): $em = strtolower((string) $e['email']); $cur = $assignedMap[$em] ?? null; ?>
      <tr>
        <td class="small"><div class="fw-semibold"><?= esc(employee_full_name($e)) ?></div><div class="text-muted"><?= esc((string) $e['email']) ?></div></td>
        <td class="small"><?= esc((string) ($cur['structure_name'] ?? '-')) ?></td>
        <td>
          <form method="post" action="<?= esc(base_url('/hr/payroll/assign')) ?>" class="d-flex gap-1">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= esc((string) $e['email']) ?>">
            <select class="form-select form-select-sm" name="structure_id" required>
              <option value="">- choose -</option>
              <?php foreach ($structures as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $cur && (int) $cur['structure_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= esc((string) $s['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary text-nowrap">Set</button>
          </form>
          <?php if ($cur): ?>
            <form method="post" action="<?= esc(base_url('/hr/payroll/assign/clear')) ?>" class="mt-1" onsubmit="return confirm('Clear this assignment?')">
              <?= csrf_field() ?><input type="hidden" name="email" value="<?= esc((string) $e['email']) ?>">
              <button class="btn btn-sm btn-link text-danger p-0">Clear</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($emps === []): ?><tr><td colspan="3" class="text-center text-muted py-3">No visible employees.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_payroll_runs(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $filters = ['limit' => 300];
    if ($fStatus !== '') $filters['status'] = $fStatus;
    $runs = hr_payroll_runs($filters);

    layout_open('Payroll Runs', $u, '/hr/payroll');
    hr_tabs('/hr/payroll');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-list-check me-2 text-primary"></i>Payroll Runs</h4>
  <div class="d-flex gap-2 align-items-center">
    <div class="btn-group btn-group-sm">
      <?php foreach (['' => 'All'] + hr_payroll_run_statuses() as $k => $lbl): ?>
        <a class="btn btn-outline-secondary <?= $fStatus === $k ? 'active' : '' ?>" href="?status=<?= esc($k) ?>"><?= esc($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/payroll/runs/new')) ?>"><i class="bi bi-plus-lg me-1"></i>New run</a>
  </div>
</div>
<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Period</th><th>Headcount</th><th>Gross</th><th>Net</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td class="small fw-semibold"><?= esc(hp_period_label((string) $r['period'])) ?></td>
        <td class="small"><?= (int) $r['headcount'] ?></td>
        <td class="small"><?= esc(hp_fmt((string) $r['total_gross'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $r['total_net'])) ?></td>
        <td><?= hr_payroll_status_badge((string) $r['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/payroll/runs/' . (int) $r['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($runs === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No runs.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_payroll_run_new(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $assigned = hr_payroll_assigned_employees();
    $defaultPeriod = date('Y-m');

    layout_open('Generate Payroll Run', $u, '/hr/payroll');
    hr_tabs('/hr/payroll');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-plus-square me-2 text-primary"></i>Generate Payroll Run</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll')) ?>">Back</a>
</div>
<div class="card p-3">
  <form method="post" action="<?= esc(base_url('/hr/payroll/runs/generate')) ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-12 col-md-4"><label class="form-label small mb-1">Period</label><input type="month" class="form-control form-control-sm" name="period" value="<?= esc($defaultPeriod) ?>" required></div>
    <div class="col-12 col-md-4"><button class="btn btn-primary btn-sm"><i class="bi bi-play-fill me-1"></i>Generate draft run</button></div>
  </form>
  <div class="small text-muted mt-3">One payslip is created per assigned employee, using their current structure. <?= count($assigned) ?> employee(s) are assigned.</div>
</div>
    <?php
    layout_close();
}

function hr_page_payroll_run(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $run = hr_payroll_run($id);
    if ($run === null) { http_response_code(404); echo 'Run not found.'; exit; }
    $slips = hr_payslips_for_run($id);
    $bankFile = hr_meezan_salary_file($id);
    $isDraft = strtoupper((string) $run['status']) === 'DRAFT';

    layout_open('Payroll Run - ' . (string) $run['period'], $u, '/hr/payroll');
    hr_tabs('/hr/payroll');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc(hp_period_label((string) $run['period'])) ?></h4>
    <div class="small text-muted"><?= (int) $run['headcount'] ?> payslips &middot; gross <?= esc(hp_fmt((string) $run['total_gross'])) ?> &middot; net <?= esc(hp_fmt((string) $run['total_net'])) ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_payroll_status_badge((string) $run['status']) ?>
    <form method="post" action="<?= esc(base_url('/hr/payroll/runs/status')) ?>" class="d-flex gap-1">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <?php foreach (hr_payroll_run_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $run['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
      </select>
    </form>
    <form method="post" action="<?= esc(base_url('/hr/payroll/runs/delete')) ?>" onsubmit="return confirm('Delete this run and all its payslips?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
    <a class="btn btn-sm btn-success" href="<?= esc(base_url('/hr/payroll/runs/' . $id . '/meezan.csv')) ?>"><i class="bi bi-bank me-1"></i>Meezan salary file</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll/runs')) ?>">Back</a>
  </div>
</div>

<div class="card p-3">
  <div class="sec-title mb-2">Payslips<?= $isDraft ? ' (editable while draft)' : ' (locked)' ?></div>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Employee</th><th>Basic</th><th>Allow.</th><th>Bonus</th><th>Comm.</th><th>Deduct.</th><th>Tax</th><th>Advance</th><th>Attend.</th><th>Net</th><?= $isDraft ? '<th></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($slips as $ps): ?>
      <tr>
        <td class="small"><?= esc((string) $ps['email']) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['basic'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['allowances'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['bonus'] ?? '0'))) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['commission'] ?? '0'))) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['deductions'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['tax'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['advance_deduction'] ?? '0'))) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['attendance_deduction'] ?? '0'))) ?></td>
        <td class="small fw-semibold"><?= esc(hp_fmt((string) $ps['net'])) ?></td>
        <?php if ($isDraft): ?>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#ps<?= (int) $ps['id'] ?>"><i class="bi bi-pencil"></i></button>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if ($slips === []): ?><tr><td colspan="<?= $isDraft ? 11 : 10 ?>" class="text-center text-muted py-3">No payslips.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card p-3 mt-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div>
      <div class="sec-title mb-0"><i class="bi bi-bank me-1 text-success"></i>Meezan Bank salary transfer</div>
      <div class="small text-muted">Bank details come from each employee's confirmed Employee Form record. The exported CSV uses Meezan Bank's salary-upload columns.</div>
    </div>
    <a class="btn btn-sm btn-outline-success" href="<?= esc(base_url('/hr/payroll/runs/' . $id . '/meezan.csv')) ?>"><i class="bi bi-download me-1"></i>Download CSV</a>
  </div>
  <?php if ($bankFile['missing'] !== []): ?>
    <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>
      <?= count($bankFile['missing']) ?> employee(s) have no account number on file: <?= esc(implode(', ', $bankFile['missing'])) ?>. Ask them to complete the Employee Form before uploading to the bank.</div>
  <?php endif; ?>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th>Beneficiary</th><th>Account title</th><th>Account number</th><th>Bank</th><th class="text-end">Amount</th><th>Confirmed</th></tr></thead>
    <tbody>
    <?php $sn = 0; $tot = 0.0; foreach ($bankFile['rows'] as $r): $sn++; $tot += (float) $r['amount']; ?>
      <tr>
        <td class="small text-muted"><?= $sn ?></td>
        <td class="small"><?= esc((string) $r['name']) ?><div class="text-muted" style="font-size:.72rem"><?= esc((string) $r['email']) ?></div></td>
        <td class="small"><?= esc((string) $r['account_title']) ?></td>
        <td class="small"><?= $r['account_number'] !== '' ? esc((string) $r['account_number']) : '<span class="badge bg-danger">Missing</span>' ?></td>
        <td class="small"><?= esc((string) ($r['bank_name'] !== '' ? $r['bank_name'] : 'Meezan Bank')) ?></td>
        <td class="small text-end fw-semibold"><?= esc(hp_fmt((string) $r['amount'])) ?></td>
        <td class="small"><?= $r['confirmed'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($bankFile['rows'] === []): ?><tr><td colspan="7" class="text-center text-muted py-3">No payslips.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($bankFile['rows'] !== []): ?>
    <tfoot><tr class="fw-semibold"><td colspan="5" class="text-end small">Total</td><td class="small text-end"><?= esc(hp_fmt((string) $tot)) ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

<?php if ($isDraft): foreach ($slips as $ps): ?>
  <div class="modal fade" id="ps<?= (int) $ps['id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post" action="<?= esc(base_url('/hr/payroll/payslip/save')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $ps['id'] ?>">
      <div class="modal-header"><h6 class="modal-title"><?= esc((string) $ps['email']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <div class="col-6"><label class="form-label small mb-1">Basic</label><input class="form-control form-control-sm" name="basic" value="<?= esc((string) $ps['basic']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Allowances</label><input class="form-control form-control-sm" name="allowances" value="<?= esc((string) $ps['allowances']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Bonus</label><input class="form-control form-control-sm" name="bonus" value="<?= esc((string) ($ps['bonus'] ?? '0')) ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Commission</label><input class="form-control form-control-sm" name="commission" value="<?= esc((string) ($ps['commission'] ?? '0')) ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Deductions</label><input class="form-control form-control-sm" name="deductions" value="<?= esc((string) $ps['deductions']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Tax</label><input class="form-control form-control-sm" name="tax" value="<?= esc((string) $ps['tax']) ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Advance recovery</label><input class="form-control form-control-sm" name="advance_deduction" value="<?= esc((string) ($ps['advance_deduction'] ?? '0')) ?>"></div>
        <div class="col-6"><label class="form-label small mb-1">Attendance deduction</label><input class="form-control form-control-sm" name="attendance_deduction" value="<?= esc((string) ($ps['attendance_deduction'] ?? '0')) ?>"></div>
        <div class="col-12"><label class="form-label small mb-1">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= esc((string) $ps['notes']) ?></textarea></div>
        <div class="col-12"><div class="small text-muted">Net = basic + allowances + bonus + commission - deductions - tax - advance - attendance.</div></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary btn-sm">Save</button></div>
    </form>
  </div></div></div>
<?php endforeach; endif; ?>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------- export ---- */

function hr_payroll_meezan_export(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $run = hr_payroll_run($id);
    if ($run === null) { http_response_code(404); echo 'Run not found.'; exit; }
    $file = hr_meezan_salary_file($id);
    $period = (string) $run['period'];
    $safe = preg_replace('/[^0-9A-Za-z\-]/', '', $period);
    activity_log($u, 'HR_PAYROLL_MEEZAN_EXPORT', 'run #' . $id . ' (' . $period . ')');
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="meezan-salary-' . ($safe !== '' ? $safe : $id) . '.csv"');
    header('Cache-Control: no-store');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Sr. No', 'Beneficiary Name', 'Account Number', 'Amount', 'Remarks']);
    $i = 1; $total = 0.0;
    foreach ($file['rows'] as $r) {
        fputcsv($fh, [$i++, (string) $r['name'], (string) $r['account_number'],
            number_format((float) $r['amount'], 2, '.', ''), 'SALARY ' . $period]);
        $total += (float) $r['amount'];
    }
    fputcsv($fh, ['', 'TOTAL', '', number_format($total, 2, '.', ''), '']);
    fclose($fh);
    exit;
}

/* ------------------------------------------------------------------- posts ---- */

function hr_post_payroll_structure_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $saved = hr_payroll_structure_save($id, $_POST, (string) $u['email']);
    flash($saved ? 'success' : 'danger', $saved ? 'Structure saved.' : 'Structure name required.');
    redirect(base_url('/hr/payroll/structures'));
}

function hr_post_payroll_structure_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    hr_payroll_structure_delete((int) ($_POST['id'] ?? 0));
    flash('success', 'Structure deleted.');
    redirect(base_url('/hr/payroll/structures'));
}

function hr_post_payroll_assign(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = (string) ($_POST['email'] ?? '');
    if (!hr_payroll_set_assignment($email, (int) ($_POST['structure_id'] ?? 0), (string) $u['email'])) {
        flash('danger', 'Could not assign that structure.');
    } else {
        activity_log($u, 'HR_PAYROLL_ASSIGNED', $email . ' -> structure #' . (int) ($_POST['structure_id'] ?? 0));
        flash('success', 'Assignment saved.');
    }
    redirect(base_url('/hr/payroll/assignments'));
}

function hr_post_payroll_assign_clear(): void {
    require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    hr_payroll_clear_assignment((string) ($_POST['email'] ?? ''));
    flash('success', 'Assignment cleared.');
    redirect(base_url('/hr/payroll/assignments'));
}

function hr_post_payroll_run_generate(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_payroll_generate((string) ($_POST['period'] ?? ''), (string) $u['email']);
    if ($id === 0) { flash('danger', 'That period is invalid, already has a run, or no employees are assigned.'); redirect(base_url('/hr/payroll/runs/new')); }
    flash('success', 'Draft payroll run generated.');
    redirect(base_url('/hr/payroll/runs/' . $id));
}

function hr_post_payroll_advance_grant(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = (string) ($_POST['email'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $id = hr_payroll_advance_grant($email, $amount, (string) ($_POST['reason'] ?? ''), (string) $u['email']);
    if ($id === 0) { flash('danger', 'Choose a valid employee and a positive amount.'); }
    else {
        activity_log($u, 'HR_PAYROLL_ADVANCE_GRANTED', $email . ' advance #' . $id);
        hr_notify(strtolower(trim($email)), 'PAYROLL_ADVANCE', 'Salary advance granted',
            'A salary advance has been recorded on your payroll.', '/hr/payroll/me', (string) $u['email']);
        flash('success', 'Advance granted.');
    }
    redirect(base_url('/hr/payroll/advances'));
}

function hr_post_payroll_advance_cancel(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $err = hr_payroll_advance_cancel((int) ($_POST['id'] ?? 0), (string) $u['email']);
    if ($err !== '') flash('danger', $err); else flash('success', 'Advance cancelled.');
    redirect(base_url('/hr/payroll/advances'));
}

function hr_post_payroll_run_status(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!hr_payroll_set_run_status($id, (string) ($_POST['status'] ?? ''), (string) $u['email'])) { flash('danger', 'Unknown run status.'); }
    else { flash('success', 'Run status updated.'); }
    redirect(base_url('/hr/payroll/runs/' . $id));
}

function hr_post_payroll_run_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_payroll_run_delete($id);
    activity_log($u, 'HR_PAYROLL_DELETED', 'Run #' . $id);
    flash('success', 'Run deleted.');
    redirect(base_url('/hr/payroll/runs'));
}

function hr_post_payroll_payslip_save(): void {
    require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $ps = one('SELECT run_id FROM hr_payslips WHERE id=?', [$id]);
    if (!hr_payroll_update_payslip($id, $_POST)) { flash('danger', 'Payslip not found or the run is no longer a draft.'); }
    else { flash('success', 'Payslip updated.'); }
    redirect(base_url('/hr/payroll/runs/' . (int) ($ps['run_id'] ?? 0)));
}

/* ------------------------------------------------------------- self-service ---- */

function hr_page_payroll_me(): void {
    $u = require_login();
    $email = strtolower((string) $u['email']);
    $slips = hr_payslips_for_email($email);
    $assignment = hr_payroll_assignment_for($email);
    $structure = $assignment !== null ? hr_payroll_structure((int) $assignment['structure_id']) : null;

    layout_open('My Payslips', $u, '/hr/me');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-cash-stack me-2 text-primary"></i>My Payslips</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/me')) ?>">Back to My HR</a>
</div>
<?php if ($structure !== null): $b = hr_payroll_breakdown($structure); ?>
<div class="card p-3 mb-3">
  <div class="sec-title mb-2">My Salary Structure</div>
  <div class="row g-2 small">
    <div class="col-6 col-md-2"><div class="text-muted">Basic</div><div class="fw-semibold"><?= esc(hp_fmt((string) $structure['basic'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="text-muted">Allowances</div><div class="fw-semibold"><?= esc(hp_fmt((string) $b['allowances'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="text-muted">Gross</div><div class="fw-semibold"><?= esc(hp_fmt((string) $b['gross'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="text-muted">Tax</div><div class="fw-semibold"><?= esc(hp_fmt((string) $b['tax'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="text-muted">Deductions</div><div class="fw-semibold"><?= esc(hp_fmt((string) $b['deductions'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="text-muted">Net</div><div class="fw-bold text-success"><?= esc(hp_fmt((string) $b['net'])) ?></div></div>
  </div>
</div>
<?php endif; ?>
<?php $myAdvances = hr_payroll_advances_for_email($email); ?>
<?php if ($myAdvances !== []): ?>
<div class="card p-3 mb-3">
  <div class="sec-title mb-2">My Outstanding Advances</div>
  <table class="table table-sm mb-0">
    <thead class="table-light"><tr><th>Amount</th><th>Reason</th><th>Granted</th></tr></thead>
    <tbody>
    <?php foreach ($myAdvances as $a): ?>
      <tr><td class="small fw-semibold"><?= esc(hp_fmt((string) $a['amount'])) ?></td><td class="small"><?= esc((string) ($a['reason'] ?? '')) ?></td><td class="small text-muted"><?= esc(dt_fmt((string) $a['created_at'], 'd M Y')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="small text-muted mt-1">Outstanding advances are recovered from your next payroll run.</div>
</div>
<?php endif; ?>
<div class="card p-3">
  <div class="sec-title mb-2">Payslip History</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Period</th><th>Basic</th><th>Allow.</th><th>Bonus</th><th>Comm.</th><th>Deduct.</th><th>Tax</th><th>Advance</th><th>Attend.</th><th>Net</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($slips as $ps): ?>
      <tr>
        <td class="small"><?= esc(hp_period_label((string) $ps['period'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['basic'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['allowances'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['bonus'] ?? '0'))) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['commission'] ?? '0'))) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['deductions'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) $ps['tax'])) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['advance_deduction'] ?? '0'))) ?></td>
        <td class="small"><?= esc(hp_fmt((string) ($ps['attendance_deduction'] ?? '0'))) ?></td>
        <td class="small fw-semibold"><?= esc(hp_fmt((string) $ps['net'])) ?></td>
        <td><?= hr_payroll_status_badge((string) $ps['run_status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($slips === []): ?><tr><td colspan="11" class="text-center text-muted py-3">No payslips yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

/* --------------------------------------------------------------- advances ---- */

function hr_page_payroll_advances(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $list = hr_payroll_advances($fStatus !== '' ? ['status' => $fStatus, 'limit' => 500] : ['limit' => 500]);
    $emps = access_visible_employees($u);

    layout_open('Salary Advances', $u, '/hr/payroll');
    hr_tabs('/hr/payroll');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-cash-coin me-2 text-primary"></i>Salary Advances</h4>
  <div class="d-flex gap-2 align-items-center">
    <div class="btn-group btn-group-sm">
      <?php foreach (['' => 'All', 'OUTSTANDING' => 'Outstanding', 'RECOVERED' => 'Recovered', 'CANCELLED' => 'Cancelled'] as $k => $lbl): ?>
        <a class="btn btn-outline-secondary <?= $fStatus === $k ? 'active' : '' ?>" href="?status=<?= esc($k) ?>"><?= esc($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/payroll')) ?>">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card p-3">
      <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Employee</th><th>Amount</th><th>Reason</th><th>Status</th><th>Granted</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $a): $an = trim((string) ($a['first_name'] ?? '') . ' ' . (string) ($a['last_name'] ?? '')); $st = strtoupper((string) $a['status']); ?>
          <tr>
            <td class="small fw-semibold"><?= esc($an !== '' ? $an : (string) $a['email']) ?></td>
            <td class="small"><?= esc(hp_fmt((string) $a['amount'])) ?></td>
            <td class="small text-truncate" style="max-width:220px"><?= esc((string) ($a['reason'] ?? '')) ?></td>
            <td><span class="badge <?= $st === 'OUTSTANDING' ? 'bg-warning text-dark' : ($st === 'RECOVERED' ? 'bg-success' : 'bg-secondary') ?>"><?= esc($st) ?></span></td>
            <td class="small text-muted"><?= esc(dt_fmt((string) $a['created_at'], 'd M Y')) ?></td>
            <td class="text-end">
              <?php if ($st === 'OUTSTANDING'): ?>
                <form method="post" action="<?= esc(base_url('/hr/payroll/advances/cancel')) ?>" class="d-inline" onsubmit="return confirm('Cancel this advance?')">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($list === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No advances recorded.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-3">
      <div class="sec-title mb-2">Grant advance</div>
      <form method="post" action="<?= esc(base_url('/hr/payroll/advances/grant')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label small mb-1">Employee</label>
          <select class="form-select form-select-sm" name="email" required>
            <option value="">Select employee</option>
            <?php foreach ($emps as $e): ?><option value="<?= esc((string) $e['email']) ?>"><?= esc(employee_full_name($e)) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label small mb-1">Amount</label><input class="form-control form-control-sm" name="amount" value="0" required></div>
        <div class="col-12"><label class="form-label small mb-1">Reason</label><input class="form-control form-control-sm" name="reason"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Grant advance</button></div>
        <div class="col-12"><div class="small text-muted">An outstanding advance is deducted in full from the next payroll run.</div></div>
      </form>
    </div>
  </div>
</div>
    <?php
    layout_close();
}
