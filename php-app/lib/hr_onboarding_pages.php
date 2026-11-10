<?php
/**
 * WorkFolio PHP - HR module screens, Phase 2: Onboarding.
 *
 * Mounted through hr_onboarding_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/onboarding* path. HR/Admin manage templates and
 * plans; any signed-in person can open /hr/onboarding/me and work the items
 * that are theirs. Nothing here reads or writes a table it does not own.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';       /* hr_tabs(), hr_is_manager() */
require_once __DIR__ . '/hr_onboarding.php';

/* ------------------------------------------------------------------- router ---- */

function hr_onboarding_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/onboarding':                 hr_page_onboarding_overview(); return true;
            case $path === '/onboarding/templates':       hr_page_onboarding_templates(); return true;
            case $path === '/onboarding/plans':           hr_page_onboarding_plans(); return true;
            case $path === '/onboarding/new':             hr_page_onboarding_new(); return true;
            case $path === '/onboarding/me':              hr_page_onboarding_me(); return true;
            case preg_match('#^/onboarding/templates/(\d+)$#', $path, $m) === 1:
                hr_page_onboarding_template((int) $m[1]); return true;
            case preg_match('#^/onboarding/plans/(\d+)$#', $path, $m) === 1:
                hr_page_onboarding_plan((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/onboarding/template/save':      hr_post_onboarding_template_save(); return true;
            case $path === '/onboarding/template/delete':    hr_post_onboarding_template_delete(); return true;
            case $path === '/onboarding/template/task/save': hr_post_onboarding_template_task_save(); return true;
            case $path === '/onboarding/template/task/delete': hr_post_onboarding_template_task_delete(); return true;
            case $path === '/onboarding/plan/create':        hr_post_onboarding_plan_create(); return true;
            case $path === '/onboarding/plan/status':        hr_post_onboarding_plan_status(); return true;
            case $path === '/onboarding/plan/delete':        hr_post_onboarding_plan_delete(); return true;
            case $path === '/onboarding/plan/task/save':     hr_post_onboarding_plan_task_save(); return true;
            case $path === '/onboarding/plan/task/toggle':   hr_post_onboarding_plan_task_toggle(); return true;
            case $path === '/onboarding/plan/task/delete':   hr_post_onboarding_plan_task_delete(); return true;
            case preg_match('#^/onboarding/me/task/(\d+)/toggle$#', $path, $m) === 1:
                hr_post_onboarding_me_task_toggle((int) $m[1]); return true;
        }
        return false;
    }
    return false;
}

/* ------------------------------------------------------------------ helpers ---- */

function hro_progress_bar(array $p): string {
    $pct = (int) ($p['percent'] ?? 0);
    $cls = $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-primary' : 'bg-warning');
    return '<div class="progress" style="height:6px"><div class="progress-bar ' . $cls . '" style="width:' . $pct . '%"></div></div>';
}

function hro_org_selects(array $map, string $name, $selected): void {
    echo '<select class="form-select form-select-sm" name="' . esc($name) . '"><option value="">&mdash;</option>';
    foreach ($map as $id => $label) {
        $sel = ((int) $selected === (int) $id) ? ' selected' : '';
        echo '<option value="' . (int) $id . '"' . $sel . '>' . esc((string) $label) . '</option>';
    }
    echo '</select>';
}

