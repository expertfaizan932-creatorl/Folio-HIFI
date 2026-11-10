<?php
/**
 * WorkFolio PHP - HR module screens: configurable approval workflows.
 *
 * Mounted through hr_approvals_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/approvals* path. Three audiences on one screen:
 * an approver gets an inbox of what is waiting on them, anyone can raise a
 * request, and HR/admin define the workflows themselves.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';
require_once __DIR__ . '/hr_approvals.php';

/* ------------------------------------------------------------------- router ---- */

function hr_approvals_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/approvals':                    hr_page_approvals(); return true;
            case $path === '/approvals/new':                hr_page_approvals_new(); return true;
            case $path === '/approvals/workflows':          hr_page_approval_workflows(); return true;
            case $path === '/approvals/workflows/new':      hr_page_approval_workflow_edit(null); return true;
            case preg_match('#^/approvals/workflows/(\d+)$#', $path, $m) === 1:
                hr_page_approval_workflow_edit((int) $m[1]); return true;
            case preg_match('#^/approvals/(\d+)$#', $path, $m) === 1:
                hr_page_approval_request((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/approvals/request/create':     hr_post_approval_request_create(); return true;
            case $path === '/approvals/workflows/save':     hr_post_approval_workflow_save(); return true;
            case $path === '/approvals/workflows/delete':   hr_post_approval_workflow_delete(); return true;
            case preg_match('#^/approvals/workflows/(\d+)/step/save$#', $path, $m) === 1:
                hr_post_approval_step_save((int) $m[1]); return true;
            case preg_match('#^/approvals/steps/(\d+)/delete$#', $path, $m) === 1:
                hr_post_approval_step_delete((int) $m[1]); return true;
            case preg_match('#^/approvals/request/(\d+)/decide$#', $path, $m) === 1:
                hr_post_approval_decide((int) $m[1]); return true;
            case preg_match('#^/approvals/request/(\d+)/cancel$#', $path, $m) === 1:
                hr_post_approval_cancel((int) $m[1]); return true;
        }
        return false;
    }
    return false;
}

/* -------------------------------------------------------------------- tabs ---- */

function hr_approval_tabs(string $active): void {
    $tabs = [
        ['/hr/approvals', 'Inbox', 'bi-inbox'],
        ['/hr/approvals/new', 'New Request', 'bi-plus-circle'],
        ['/hr/approvals/workflows', 'Workflows', 'bi-diagram-3'],
    ];
    echo '<ul class="nav nav-pills flex-wrap gap-1 mb-3">';
    foreach ($tabs as $t) {
        $cls = $t[0] === $active ? 'active' : '';
        echo '<li class="nav-item"><a class="nav-link py-1 px-2 small ' . $cls . '" href="' . esc(base_url($t[0])) . '">'
            . '<i class="bi ' . $t[2] . ' me-1"></i>' . $t[1] . '</a></li>';
    }
    echo '</ul>';
}

/* ------------------------------------------------------------------- inbox ---- */

