<?php
/**
 * WorkFolio PHP - HR module screens: Offboarding.
 *
 * Mounted through hr_offboarding_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/offboarding* path. HR/Admin manage plans; the leaver
 * can open /hr/offboarding/me and close the items that are theirs.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';       /* hr_tabs(), hr_is_manager() */
require_once __DIR__ . '/hr_offboarding.php';

/* ------------------------------------------------------------------- router ---- */

function hr_offboarding_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/offboarding':              hr_page_offboarding_overview(); return true;
            case $path === '/offboarding/new':          hr_page_offboarding_new(); return true;
            case $path === '/offboarding/plans':        hr_page_offboarding_plans(); return true;
            case $path === '/offboarding/me':           hr_page_offboarding_me(); return true;
            case preg_match('#^/offboarding/plans/(\d+)$#', $path, $m) === 1:
                hr_page_offboarding_plan((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/offboarding/plan/create':   hr_post_offboarding_plan_create(); return true;
            case $path === '/offboarding/plan/save':     hr_post_offboarding_plan_save(); return true;
            case $path === '/offboarding/plan/status':   hr_post_offboarding_plan_status(); return true;
            case $path === '/offboarding/plan/approve':  hr_post_offboarding_plan_approve(); return true;
            case $path === '/offboarding/plan/reject':   hr_post_offboarding_plan_reject(); return true;
            case $path === '/offboarding/plan/delete':   hr_post_offboarding_plan_delete(); return true;
            case $path === '/offboarding/plan/task/save': hr_post_offboarding_task_save(); return true;
            case $path === '/offboarding/plan/task/toggle': hr_post_offboarding_task_toggle(); return true;
            case $path === '/offboarding/plan/task/delete': hr_post_offboarding_task_delete(); return true;
            case $path === '/offboarding/me/request':    hr_post_offboarding_me_request(); return true;
            case preg_match('#^/offboarding/me/task/(\d+)/toggle$#', $path, $m) === 1:
                hr_post_offboarding_me_task_toggle((int) $m[1]); return true;
        }
        return false;
    }
    return false;
}

/* ------------------------------------------------------------------ helpers ---- */

function hof_progress_bar(array $p): string {
    $pct = (int) ($p['percent'] ?? 0);
    $cls = $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-primary' : 'bg-warning');
    return '<div class="progress" style="height:6px"><div class="progress-bar ' . $cls . '" style="width:' . $pct . '%"></div></div>';
}

function hof_progress_count(array $p): string {
    return (int) $p['done'] . ' / ' . (int) $p['total'] . ' done';
}

/** Human notice-period summary for a plan row. */
function hof_notice_text(array $p): string {
    $days = (int) ($p['notice_period_days'] ?? 0);
    if ($days <= 0) return '—';
    $end = hof_date((string) ($p['notice_end_date'] ?? '')) ?: (string) ($p['last_working_date'] ?? '');
    return $days . ' days' . ($end !== '' ? ' (to ' . $end . ')' : '');
}

/** A compact self-service card appended to /hr/me. Offers resignation when none exists. */
function hr_offboarding_me_card(string $email): void {
    $plan = hr_offboarding_plan_by_email($email);
    if ($plan === null) {
        ?>
        <div class="card p-3 mb-3">
          <div class="sec-title mb-2"><i class="bi bi-person-dash me-1 text-primary"></i>Resignation</div>
          <div class="small text-muted mb-2">Thinking of moving on? Filing a resignation sends it to HR for approval.</div>
          <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/offboarding/me')) ?>"><i class="bi bi-send me-1"></i>Submit resignation</a>
        </div>
        <?php
        return;
    }
    $status = strtoupper((string) $plan['status']);
    $tasks = $status === 'REQUESTED' ? [] : hr_offboarding_tasks((int) $plan['id']);
    $prog = hr_offboarding_progress((int) $plan['id']);
    ?>
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0"><i class="bi bi-person-dash me-1 text-primary"></i>My Offboarding</div>
        <?= hr_offboarding_status_badge((string) $plan['status']) ?>
      </div>
      <?php if ($status === 'REQUESTED'): ?>
        <div class="small text-muted">Your resignation is with HR for approval &middot; notice <?= esc(hof_notice_text($plan)) ?>.</div>
      <?php elseif ($status === 'REJECTED'): ?>
        <div class="small text-muted">Your last resignation request was declined. You can submit a new one.</div>
      <?php else: ?>
        <div class="small text-muted mb-1"><?= hof_progress_count($prog) ?></div>
        <?= hof_progress_bar($prog) ?>
        <table class="table table-sm mt-2 mb-0">
          <tbody>
          <?php foreach ($tasks as $t): $mine = strtoupper((string) $t['owner_role']) === 'EMPLOYEE';
              $closed = in_array(strtoupper((string) $t['status']), ['DONE', 'SKIPPED'], true); ?>
            <tr>
              <td class="small"><?= esc((string) $t['title']) ?>
                <div class="text-muted"><?= esc(hr_offboarding_category_label((string) $t['category'])) ?></div></td>
              <td><?= hr_offboarding_task_status_badge((string) $t['status']) ?></td>
              <td class="text-end">
                <?php if ($mine && !$closed): ?>
                  <form method="post" action="<?= esc(base_url('/hr/offboarding/me/task/' . (int) $t['id'] . '/toggle')) ?>" class="d-inline">
                    <?= csrf_field() ?><input type="hidden" name="status" value="DONE">
                    <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-primary mt-2" href="<?= esc(base_url('/hr/offboarding/me')) ?>">Open my offboarding</a>
    </div>
    <?php
}