/** A compact self-service card appended to /hr/me. Safe when no plan exists. */
function hr_onboarding_me_card(string $email): void {
    $plan = hr_onboarding_plan_by_email($email);
    if ($plan === null) return;
    $tasks = hr_onboarding_plan_tasks((int) $plan['id']);
    $prog = hr_onboarding_plan_progress((int) $plan['id']);
    $uid = strtolower(trim($email));
    ?>
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0"><i class="bi bi-person-plus me-1 text-primary"></i>My Onboarding</div>
        <?= hr_onboarding_plan_status_badge((string) $plan['status']) ?>
      </div>
      <div class="small text-muted mb-1"><?= (int) $prog['done'] ?> / <?= (int) $prog['total'] ?> done
        <?= $prog['overdue'] > 0 ? '&middot; <span class="text-danger">' . (int) $prog['overdue'] . ' overdue</span>' : '' ?></div>
      <?= hro_progress_bar($prog) ?>
      <table class="table table-sm mt-2 mb-0">
        <tbody>
        <?php foreach ($tasks as $t): $mine = strtolower((string) ($t['assignee_email'] ?? '')) === $uid
            || strtoupper((string) $t['owner_role']) === 'EMPLOYEE';
            $closed = in_array(strtoupper((string) $t['status']), ['DONE', 'SKIPPED'], true); ?>
          <tr>
            <td class="small"><?= esc((string) $t['title']) ?>
              <div class="text-muted"><?= esc(hr_onboarding_category_label((string) $t['category'])) ?></div></td>
            <td><?= hr_onboarding_task_status_badge((string) $t['status']) ?></td>
            <td class="text-end">
              <?php if ($mine && !$closed): ?>
                <form method="post" action="<?= esc(base_url('/hr/onboarding/me/task/' . (int) $t['id'] . '/toggle')) ?>" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="status" value="DONE">
                  <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($tasks === []): ?><tr><td colspan="3" class="text-center text-muted py-2">No tasks yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <a class="btn btn-sm btn-outline-primary mt-2" href="<?= esc(base_url('/hr/onboarding/me')) ?>">Open my onboarding</a>
    </div>
    <?php
}

/* ----------------------------------------------------------------- overview ---- */

function hr_page_onboarding_overview(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $stats = hr_onboarding_stats();
    $plans = hr_onboarding_plans(['limit' => 15]);

    layout_open('Onboarding', $u, '/hr/onboarding');
    hr_tabs('/hr/onboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>Onboarding</h4>
    <div class="small text-muted">Checklists for new hires, stamped out from reusable templates.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/onboarding/new')) ?>"><i class="bi bi-plus-lg me-1"></i>Start a plan</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/onboarding/templates')) ?>">Templates</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/onboarding/plans')) ?>">All plans</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">In Progress</div><div class="fs-4 fw-bold text-primary"><?= (int) $stats['active_plans'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Pending Tasks</div><div class="fs-4 fw-bold"><?= (int) $stats['pending_tasks'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Overdue Tasks</div><div class="fs-4 fw-bold text-danger"><?= (int) $stats['overdue_tasks'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Completed (Month)</div><div class="fs-4 fw-bold text-success"><?= (int) $stats['completed_month'] ?></div></div></div>
</div>