function hr_page_approvals(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'EMPLOYEE']);
    hr_seed();
    $stats = hr_approval_stats();
    $pending = hr_approval_pending_for($u);
    $mine = hr_approval_mine((string) $u['email']);
    $all = hr_is_manager($u) ? hr_approval_requests(['limit' => 200]) : [];

    layout_open('Approvals', $u, '/hr/approvals');
    hr_tabs('/hr/approvals');
    hr_approval_tabs('/hr/approvals');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-check2-square me-2 text-primary"></i>Approvals</h4>
    <div class="small text-muted">Requests routed through your configured approval chains.</div>
  </div>
  <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/approvals/new')) ?>"><i class="bi bi-plus-lg me-1"></i>New Request</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><?= hr_approval_stat_card('Awaiting me', count($pending), 'text-warning') ?></div>
  <div class="col-6 col-md-3"><?= hr_approval_stat_card('Open (all)', $stats['pending']) ?></div>
  <div class="col-6 col-md-3"><?= hr_approval_stat_card('Approved', $stats['approved'], 'text-success') ?></div>
  <div class="col-6 col-md-3"><?= hr_approval_stat_card('Rejected', $stats['rejected'], 'text-danger') ?></div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2"><i class="bi bi-inbox me-1 text-primary"></i>Awaiting your decision (<?= count($pending) ?>)</div>
  <?php if ($pending === []): ?><div class="small text-muted">Nothing is waiting on you right now.</div><?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th>Title</th><th>Type</th><th>Requested by</th><th>Step</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pending as $r): ?>
      <tr>
        <td class="small"><?= (int) $r['id'] ?></td>
        <td class="small"><a href="<?= esc(base_url('/hr/approvals/' . (int) $r['id'])) ?>"><?= esc((string) $r['title']) ?></a></td>
        <td class="small"><?= esc(hr_approval_request_type_label((string) $r['request_type'])) ?></td>
        <td class="small"><?= esc((string) $r['requested_by']) ?></td>
        <td class="small"><?= (int) $r['current_step'] ?>/<?= (int) $r['total_steps'] ?> &middot; <?= esc((string) $r['step_label']) ?></td>
        <td class="small text-muted"><?= esc(dt_fmt((string) $r['created_at'], 'd M, H:i')) ?></td>
        <td class="text-end text-nowrap">
          <form method="post" action="<?= esc(base_url('/hr/approvals/request/' . (int) $r['id'] . '/decide')) ?>" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="approve">
            <button class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Approve</button>
          </form>
          <form method="post" action="<?= esc(base_url('/hr/approvals/request/' . (int) $r['id'] . '/decide')) ?>" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="reject">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Reject</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2"><i class="bi bi-person me-1 text-primary"></i>My requests (<?= count($mine) ?>)</div>
  <?php if ($mine === []): ?><div class="small text-muted">You have not raised any requests.</div><?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th>Title</th><th>Type</th><th>Progress</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($mine as $r): ?>
      <tr>
        <td class="small"><?= (int) $r['id'] ?></td>
        <td class="small"><a href="<?= esc(base_url('/hr/approvals/' . (int) $r['id'])) ?>"><?= esc((string) $r['title']) ?></a></td>
        <td class="small"><?= esc(hr_approval_request_type_label((string) $r['request_type'])) ?></td>
        <td class="small"><?= (int) $r['current_step'] ?>/<?= (int) $r['total_steps'] ?></td>
        <td><?= hr_approval_status_badge((string) $r['status']) ?></td>
        <td class="small text-muted"><?= esc(dt_fmt((string) $r['created_at'], 'd M')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php if (hr_is_manager($u)): ?>
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="sec-title mb-0"><i class="bi bi-list-check me-1 text-primary"></i>All requests (<?= count($all) ?>)</div>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/approvals/workflows')) ?>">Manage workflows</a>
  </div>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th>Title</th><th>Type</th><th>Requested by</th><th>Step</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($all as $r): ?>
      <tr>
        <td class="small"><?= (int) $r['id'] ?></td>
        <td class="small"><a href="<?= esc(base_url('/hr/approvals/' . (int) $r['id'])) ?>"><?= esc((string) $r['title']) ?></a></td>
        <td class="small"><?= esc(hr_approval_request_type_label((string) $r['request_type'])) ?></td>
        <td class="small"><?= esc((string) $r['requested_by']) ?></td>
        <td class="small"><?= (int) $r['current_step'] ?>/<?= (int) $r['total_steps'] ?></td>
        <td><?= hr_approval_status_badge((string) $r['status']) ?></td>
        <td class="small text-muted"><?= esc(dt_fmt((string) $r['created_at'], 'd M')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($all === []): ?><tr><td colspan="7" class="text-center text-muted py-3">No requests yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
    <?php
    layout_close();
}

function hr_approval_stat_card(string $label, $value, string $cls = ''): string {
    return '<div class="card p-3"><div class="text-muted small">' . esc($label) . '</div>'
        . '<div class="fs-4 fw-bold ' . esc($cls) . '">' . esc((string) $value) . '</div></div>';
}

/* ---------------------------------------------------------------- new request -- */

function hr_page_approvals_new(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'EMPLOYEE']);
    hr_seed();
    $workflows = hr_approval_workflows(true);

    layout_open('New Approval Request', $u, '/hr/approvals');
    hr_tabs('/hr/approvals');
    hr_approval_tabs('/hr/approvals/new');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>New Approval Request</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/approvals')) ?>">Back to Inbox</a>