/* ----------------------------------------------------------------- overview ---- */

function hr_page_offboarding_overview(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $stats = hr_offboarding_stats();
    $plans = hr_offboarding_plans(['limit' => 15]);

    layout_open('Offboarding', $u, '/hr/offboarding');
    hr_tabs('/hr/offboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-person-dash me-2 text-primary"></i>Offboarding</h4>
    <div class="small text-muted">Clearance checklists for leavers, tracked to the last day.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/offboarding/new')) ?>"><i class="bi bi-plus-lg me-1"></i>Start offboarding</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/offboarding/plans')) ?>">All plans</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Awaiting Approval</div><div class="fs-4 fw-bold text-info"><?= (int) $stats['requested'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">In Progress</div><div class="fs-4 fw-bold text-primary"><?= (int) $stats['active_plans'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Pending Tasks</div><div class="fs-4 fw-bold"><?= (int) $stats['pending_tasks'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Completed (Month)</div><div class="fs-4 fw-bold text-success"><?= (int) $stats['completed_month'] ?></div></div></div>
</div>

<?php $requests = hr_offboarding_plans(['status' => 'REQUESTED', 'limit' => 50]); ?>
<?php if ($requests !== []): ?>
<div class="card p-3 mb-3 border-info">
  <div class="sec-title mb-2"><i class="bi bi-hourglass-split me-1 text-info"></i>Resignation Requests Awaiting Approval (<?= count($requests) ?>)</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Employee</th><th>Resignation</th><th>Notice</th><th>Reason</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($requests as $p): $pd = (int) ($p['notice_period_days'] ?? 0); $rd = hof_date((string) ($p['resignation_date'] ?? '')); ?>
      <tr>
        <td class="small"><div class="fw-semibold"><?= esc((string) ($p['full_name'] ?: $p['email'])) ?></div><div class="text-muted"><?= esc((string) $p['email']) ?></div></td>
        <td class="small"><?= esc((string) ($p['resignation_date'] ?: '-')) ?></td>
        <td class="small"><?= esc(hof_notice_text($p)) ?></td>
        <td class="small text-muted" style="max-width:260px"><?= esc(substr((string) $p['reason'], 0, 120)) ?></td>
        <td class="text-end text-nowrap">
          <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/approve')) ?>" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="notice_period_days" value="<?= $pd ?>">
            <input class="form-control form-control-sm d-inline-block w-auto" type="date" name="last_working_date" value="<?= esc((string) ($p['last_working_date'] ?? hof_add_days($rd, $pd))) ?>" style="width:auto">
            <button class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Approve</button>
          </form>
          <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/reject')) ?>" class="d-inline" onsubmit="return confirm('Reject this resignation request?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
          </form>
          <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/offboarding/plans/' . (int) $p['id'])) ?>">Open</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="card p-3">
  <div class="sec-title mb-2">Recent Plans</div>
  <?php if ($plans === []): ?>
    <div class="small text-muted">No offboarding plans yet. <a href="<?= esc(base_url('/hr/offboarding/new')) ?>">Start one</a>.</div>
  <?php else: ?>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr><th>Leaver</th><th>Type</th><th>Last Working Day</th><th>Progress</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): $tot = (int) $p['task_count']; $dn = (int) $p['done_count']; $pct = $tot > 0 ? (int) round($dn / $tot * 100) : 0; ?>
        <tr>
          <td class="small"><div class="fw-semibold"><?= esc((string) ($p['full_name'] ?: $p['email'])) ?></div><div class="text-muted"><?= esc((string) $p['email']) ?></div></td>
          <td class="small"><?= esc(hr_offboarding_type_label((string) $p['offboard_type'])) ?></td>
          <td class="small"><?= esc((string) ($p['last_working_date'] ?: '-')) ?></td>
          <td style="min-width:120px"><?= hof_progress_bar(['percent' => $pct]) ?><div class="text-muted" style="font-size:.72rem"><?= $dn ?>/<?= $tot ?></div></td>
          <td><?= hr_offboarding_status_badge((string) $p['status']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/offboarding/plans/' . (int) $p['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
    <?php
    layout_close();
}

function hr_page_offboarding_plans(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $filters = ['limit' => 300];
    if ($fStatus !== '') $filters['status'] = $fStatus;
    $plans = hr_offboarding_plans($filters);

    layout_open('Offboarding Plans', $u, '/hr/offboarding');
    hr_tabs('/hr/offboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Offboarding Plans</h4>
  <div class="d-flex gap-2 align-items-center">
    <div class="btn-group btn-group-sm">
      <?php foreach (['' => 'All'] + hr_offboarding_statuses() as $k => $lbl): ?>
        <a class="btn btn-outline-secondary <?= $fStatus === $k ? 'active' : '' ?>" href="?status=<?= esc($k) ?>"><?= esc($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/offboarding/new')) ?>"><i class="bi bi-plus-lg me-1"></i>Start offboarding</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Leaver</th><th>Type</th><th>Last Working Day</th><th>Progress</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($plans as $p): $tot = (int) $p['task_count']; $dn = (int) $p['done_count']; $pct = $tot > 0 ? (int) round($dn / $tot * 100) : 0; ?>
      <tr>
        <td class="small"><div class="fw-semibold"><?= esc((string) ($p['full_name'] ?: $p['email'])) ?></div><div class="text-muted"><?= esc((string) $p['email']) ?></div></td>
        <td class="small"><?= esc(hr_offboarding_type_label((string) $p['offboard_type'])) ?></td>
        <td class="small"><?= esc((string) ($p['last_working_date'] ?: '-')) ?></td>
        <td style="min-width:120px"><?= hof_progress_bar(['percent' => $pct]) ?><div class="text-muted" style="font-size:.72rem"><?= $dn ?>/<?= $tot ?></div></td>
        <td><?= hr_offboarding_status_badge((string) $p['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/offboarding/plans/' . (int) $p['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($plans === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No plans match.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_offboarding_new(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    layout_open('Start Offboarding', $u, '/hr/offboarding');
    hr_tabs('/hr/offboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-dash me-2 text-primary"></i>Start an Offboarding Plan</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/offboarding')) ?>">Back</a>
</div>
<div class="card p-3">
  <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/create')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Leaver email</label><input type="email" class="form-control form-control-sm" name="email" required></div>
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Full name</label><input class="form-control form-control-sm" name="full_name"></div>
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Type</label>
        <select class="form-select form-select-sm" name="offboard_type"><?php foreach (hr_offboarding_types() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Resignation date</label><input type="date" class="form-control form-control-sm" name="resignation_date"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Last working day</label><input type="date" class="form-control form-control-sm" name="last_working_date"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Notice (days)</label><input type="number" min="0" class="form-control form-control-sm" name="notice_period_days" value="30"></div>
      <div class="col-6 col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="eligible_rehire" value="1" id="rehire"><label class="form-check-label small" for="rehire">Eligible for rehire</label></div></div>
      <div class="col-12"><label class="form-label small mb-1">Reason</label><textarea class="form-control form-control-sm" name="reason" rows="2"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-person-dash me-1"></i>Create plan</button></div>
    </div>
  </form>
</div>
    <?php
    layout_close();
}

function hr_page_offboarding_plan(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $p = hr_offboarding_plan($id);
    if ($p === null) { http_response_code(404); echo 'Plan not found.'; exit; }
    $tasks = hr_offboarding_tasks($id);
    $prog = hr_offboarding_progress($id);

    layout_open('Offboarding - ' . (string) ($p['full_name'] ?: $p['email']), $u, '/hr/offboarding');
    hr_tabs('/hr/offboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) ($p['full_name'] ?: $p['email'])) ?></h4>
    <div class="small text-muted"><?= esc((string) $p['email']) ?>
      &middot; <?= esc(hr_offboarding_type_label((string) $p['offboard_type'])) ?>
      &middot; last day <?= esc((string) ($p['last_working_date'] ?: '-')) ?>
      &middot; notice <?= esc(hof_notice_text($p)) ?>
      <?php if (strtoupper((string) ($p['source'] ?? 'HR')) === 'SELF'): ?>&middot; <span class="badge bg-light text-dark border">self-submitted</span><?php endif; ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_offboarding_status_badge((string) $p['status']) ?>
    <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/status')) ?>" class="d-flex gap-1">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <?php foreach (hr_offboarding_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $p['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
      </select>
    </form>
    <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/delete')) ?>" onsubmit="return confirm('Delete this plan and its checklist?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/offboarding/plans')) ?>">Back</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="sec-title mb-0">Progress</div>
    <div class="small text-muted"><?= hof_progress_count($prog) ?> &middot; <?= (int) $prog['percent'] ?>%</div>
  </div>
  <?= hof_progress_bar($prog) ?>
</div>

<?php if (strtoupper((string) $p['status']) === 'REQUESTED'): $rd = hof_date((string) ($p['resignation_date'] ?? '')); ?>
<div class="card p-3 mb-3 border-info">
  <div class="sec-title mb-2"><i class="bi bi-hourglass-split me-1 text-info"></i>Resignation Awaiting Approval</div>
  <div class="small text-muted mb-2">Submitted <?= esc(dt_fmt((string) $p['created_at'])) ?><?php if (trim((string) $p['reason']) !== ''): ?> &middot; &ldquo;<?= esc((string) $p['reason']) ?>&rdquo;<?php endif; ?></div>
  <div class="d-flex flex-wrap gap-2 align-items-end">
    <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/approve')) ?>" class="d-flex flex-wrap gap-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <div><label class="form-label small mb-1">Notice (days)</label><input type="number" min="0" class="form-control form-control-sm" name="notice_period_days" value="<?= (int) $p['notice_period_days'] ?>" style="width:100px"></div>
      <div><label class="form-label small mb-1">Last working day</label><input type="date" class="form-control form-control-sm" name="last_working_date" value="<?= esc((string) ($p['last_working_date'] ?: hof_add_days($rd, (int) $p['notice_period_days']))) ?>"></div>
      <div><label class="form-label small mb-1">Approval note</label><input class="form-control form-control-sm" name="decision_note" placeholder="optional"></div>
      <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Approve &amp; start</button>
    </form>
    <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/reject')) ?>" class="d-flex gap-2 align-items-end" onsubmit="return confirm('Reject this request?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <div><label class="form-label small mb-1">Rejection note</label><input class="form-control form-control-sm" name="decision_note" placeholder="reason"></div>
      <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg me-1"></i>Reject</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (strtoupper((string) $p['status']) === 'REJECTED' && trim((string) ($p['decision_note'] ?? '')) !== ''): ?>
<div class="alert alert-danger py-2 small">Rejected by <?= esc((string) $p['approved_by']) ?> on <?= esc(dt_fmt((string) $p['approved_at'])) ?>: <?= esc((string) $p['decision_note']) ?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Exit Details</div>
  <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/save')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
    <div class="row g-2">
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Type</label>
        <select class="form-select form-select-sm" name="offboard_type"><?php foreach (hr_offboarding_types() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $p['offboard_type']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Resignation date</label><input type="date" class="form-control form-control-sm" name="resignation_date" value="<?= esc((string) ($p['resignation_date'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Last working day</label><input type="date" class="form-control form-control-sm" name="last_working_date" value="<?= esc((string) ($p['last_working_date'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Notice (days)</label><input type="number" min="0" class="form-control form-control-sm" name="notice_period_days" value="<?= (int) ($p['notice_period_days'] ?? 0) ?>"></div>
      <div class="col-6 col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="eligible_rehire" value="1" <?= (int) $p['eligible_rehire'] === 1 ? 'checked' : '' ?> id="rehire"><label class="form-check-label small" for="rehire">Eligible for rehire</label></div></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Reason</label><textarea class="form-control form-control-sm" name="reason" rows="2"><?= esc((string) ($p['reason'] ?? '')) ?></textarea></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Exit interview notes</label><textarea class="form-control form-control-sm" name="exit_interview" rows="2"><?= esc((string) ($p['exit_interview'] ?? '')) ?></textarea></div>
      <div class="col-12"><label class="form-label small mb-1">Clearance notes</label><textarea class="form-control form-control-sm" name="clearance_notes" rows="2"><?= esc((string) ($p['clearance_notes'] ?? '')) ?></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save details</button></div>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="sec-title mb-2">Clearance Checklist (<?= count($tasks) ?>)</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>Item</th><th>Category</th><th>Owner</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t): ?>
      <tr>
        <td class="small"><?= esc((string) $t['title']) ?><?php if (trim((string) $t['notes']) !== ''): ?><div class="text-muted" style="font-size:.72rem"><?= esc((string) $t['notes']) ?></div><?php endif; ?></td>
        <td class="small"><?= esc(hr_offboarding_category_label((string) $t['category'])) ?></td>
        <td class="small"><?= esc(hr_offboarding_owner_roles()[strtoupper((string) $t['owner_role'])] ?? (string) $t['owner_role']) ?></td>
        <td><?= hr_offboarding_task_status_badge((string) $t['status']) ?></td>
        <td class="text-end text-nowrap">
          <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/task/toggle')) ?>" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
            <select class="form-select form-select-sm d-inline-block w-auto" name="status" onchange="this.form.submit()">
              <?php foreach (hr_offboarding_task_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $t['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
            </select>
          </form>
          <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/task/delete')) ?>" class="d-inline" onsubmit="return confirm('Remove this item?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($tasks === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No items. Add one below.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= esc(base_url('/hr/offboarding/plan/task/save')) ?>" class="row g-2 mt-1 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
    <div class="col-12 col-md-5"><label class="form-label small mb-1">New item</label><input class="form-control form-control-sm" name="title" required></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Category</label>
      <select class="form-select form-select-sm" name="category"><?php foreach (hr_offboarding_categories() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Owner</label>
      <select class="form-select form-select-sm" name="owner_role"><?php foreach (hr_offboarding_owner_roles() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-2"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
  </form>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------ posts ---- */

function hr_post_offboarding_plan_create(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_offboarding_plan_create($_POST, (string) $u['email']);
    if ($id === 0) { flash('danger', 'A valid, not-already-offboarding email is required.'); redirect(base_url('/hr/offboarding/new')); }
    activity_log($u, 'HR_OFFBOARDING_CREATED', 'Plan #' . $id . ' for ' . (string) ($_POST['email'] ?? ''));
    flash('success', 'Offboarding plan started.');
    redirect(base_url('/hr/offboarding/plans/' . $id));
}

function hr_post_offboarding_plan_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!hr_offboarding_plan_save($id, $_POST, (string) $u['email'])) { flash('danger', 'Plan not found.'); }
    else { flash('success', 'Plan saved.'); }
    redirect(base_url('/hr/offboarding/plans/' . $id));
}

function hr_post_offboarding_plan_status(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!hr_offboarding_set_status($id, (string) ($_POST['status'] ?? ''), (string) $u['email'])) { flash('danger', 'Unknown plan status.'); }
    else { flash('success', 'Plan status updated.'); }
    redirect(base_url('/hr/offboarding/plans/' . $id));
}

function hr_post_offboarding_plan_approve(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $plan = hr_offboarding_plan($id);
    if (!hr_offboarding_request_approve($id, $_POST, (string) $u['email'])) {
        flash('danger', 'That request is no longer awaiting approval.');
    } else {
        if ($plan !== null) {
            hr_notify((string) $plan['email'], 'OFFBOARDING_APPROVED', 'Your resignation was approved',
                'HR approved your resignation. Your clearance checklist is now open.', '/hr/offboarding/me', (string) $u['email']);
            hr_notify_email((string) $plan['email'], 'OFFBOARDING_APPROVED', 'Your resignation was approved',
                'HR approved your resignation. Your clearance checklist is now open.', '/hr/offboarding/me');
        }
        flash('success', 'Resignation approved; clearance checklist started.');
    }
    redirect(base_url('/hr/offboarding/plans/' . $id));
}

function hr_post_offboarding_plan_reject(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $plan = hr_offboarding_plan($id);
    $note = (string) ($_POST['decision_note'] ?? '');
    if (!hr_offboarding_request_reject($id, $note, (string) $u['email'])) {
        flash('danger', 'That request is no longer awaiting approval.');
    } else {
        if ($plan !== null) {
            $body = 'HR declined your resignation request.' . ($note !== '' ? ' ' . $note : '');
            hr_notify((string) $plan['email'], 'OFFBOARDING_REJECTED', 'Your resignation request was declined', $body, '/hr/offboarding/me', (string) $u['email']);
            hr_notify_email((string) $plan['email'], 'OFFBOARDING_REJECTED', 'Your resignation request was declined', $body, '/hr/offboarding/me');
        }
        flash('success', 'Resignation request rejected.');
    }
    redirect(base_url('/hr/offboarding/plans/' . $id));
}

function hr_post_offboarding_plan_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_offboarding_plan_delete($id);
    activity_log($u, 'HR_OFFBOARDING_DELETED', 'Plan #' . $id);
    flash('success', 'Plan deleted.');
    redirect(base_url('/hr/offboarding/plans'));
}

function hr_post_offboarding_task_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if (hr_offboarding_plan($planId) === null) { http_response_code(404); echo 'Plan not found.'; exit; }
    hr_offboarding_task_save(hr_int_or_null($_POST['id'] ?? null), $planId, $_POST, (string) $u['email']);
    hr_offboarding_recheck_plan($planId);
    flash('success', 'Checklist item saved.');
    redirect(base_url('/hr/offboarding/plans/' . $planId));
}

function hr_post_offboarding_task_toggle(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if (!hr_offboarding_task_toggle($id, (string) ($_POST['status'] ?? ''), (string) $u['email'])) { flash('danger', 'Unknown task status.'); }
    else { flash('success', 'Checklist updated.'); }
    redirect(base_url('/hr/offboarding/plans/' . $planId));
}

function hr_post_offboarding_task_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $t = hr_offboarding_task($id);
    hr_offboarding_task_delete($id);
    flash('success', 'Checklist item removed.');
    redirect(base_url('/hr/offboarding/plans/' . (int) ($t['plan_id'] ?? 0)));
}

/* ------------------------------------------------------------- self-service ---- */

function hr_page_offboarding_me(): void {
    $u = require_login();
    $email = strtolower((string) $u['email']);
    $plan = hr_offboarding_plan_by_email($email);

    layout_open('My Offboarding', $u, '/hr/me');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-dash me-2 text-primary"></i>My Offboarding</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/me')) ?>">Back to My HR</a>
</div>
<?php
$planStatus = $plan !== null ? strtoupper((string) $plan['status']) : '';
$open = in_array($planStatus, ['REQUESTED', 'IN_PROGRESS'], true);
if (!$open): ?>
  <?php if ($plan !== null && $planStatus === 'REJECTED'): ?>
    <div class="alert alert-danger py-2 small">Your resignation request was declined<?php if (trim((string) $plan['decision_note']) !== ''): ?>: <?= esc((string) $plan['decision_note']) ?><?php endif; ?>. You may submit a new request below.</div>
  <?php endif; ?>
  <div class="card p-3">
    <div class="sec-title mb-2"><i class="bi bi-envelope-paper me-1 text-primary"></i>Submit Resignation</div>
    <?php if ($plan === null): ?><div class="small text-muted mb-2">You have no offboarding plan. Filing your resignation sends it to HR for approval.</div><?php endif; ?>
    <form method="post" action="<?= esc(base_url('/hr/offboarding/me/request')) ?>">
      <?= csrf_field() ?>
      <div class="row g-2">
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Resignation date</label><input type="date" class="form-control form-control-sm" name="resignation_date" value="<?= esc(date('Y-m-d')) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Notice (days)</label><input type="number" min="0" class="form-control form-control-sm" name="notice_period_days" value="30"></div>
        <div class="col-12 col-md-6"><label class="form-label small mb-1">Preferred last working day (optional)</label><input type="date" class="form-control form-control-sm" name="last_working_date"></div>
        <div class="col-12"><label class="form-label small mb-1">Reason</label><textarea class="form-control form-control-sm" name="reason" rows="2" placeholder="Why are you leaving?"></textarea></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Submit resignation</button></div>
      </div>
    </form>
  </div>
<?php elseif ($planStatus === 'REQUESTED'): ?>
  <div class="card p-3 border-info">
    <div class="d-flex justify-content-between align-items-center">
      <div class="sec-title mb-0"><i class="bi bi-hourglass-split me-1 text-info"></i>Resignation Submitted</div>
      <?= hr_offboarding_status_badge('REQUESTED') ?>
    </div>
    <div class="small text-muted mt-2">Your resignation is with HR for approval. Resignation date <?= esc((string) ($plan['resignation_date'] ?: '-')) ?> &middot; notice <?= esc(hof_notice_text($plan)) ?>.</div>
  </div>
<?php else:
    $tasks = hr_offboarding_tasks((int) $plan['id']);
    $prog = hr_offboarding_progress((int) $plan['id']); ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <div class="sec-title mb-0">Progress</div>
      <?= hr_offboarding_status_badge((string) $plan['status']) ?>
    </div>
    <div class="small text-muted mb-1"><?= hof_progress_count($prog) ?> &middot; notice <?= esc(hof_notice_text($plan)) ?></div>
    <?= hof_progress_bar($prog) ?>
  </div>
  <div class="card p-3">
    <div class="sec-title mb-2">Checklist</div>
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Item</th><th>Category</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): $mine = strtoupper((string) $t['owner_role']) === 'EMPLOYEE';
          $closed = in_array(strtoupper((string) $t['status']), ['DONE', 'SKIPPED'], true); ?>
        <tr>
          <td class="small"><?= esc((string) $t['title']) ?></td>
          <td class="small"><?= esc(hr_offboarding_category_label((string) $t['category'])) ?></td>
          <td><?= hr_offboarding_task_status_badge((string) $t['status']) ?></td>
          <td class="text-end">
            <?php if ($mine && !$closed): ?>
              <form method="post" action="<?= esc(base_url('/hr/offboarding/me/task/' . (int) $t['id'] . '/toggle')) ?>" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="status" value="DONE">
                <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg me-1"></i>Mark done</button>
              </form>
            <?php endif; ?>
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

function hr_post_offboarding_me_request(): void {
    $u = require_login();
    require_csrf();
    $email = strtolower((string) $u['email']);
    $id = hr_offboarding_request_create($email, $_POST);
    if ($id === 0) {
        flash('danger', 'You already have an open offboarding plan.');
    } else {
        activity_log($u, 'HR_OFFBOARDING_REQUESTED', 'Plan #' . $id . ' for ' . $email);
        $title = (function_exists('employee_full_name') ? employee_full_name($u) : $email) . ' submitted a resignation';
        $body = 'A resignation request is awaiting your approval.';
        hr_notify_roles(['SUPER_ADMIN', 'ADMIN', 'HR'], 'OFFBOARDING_REQUEST', $title, $body, '/hr/offboarding', $email);
        foreach (rows("SELECT email FROM employees WHERE `role` IN ('SUPER_ADMIN','ADMIN','HR')") as $hr)
            hr_notify_email((string) $hr['email'], 'OFFBOARDING_REQUEST', $title, $body, '/hr/offboarding');
        flash('success', 'Resignation submitted for HR approval.');
    }
    redirect(base_url('/hr/offboarding/me'));
}

function hr_post_offboarding_me_task_toggle(int $id): void {
    $u = require_login();
    require_csrf();
    $t = hr_offboarding_task($id);
    if ($t === null) { http_response_code(404); echo 'Task not found.'; exit; }
    if (strtoupper((string) $t['owner_role']) !== 'EMPLOYEE') { http_response_code(403); echo 'Not allowed.'; exit; }
    $status = strtoupper(trim((string) ($_POST['status'] ?? 'DONE')));
    if (!hr_offboarding_task_toggle($id, $status, (string) $u['email'])) { flash('danger', 'Unknown task status.'); }
    else { flash('success', 'Checklist updated.'); }
    redirect(base_url('/hr/offboarding/me'));
}