<div class="card p-3">
  <div class="sec-title mb-2">Recent Plans</div>
  <?php if ($plans === []): ?>
    <div class="small text-muted">No onboarding plans yet. <a href="<?= esc(base_url('/hr/onboarding/new')) ?>">Start one</a>.</div>
  <?php else: ?>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light"><tr><th>New Hire</th><th>Designation</th><th>Template</th><th>Progress</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): $tot = (int) $p['task_count']; $dn = (int) $p['done_count'];
          $pct = $tot > 0 ? (int) round($dn / $tot * 100) : 0; ?>
        <tr>
          <td class="small"><div class="fw-semibold"><?= esc((string) ($p['full_name'] ?: $p['email'])) ?></div><div class="text-muted"><?= esc((string) $p['email']) ?></div></td>
          <td class="small"><?= esc((string) ($p['designation'] ?? '-')) ?></td>
          <td class="small"><?= esc((string) ($p['template_name'] ?? '-')) ?></td>
          <td style="min-width:120px"><?= hro_progress_bar(['percent' => $pct]) ?><div class="text-muted" style="font-size:.72rem"><?= $dn ?>/<?= $tot ?></div></td>
          <td><?= hr_onboarding_plan_status_badge((string) $p['status']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/onboarding/plans/' . (int) $p['id'])) ?>">Open</a></td>
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

/* ---------------------------------------------------------------- templates ---- */

function hr_page_onboarding_templates(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $maps = hr_org_maps();
    $templates = hr_onboarding_templates_all();

    layout_open('Onboarding Templates', $u, '/hr/onboarding');
    hr_tabs('/hr/onboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-list-check me-2 text-primary"></i>Onboarding Templates</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/onboarding')) ?>">Back to Onboarding</a>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">New Template</div>
  <form method="post" action="<?= esc(base_url('/hr/onboarding/template/save')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" required></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Department</label><?php hro_org_selects($maps['departments'], 'department_id', 0); ?></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Branch</label><?php hro_org_selects($maps['branches'], 'branch_id', 0); ?></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Employment Type</label>
        <select class="form-select form-select-sm" name="employment_type"><option value="">&mdash;</option>
          <?php foreach (hr_employment_types() as $t): ?><option><?= esc($t) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked id="tpl-active"><label class="form-check-label small" for="tpl-active">Active</label></div></div>
      <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="2"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Create template</button></div>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Name</th><th>Department</th><th>Branch</th><th>Type</th><th>Items</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($templates as $t): ?>
      <tr>
        <td class="fw-semibold small"><?= esc((string) $t['name']) ?></td>
        <td class="small"><?= esc((string) ($t['department_name'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($t['branch_name'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($t['employment_type'] ?? '-')) ?></td>
        <td class="small"><?= (int) $t['task_count'] ?></td>
        <td><?= ((int) $t['active'] === 1) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/onboarding/templates/' . (int) $t['id'])) ?>">Open</a>
          <form method="post" action="<?= esc(base_url('/hr/onboarding/template/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this template? Existing plans keep their tasks.')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($templates === []): ?><tr><td colspan="7" class="text-center text-muted py-3">No templates yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_onboarding_template(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $t = hr_onboarding_template($id);
    if ($t === null) { http_response_code(404); echo 'Template not found.'; exit; }
    $maps = hr_org_maps();
    $tasks = hr_onboarding_template_tasks($id);

    layout_open('Template - ' . (string) $t['name'], $u, '/hr/onboarding');
    hr_tabs('/hr/onboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $t['name']) ?></h4>
    <div class="small text-muted">
      <?= esc((string) ($maps['departments'][(int) ($t['department_id'] ?? 0)] ?? 'All departments')) ?>
      &middot; <?= esc((string) ($maps['branches'][(int) ($t['branch_id'] ?? 0)] ?? 'All branches')) ?>
      &middot; <?= ((int) $t['active'] === 1) ? 'Active' : 'Inactive' ?>
    </div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/onboarding/templates')) ?>">Back</a>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Template Settings</div>
  <form method="post" action="<?= esc(base_url('/hr/onboarding/template/save')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" value="<?= esc((string) $t['name']) ?>" required></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Department</label><?php hro_org_selects($maps['departments'], 'department_id', $t['department_id'] ?? 0); ?></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Branch</label><?php hro_org_selects($maps['branches'], 'branch_id', $t['branch_id'] ?? 0); ?></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Employment Type</label>
        <select class="form-select form-select-sm" name="employment_type"><option value="">&mdash;</option>
          <?php foreach (hr_employment_types() as $et): ?><option <?= (string) $t['employment_type'] === $et ? 'selected' : '' ?>><?= esc($et) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" <?= (int) $t['active'] === 1 ? 'checked' : '' ?> id="tpl-active"><label class="form-check-label small" for="tpl-active">Active</label></div></div>
      <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="2"><?= esc((string) ($t['description'] ?? '')) ?></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save settings</button></div>
    </div>
  </form>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Checklist Items (<?= count($tasks) ?>)</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th style="width:70px">Order</th><th>Title</th><th>Category</th><th>Owner</th><th>Due After</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $it): ?>
      <tr>
        <td><input form="tt-<?= (int) $it['id'] ?>" type="number" class="form-control form-control-sm" name="sort_order" value="<?= (int) $it['sort_order'] ?>"></td>
        <td><input form="tt-<?= (int) $it['id'] ?>" class="form-control form-control-sm" name="title" value="<?= esc((string) $it['title']) ?>" required></td>
        <td><select form="tt-<?= (int) $it['id'] ?>" class="form-select form-select-sm" name="category">
          <?php foreach (hr_onboarding_categories() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $it['category']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
        </select></td>
        <td><select form="tt-<?= (int) $it['id'] ?>" class="form-select form-select-sm" name="owner_role">
          <?php foreach (hr_onboarding_owner_roles() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $it['owner_role']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
        </select></td>
        <td><div class="input-group input-group-sm" style="width:130px"><input form="tt-<?= (int) $it['id'] ?>" type="number" min="0" class="form-control" name="due_offset_days" value="<?= (int) $it['due_offset_days'] ?>"><span class="input-group-text">days</span></div></td>
        <td class="text-end text-nowrap">
          <form id="tt-<?= (int) $it['id'] ?>" method="post" action="<?= esc(base_url('/hr/onboarding/template/task/save')) ?>" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-sm btn-outline-primary" title="Save"><i class="bi bi-save"></i></button>
          </form>
          <form method="post" action="<?= esc(base_url('/hr/onboarding/template/task/delete')) ?>" class="d-inline" onsubmit="return confirm('Remove this item?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($tasks === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No items yet. Add the first below.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= esc(base_url('/hr/onboarding/template/task/save')) ?>" class="row g-2 mt-1 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
    <div class="col-12 col-md-4"><label class="form-label small mb-1">New item</label><input class="form-control form-control-sm" name="title" placeholder="e.g. Collect signed contract" required></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Category</label>
      <select class="form-select form-select-sm" name="category"><?php foreach (hr_onboarding_categories() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Owner</label>
      <select class="form-select form-select-sm" name="owner_role"><?php foreach (hr_onboarding_owner_roles() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Due After</label><div class="input-group input-group-sm"><input type="number" min="0" class="form-control" name="due_offset_days" value="0"><span class="input-group-text">days</span></div></div>
    <div class="col-6 col-md-2"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Add item</button></div>
  </form>
</div>
    <?php
    layout_close();
}

function hr_post_onboarding_template_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $newId = hr_onboarding_template_save($id, $_POST, (string) $u['email']);
    if ($newId === 0) {
        flash('danger', 'A template needs a name.');
        redirect(base_url('/hr/onboarding/templates'));
    }
    activity_log($u, $id ? 'HR_ONBOARDING_TEMPLATE_SAVED' : 'HR_ONBOARDING_TEMPLATE_CREATED', (string) ($_POST['name'] ?? ''));
    flash('success', 'Template saved.');
    redirect(base_url('/hr/onboarding/templates/' . $newId));
}

function hr_post_onboarding_template_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_onboarding_template_delete($id);
    activity_log($u, 'HR_ONBOARDING_TEMPLATE_DELETED', 'Template #' . $id);
    flash('success', 'Template deleted.');
    redirect(base_url('/hr/onboarding/templates'));
}

function hr_post_onboarding_template_task_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $templateId = (int) ($_POST['template_id'] ?? 0);
    if (hr_onboarding_template($templateId) === null) { http_response_code(404); echo 'Template not found.'; exit; }
    $id = hr_int_or_null($_POST['id'] ?? null);
    hr_onboarding_template_task_save($id, $templateId, $_POST);
    flash('success', 'Checklist item saved.');
    redirect(base_url('/hr/onboarding/templates/' . $templateId));
}

function hr_post_onboarding_template_task_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $it = hr_onboarding_template_task($id);
    hr_onboarding_template_task_delete($id);
    flash('success', 'Checklist item removed.');
    redirect(base_url('/hr/onboarding/templates/' . (int) ($it['template_id'] ?? 0)));
}

/* -------------------------------------------------------------------- plans ---- */

function hr_page_onboarding_plans(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $filters = ['limit' => 300];
    if ($fStatus !== '') $filters['status'] = $fStatus;
    $plans = hr_onboarding_plans($filters);

    layout_open('Onboarding Plans', $u, '/hr/onboarding');
    hr_tabs('/hr/onboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Onboarding Plans</h4>
  <div class="d-flex gap-2 align-items-center">
    <div class="btn-group btn-group-sm">
      <?php foreach (['' => 'All'] + hr_onboarding_plan_statuses() as $k => $lbl): ?>
        <a class="btn btn-outline-secondary <?= $fStatus === $k ? 'active' : '' ?>" href="?status=<?= esc($k) ?>"><?= esc($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/onboarding/new')) ?>"><i class="bi bi-plus-lg me-1"></i>Start a plan</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>New Hire</th><th>Designation</th><th>Joining</th><th>Template</th><th>Progress</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($plans as $p): $tot = (int) $p['task_count']; $dn = (int) $p['done_count']; $pct = $tot > 0 ? (int) round($dn / $tot * 100) : 0; ?>
      <tr>
        <td class="small"><div class="fw-semibold"><?= esc((string) ($p['full_name'] ?: $p['email'])) ?></div><div class="text-muted"><?= esc((string) $p['email']) ?></div></td>
        <td class="small"><?= esc((string) ($p['designation'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($p['joining_date'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($p['template_name'] ?? '-')) ?></td>
        <td style="min-width:120px"><?= hro_progress_bar(['percent' => $pct]) ?><div class="text-muted" style="font-size:.72rem"><?= $dn ?>/<?= $tot ?></div></td>
        <td><?= hr_onboarding_plan_status_badge((string) $p['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/onboarding/plans/' . (int) $p['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($plans === []): ?><tr><td colspan="7" class="text-center text-muted py-3">No plans match.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_onboarding_new(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $maps = hr_org_maps();
    $templates = hr_onboarding_templates_all(true);

    layout_open('Start Onboarding', $u, '/hr/onboarding');
    hr_tabs('/hr/onboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>Start an Onboarding Plan</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/onboarding')) ?>">Back</a>
</div>

<div class="card p-3">
  <form method="post" action="<?= esc(base_url('/hr/onboarding/plan/create')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">New hire email</label><input type="email" class="form-control form-control-sm" name="email" required></div>
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Full name</label><input class="form-control form-control-sm" name="full_name"></div>
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Template</label>
        <select class="form-select form-select-sm" name="template_id"><option value="">None (empty plan)</option>
          <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= esc((string) $t['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Department</label><?php hro_org_selects($maps['departments'], 'department_id', 0); ?></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Branch</label><?php hro_org_selects($maps['branches'], 'branch_id', 0); ?></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Designation</label><input class="form-control form-control-sm" name="designation"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Employment Type</label>
        <select class="form-select form-select-sm" name="employment_type"><option value="">&mdash;</option>
          <?php foreach (hr_employment_types() as $t): ?><option><?= esc($t) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Joining date</label><input type="date" class="form-control form-control-sm" name="joining_date"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Target completion</label><input type="date" class="form-control form-control-sm" name="target_date"></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Create plan</button></div>
    </div>
  </form>
</div>
    <?php
    layout_close();
}

function hr_page_onboarding_plan(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $p = hr_onboarding_plan($id);
    if ($p === null) { http_response_code(404); echo 'Plan not found.'; exit; }
    $maps = hr_org_maps();
    $tasks = hr_onboarding_plan_tasks($id);
    $prog = hr_onboarding_plan_progress($id);

    layout_open('Onboarding - ' . (string) ($p['full_name'] ?: $p['email']), $u, '/hr/onboarding');
    hr_tabs('/hr/onboarding');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) ($p['full_name'] ?: $p['email'])) ?></h4>
    <div class="small text-muted">
      <?= esc((string) $p['email']) ?>
      &middot; <?= esc((string) ($maps['departments'][(int) ($p['department_id'] ?? 0)] ?? '-')) ?>
      &middot; <?= esc((string) ($p['designation'] ?? '-')) ?>
      &middot; joining <?= esc((string) ($p['joining_date'] ?? '-')) ?>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_onboarding_plan_status_badge((string) $p['status']) ?>
    <form method="post" action="<?= esc(base_url('/hr/onboarding/plan/status')) ?>" class="d-flex gap-1">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <?php foreach (hr_onboarding_plan_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $p['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
      </select>
    </form>
    <form method="post" action="<?= esc(base_url('/hr/onboarding/plan/delete')) ?>" onsubmit="return confirm('Delete this plan and its checklist?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/onboarding/plans')) ?>">Back</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="sec-title mb-0">Progress</div>
    <div class="small text-muted"><?= (int) $prog['done'] ?> / <?= (int) $prog['total'] ?> done
      <?= $prog['overdue'] > 0 ? '&middot; <span class="text-danger">' . (int) $prog['overdue'] . ' overdue</span>' : '' ?>
      &middot; <?= (int) $prog['percent'] ?>%</div>
  </div>
  <?= hro_progress_bar($prog) ?>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Checklist (<?= count($tasks) ?>)</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>Item</th><th>Category</th><th>Owner</th><th>Assignee</th><th>Due</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t): $overdue = $t['due_date'] !== '' && (string) $t['due_date'] < today()
        && !in_array(strtoupper((string) $t['status']), ['DONE', 'SKIPPED'], true); ?>
      <tr>
        <td class="small"><?= esc((string) $t['title']) ?><?php if (trim((string) $t['notes']) !== ''): ?><div class="text-muted" style="font-size:.72rem"><?= esc((string) $t['notes']) ?></div><?php endif; ?></td>
        <td class="small"><?= esc(hr_onboarding_category_label((string) $t['category'])) ?></td>
        <td class="small"><?= esc(hr_onboarding_owner_roles()[strtoupper((string) $t['owner_role'])] ?? (string) $t['owner_role']) ?></td>
        <td class="small"><?= esc((string) ($t['assignee_email'] ?? '-')) ?></td>
        <td class="small <?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= esc((string) ($t['due_date'] ?? '-')) ?></td>
        <td><?= hr_onboarding_task_status_badge((string) $t['status']) ?></td>
        <td class="text-end text-nowrap">
          <form method="post" action="<?= esc(base_url('/hr/onboarding/plan/task/toggle')) ?>" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
            <select class="form-select form-select-sm d-inline-block w-auto" name="status" onchange="this.form.submit()">
              <?php foreach (hr_onboarding_task_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $t['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
            </select>
          </form>
          <form method="post" action="<?= esc(base_url('/hr/onboarding/plan/task/delete')) ?>" class="d-inline" onsubmit="return confirm('Remove this item?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($tasks === []): ?><tr><td colspan="7" class="text-center text-muted py-3">No items. Add one below.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= esc(base_url('/hr/onboarding/plan/task/save')) ?>" class="row g-2 mt-1 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
    <div class="col-12 col-md-4"><label class="form-label small mb-1">New item</label><input class="form-control form-control-sm" name="title" required></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Category</label>
      <select class="form-select form-select-sm" name="category"><?php foreach (hr_onboarding_categories() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Owner</label>
      <select class="form-select form-select-sm" name="owner_role"><?php foreach (hr_onboarding_owner_roles() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Assignee (email)</label><input class="form-control form-control-sm" name="assignee_email"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Due date</label><input type="date" class="form-control form-control-sm" name="due_date"></div>
    <div class="col-12"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add item</button></div>
  </form>
</div>
    <?php
    layout_close();
}

function hr_post_onboarding_plan_create(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_onboarding_plan_create($_POST, (string) $u['email']);
    if ($id === 0) {
        flash('danger', 'A plan needs a valid new-hire email.');
        redirect(base_url('/hr/onboarding/new'));
    }
    activity_log($u, 'HR_ONBOARDING_PLAN_CREATED', 'Plan #' . $id . ' for ' . (string) ($_POST['email'] ?? ''));
    flash('success', 'Onboarding plan started.');
    redirect(base_url('/hr/onboarding/plans/' . $id));
}

function hr_post_onboarding_plan_status(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!hr_onboarding_plan_set_status($id, (string) ($_POST['status'] ?? ''), (string) $u['email'])) {
        flash('danger', 'Unknown plan status.');
    } else {
        flash('success', 'Plan status updated.');
    }
    redirect(base_url('/hr/onboarding/plans/' . $id));
}

function hr_post_onboarding_plan_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_onboarding_plan_delete($id);
    activity_log($u, 'HR_ONBOARDING_PLAN_DELETED', 'Plan #' . $id);
    flash('success', 'Plan deleted.');
    redirect(base_url('/hr/onboarding/plans'));
}

function hr_post_onboarding_plan_task_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if (hr_onboarding_plan($planId) === null) { http_response_code(404); echo 'Plan not found.'; exit; }
    $id = hr_int_or_null($_POST['id'] ?? null);
    hr_onboarding_plan_task_save($id, $planId, $_POST, (string) $u['email']);
    hr_onboarding_recheck_plan($planId, (string) $u['email']);
    flash('success', 'Checklist item saved.');
    redirect(base_url('/hr/onboarding/plans/' . $planId));
}

function hr_post_onboarding_plan_task_toggle(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if (!hr_onboarding_plan_task_toggle($id, (string) ($_POST['status'] ?? ''), (string) $u['email'])) {
        flash('danger', 'Unknown task status.');
    } else {
        flash('success', 'Checklist updated.');
    }
    redirect(base_url('/hr/onboarding/plans/' . $planId));
}

function hr_post_onboarding_plan_task_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $t = hr_onboarding_plan_task($id);
    hr_onboarding_plan_task_delete($id);
    flash('success', 'Checklist item removed.');
    redirect(base_url('/hr/onboarding/plans/' . (int) ($t['plan_id'] ?? 0)));
}

/* ------------------------------------------------------------- self-service ---- */

function hr_page_onboarding_me(): void {
    $u = require_login();
    $email = strtolower((string) $u['email']);
    $plan = hr_onboarding_plan_by_email($email);

    layout_open('My Onboarding', $u, '/hr/me');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>My Onboarding</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/me')) ?>">Back to My HR</a>
</div>
<?php if ($plan === null): ?>
  <div class="card p-3"><div class="small text-muted">You have no onboarding plan assigned.</div></div>
<?php else:
    $tasks = hr_onboarding_plan_tasks((int) $plan['id']);
    $prog = hr_onboarding_plan_progress((int) $plan['id']); ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <div class="sec-title mb-0">Progress</div>
      <?= hr_onboarding_plan_status_badge((string) $plan['status']) ?>
    </div>
    <div class="small text-muted mb-1"><?= (int) $prog['done'] ?> / <?= (int) $prog['total'] ?> done<?= $prog['overdue'] > 0 ? ' &middot; <span class="text-danger">' . (int) $prog['overdue'] . ' overdue</span>' : '' ?></div>
    <?= hro_progress_bar($prog) ?>
  </div>
  <div class="card p-3">
    <div class="sec-title mb-2">Checklist</div>
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Item</th><th>Category</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): $mine = strtolower((string) ($t['assignee_email'] ?? '')) === $email
          || strtoupper((string) $t['owner_role']) === 'EMPLOYEE';
          $closed = in_array(strtoupper((string) $t['status']), ['DONE', 'SKIPPED'], true); ?>
        <tr>
          <td class="small"><?= esc((string) $t['title']) ?></td>
          <td class="small"><?= esc(hr_onboarding_category_label((string) $t['category'])) ?></td>
          <td class="small"><?= esc((string) ($t['due_date'] ?? '-')) ?></td>
          <td><?= hr_onboarding_task_status_badge((string) $t['status']) ?></td>
          <td class="text-end">
            <?php if ($mine && !$closed): ?>
              <form method="post" action="<?= esc(base_url('/hr/onboarding/me/task/' . (int) $t['id'] . '/toggle')) ?>" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="status" value="DONE">
                <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg me-1"></i>Mark done</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($tasks === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No items yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
    <?php
    layout_close();
}

/**
 * A new hire may close only the items that are theirs: assigned to their own
 * email or owned by the EMPLOYEE role. Anything else is refused with a 403 so a
 * crafted POST cannot close an HR or IT task.
 */
function hr_post_onboarding_me_task_toggle(int $id): void {
    $u = require_login();
    require_csrf();
    $email = strtolower((string) $u['email']);
    $t = hr_onboarding_plan_task($id);
    if ($t === null) { http_response_code(404); echo 'Task not found.'; exit; }
    $mine = strtolower((string) ($t['assignee_email'] ?? '')) === $email
        || strtoupper((string) $t['owner_role']) === 'EMPLOYEE';
    if (!$mine) { http_response_code(403); echo 'Not allowed.'; exit; }
    $status = strtoupper(trim((string) ($_POST['status'] ?? 'DONE')));
    if (!hr_onboarding_plan_task_toggle($id, $status, (string) $u['email'])) {
        flash('danger', 'Unknown task status.');
    } else {
        flash('success', 'Checklist updated.');
    }
    redirect(base_url('/hr/onboarding/me'));
}