</div>

<?php if ($workflows === []): ?>
  <div class="alert alert-info small">No active workflows yet. HR can define one under
    <a href="<?= esc(base_url('/hr/approvals/workflows')) ?>">Workflows</a>; without one, your request is routed to HR for review.</div>
<?php endif; ?>

<div class="card p-3">
  <form method="post" action="<?= esc(base_url('/hr/approvals/request/create')) ?>" class="row g-3">
    <?= csrf_field() ?>
    <div class="col-12 col-md-6">
      <label class="form-label small mb-1">Workflow</label>
      <select class="form-select form-select-sm" name="workflow_id">
        <option value="">Route to HR (default)</option>
        <?php foreach ($workflows as $w): ?>
          <option value="<?= (int) $w['id'] ?>"><?= esc((string) $w['name']) ?> &middot; <?= esc(hr_approval_request_type_label((string) $w['request_type'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label small mb-1">Title</label>
      <input class="form-control form-control-sm" name="title" required maxlength="200" placeholder="e.g. Client visit travel reimbursement">
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label small mb-1">Amount (optional)</label>
      <input class="form-control form-control-sm" name="amount" maxlength="60" placeholder="e.g. 25000">
    </div>
    <div class="col-12">
      <label class="form-label small mb-1">Details</label>
      <textarea class="form-control form-control-sm" name="body" rows="4" placeholder="Explain what needs approval and why."></textarea>
    </div>
    <div class="col-12">
      <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Submit request</button>
    </div>
  </form>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------- request detail -- */

function hr_page_approval_request(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'EMPLOYEE']);
    $r = hr_approval_request($id);
    if ($r === null) { http_response_code(404); echo 'Request not found.'; exit; }
    $isOwner = strtolower((string) $r['requested_by']) === strtolower((string) $u['email']);
    if (!$isOwner && !hr_is_manager($u) && !hr_approval_user_is_approver($u, $r)) {
        http_response_code(403); echo 'Not allowed.'; exit;
    }
    $steps = hr_approval_request_steps($id);
    $canDecide = strtoupper((string) $r['status']) === 'PENDING' && hr_approval_user_is_approver($u, $r);
    $canCancel = strtoupper((string) $r['status']) === 'PENDING' && ($isOwner || hr_is_manager($u));

    layout_open('Approval Request #' . $id, $u, '/hr/approvals');
    hr_tabs('/hr/approvals');
    hr_approval_tabs('/hr/approvals');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $r['title']) ?></h4>
    <div class="small text-muted">#<?= (int) $r['id'] ?> &middot; <?= esc(hr_approval_request_type_label((string) $r['request_type'])) ?>
      &middot; by <?= esc((string) $r['requested_by']) ?> &middot; <?= esc(dt_fmt((string) $r['created_at'])) ?></div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?= hr_approval_status_badge((string) $r['status']) ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/approvals')) ?>">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">Details</div>
      <?php if (trim((string) $r['amount']) !== ''): ?>
        <div class="small mb-1"><span class="text-muted">Amount:</span> <?= esc((string) $r['amount']) ?></div>
      <?php endif; ?>
      <div class="small" style="white-space:pre-wrap"><?= esc((string) $r['body']) ?></div>
    </div>

    <div class="card p-3">
      <div class="sec-title mb-2">Approval chain</div>
      <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Approver</th><th>Status</th><th>Decided by</th><th>When</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($steps as $s): ?>
          <tr class="<?= (int) $s['step_no'] === (int) $r['current_step'] && strtoupper((string) $r['status']) === 'PENDING' ? 'table-warning' : '' ?>">
            <td class="small"><?= (int) $s['step_no'] ?></td>
            <td class="small"><?= esc(hr_approval_approver_label((string) $s['approver_kind'], (string) $s['approver_value'])) ?>
              <?php if (trim((string) $s['label']) !== ''): ?><div class="text-muted"><?= esc((string) $s['label']) ?></div><?php endif; ?></td>
            <td><?= hr_approval_status_badge((string) $s['status']) ?></td>
            <td class="small"><?= esc((string) ($s['decided_by'] ?: '-')) ?></td>
            <td class="small text-muted"><?= esc($s['decided_at'] ? dt_fmt((string) $s['decided_at'], 'd M, H:i') : '-') ?></td>
            <td class="small"><?= esc((string) ($s['note'] ?: '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <?php if ($canDecide): ?>
      <div class="card p-3 mb-3">
        <div class="sec-title mb-2">Your decision (step <?= (int) $r['current_step'] ?>)</div>
        <form method="post" action="<?= esc(base_url('/hr/approvals/request/' . $id . '/decide')) ?>" class="row g-2">
          <?= csrf_field() ?>
          <div class="col-12"><label class="form-label small mb-1">Note (optional)</label>
            <input class="form-control form-control-sm" name="note" maxlength="400"></div>
          <div class="col-12 d-flex gap-2">
            <button class="btn btn-success btn-sm" name="action" value="approve"><i class="bi bi-check-lg me-1"></i>Approve</button>
            <button class="btn btn-outline-danger btn-sm" name="action" value="reject"><i class="bi bi-x-lg me-1"></i>Reject</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
    <?php if ($canCancel): ?>
      <div class="card p-3">
        <div class="sec-title mb-2">Cancel</div>
        <form method="post" action="<?= esc(base_url('/hr/approvals/request/' . $id . '/cancel')) ?>" onsubmit="return confirm('Cancel this request?')">
          <?= csrf_field() ?>
          <button class="btn btn-outline-secondary btn-sm">Cancel request</button>
        </form>
      </div>
    <?php endif; ?>
    <?php if (strtoupper((string) $r['status']) !== 'PENDING'): ?>
      <div class="card p-3">
        <div class="sec-title mb-2">Outcome</div>
        <div class="small"><?= hr_approval_status_badge((string) $r['status']) ?>
          by <?= esc((string) ($r['decided_by'] ?: '-')) ?> on <?= esc(dt_fmt((string) $r['decided_at'])) ?></div>
        <?php if (trim((string) $r['decision_note']) !== ''): ?><div class="small mt-1">Note: <?= esc((string) $r['decision_note']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
    <?php
    layout_close();
}

/** Is the user an approver on the request's current step? */
function hr_approval_user_is_approver(array $u, array $r): bool {
    if (strtoupper((string) $r['status']) !== 'PENDING') return false;
    $step = one('SELECT * FROM hr_approval_steps WHERE request_id=? AND step_no=?',
        [(int) $r['id'], (int) $r['current_step']]);
    if ($step === null) return false;
    $email = strtolower(trim((string) $u['email']));
    if (in_array($email, hr_approval_step_approver_emails($step, (string) $r['requested_by']), true)) return true;
    return strtoupper((string) $step['approver_kind']) === 'ROLE'
        && strcasecmp((string) ($u['role'] ?? ''), (string) $step['approver_value']) === 0;
}

/* -------------------------------------------------------------- workflow list -- */

function hr_page_approval_workflows(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $workflows = hr_approval_workflows();

    layout_open('Approval Workflows', $u, '/hr/approvals');
    hr_tabs('/hr/approvals');
    hr_approval_tabs('/hr/approvals/workflows');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-diagram-3 me-2 text-primary"></i>Approval Workflows</h4>
    <div class="small text-muted">Ordered approver chains that requests are routed through.</div>
  </div>
  <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/approvals/workflows/new')) ?>"><i class="bi bi-plus-lg me-1"></i>New Workflow</a>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Name</th><th>Type</th><th>Steps</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($workflows as $w): $steps = hr_approval_workflow_steps((int) $w['id']); ?>
      <tr>
        <td class="small"><a href="<?= esc(base_url('/hr/approvals/workflows/' . (int) $w['id'])) ?>"><?= esc((string) $w['name']) ?></a>
          <?php if (trim((string) $w['description']) !== ''): ?><div class="text-muted"><?= esc((string) $w['description']) ?></div><?php endif; ?></td>
        <td class="small"><?= esc(hr_approval_request_type_label((string) $w['request_type'])) ?></td>
        <td class="small"><?= count($steps) ?>
          <?php if ($steps !== []): ?><div class="text-muted"><?= esc(implode(' &rarr; ', array_map(static fn($s) => hr_approval_approver_label((string) $s['approver_kind'], (string) $s['approver_value']), $steps))) ?></div><?php endif; ?></td>
        <td><?= (int) $w['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/approvals/workflows/' . (int) $w['id'])) ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" action="<?= esc(base_url('/hr/approvals/workflows/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this workflow and its steps?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($workflows === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No workflows yet. Create one to start routing requests.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

/* -------------------------------------------------------------- workflow edit -- */

function hr_page_approval_workflow_edit(?int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $w = $id ? hr_approval_workflow($id) : null;
    if ($id && $w === null) { http_response_code(404); echo 'Workflow not found.'; exit; }
    $steps = $w !== null ? hr_approval_workflow_steps((int) $w['id']) : [];
    $next = $w !== null ? hr_approval_next_step_no((int) $w['id']) : 1;

    layout_open($w ? 'Edit Workflow' : 'New Workflow', $u, '/hr/approvals');
    hr_tabs('/hr/approvals');
    hr_approval_tabs('/hr/approvals/workflows');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><?= $w ? 'Edit Workflow' : 'New Workflow' ?></h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/approvals/workflows')) ?>">Back</a>
</div>

<div class="card p-3 mb-3">
  <form method="post" action="<?= esc(base_url('/hr/approvals/workflows/save')) ?>" class="row g-3">
    <?= csrf_field() ?>
    <?php if ($w): ?><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><?php endif; ?>
    <div class="col-12 col-md-6"><label class="form-label small mb-1">Name</label>
      <input class="form-control form-control-sm" name="name" required maxlength="160" value="<?= esc((string) ($w['name'] ?? '')) ?>" placeholder="e.g. Expense approval"></div>
    <div class="col-12 col-md-6"><label class="form-label small mb-1">Request type</label>
      <select class="form-select form-select-sm" name="request_type">
        <?php foreach (hr_approval_request_types() as $k => $lbl): ?>
          <option value="<?= esc($k) ?>" <?= strtoupper((string) ($w['request_type'] ?? 'GENERAL')) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label small mb-1">Description</label>
      <input class="form-control form-control-sm" name="description" maxlength="400" value="<?= esc((string) ($w['description'] ?? '')) ?>"></div>
    <div class="col-12"><div class="form-check">
      <input class="form-check-input" type="checkbox" name="active" value="1" id="wf_active" <?= (!$w || (int) $w['active']) ? 'checked' : '' ?>>
      <label class="form-check-label small" for="wf_active">Active (available when raising requests)</label>
    </div></div>
    <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save workflow</button></div>
  </form>
</div>

<?php if ($w): ?>
<div class="card p-3">
  <div class="sec-title mb-2">Steps (in order)</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>#</th><th>Approver</th><th>Label</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($steps as $s): ?>
      <tr>
        <td class="small" style="width:120px">
          <form method="post" action="<?= esc(base_url('/hr/approvals/workflows/' . (int) $w['id'] . '/step/save')) ?>" class="d-flex gap-1">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <input type="hidden" name="approver_kind" value="<?= esc((string) $s['approver_kind']) ?>">
            <input type="hidden" name="approver_value" value="<?= esc((string) $s['approver_value']) ?>">
            <input type="hidden" name="label" value="<?= esc((string) $s['label']) ?>">
            <input class="form-control form-control-sm" type="number" min="1" name="step_no" value="<?= (int) $s['step_no'] ?>" style="width:56px">
            <button class="btn btn-sm btn-outline-secondary" title="Save order"><i class="bi bi-check"></i></button>
          </form>
        </td>
        <td class="small"><?= esc(hr_approval_approver_label((string) $s['approver_kind'], (string) $s['approver_value'])) ?></td>
        <td class="small"><?= esc((string) $s['label']) ?></td>
        <td class="text-end">
          <form method="post" action="<?= esc(base_url('/hr/approvals/steps/' . (int) $s['id'] . '/delete')) ?>" class="d-inline" onsubmit="return confirm('Remove this step?')">
            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($steps === []): ?><tr><td colspan="4" class="text-center text-muted py-3">No steps yet. Add one below.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>

  <form method="post" action="<?= esc(base_url('/hr/approvals/workflows/' . (int) $w['id'] . '/step/save')) ?>" class="row g-2 align-items-end mt-2">
    <?= csrf_field() ?>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Order</label>
      <input class="form-control form-control-sm" type="number" min="1" name="step_no" value="<?= (int) $next ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Approver kind</label>
      <select class="form-select form-select-sm" name="approver_kind" id="ap_kind" onchange="happrKind(this.value)">
        <?php foreach (hr_approval_kinds() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12 col-md-3" id="ap_value_wrap"><label class="form-label small mb-1">Approver</label>
      <select class="form-select form-select-sm" name="approver_value" id="ap_role">
        <?php foreach (hr_approval_roles() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?>
      </select>
      <input class="form-control form-control-sm d-none" name="approver_value" id="ap_email" placeholder="person@company.com" disabled></div>
    <div class="col-12 col-md-2"><label class="form-label small mb-1">Label</label>
      <input class="form-control form-control-sm" name="label" maxlength="160" placeholder="e.g. Manager review"></div>
    <div class="col-12 col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Add step</button></div>
  </form>
</div>
<script>
function happrKind(kind){
  var role=document.getElementById('ap_role'), email=document.getElementById('ap_email');
  if(kind==='EMAIL'){ role.classList.add('d-none'); role.disabled=true; email.classList.remove('d-none'); email.disabled=false; }
  else if(kind==='MANAGER'){ email.classList.add('d-none'); email.disabled=true; role.classList.add('d-none'); role.disabled=true; }
  else { email.classList.add('d-none'); email.disabled=true; role.classList.remove('d-none'); role.disabled=false; }
}
</script>
<?php endif; ?>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------- posts ---- */

function hr_post_approval_request_create(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'EMPLOYEE']);
    require_csrf();
    $wf = hr_int_or_null($_POST['workflow_id'] ?? null);
    $rid = hr_approval_request_create($wf, $_POST, (string) $u['email']);
    if ($rid === 0) {
        flash('danger', 'Enter a title to raise a request.');
        redirect(base_url('/hr/approvals/new'));
    }
    activity_log($u, 'HR_APPROVAL_REQUESTED', 'Request #' . $rid);
    flash('success', 'Request submitted for approval.');
    redirect(base_url('/hr/approvals/' . $rid));
}

function hr_post_approval_decide(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'EMPLOYEE']);
    require_csrf();
    $err = hr_approval_decide($id, (string) ($_POST['action'] ?? ''), (string) $u['email'], (string) ($_POST['note'] ?? ''));
    if ($err !== '') flash('danger', $err);
    else flash('success', 'Decision recorded.');
    redirect(base_url('/hr/approvals/' . $id));
}

function hr_post_approval_cancel(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'EMPLOYEE']);
    require_csrf();
    $err = hr_approval_cancel($id, $u);
    if ($err !== '') flash('danger', $err); else flash('success', 'Request cancelled.');
    redirect(base_url('/hr/approvals/' . $id));
}

function hr_post_approval_workflow_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $wid = hr_approval_workflow_save($id, $_POST, (string) $u['email']);
    if ($wid === 0) {
        flash('danger', 'A workflow needs a name.');
        redirect(base_url('/hr/approvals/workflows'));
    }
    activity_log($u, 'HR_APPROVAL_WORKFLOW_SAVED', 'Workflow #' . $wid);
    flash('success', 'Workflow saved.');
    redirect(base_url('/hr/approvals/workflows/' . $wid));
}

function hr_post_approval_workflow_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) hr_approval_workflow_delete($id);
    flash('success', 'Workflow deleted.');
    redirect(base_url('/hr/approvals/workflows'));
}

function hr_post_approval_step_save(int $workflowId): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $sid = hr_approval_step_save($workflowId, $id, $_POST);
    if ($sid === 0) flash('danger', 'That approver is not valid.');
    else flash('success', 'Step saved.');
    redirect(base_url('/hr/approvals/workflows/' . $workflowId));
}

function hr_post_approval_step_delete(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $s = hr_approval_step($id);
    hr_approval_step_delete($id);
    flash('success', 'Step removed.');
    redirect(base_url('/hr/approvals/workflows/' . ($s ? (int) $s['workflow_id'] : '')));
}
