<?php
/**
 * WorkFolio PHP - HR module screens: Tasks & Work.
 *
 * Mounted through hr_tasks_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/tasks* path. HR/Admin can create and assign tasks to
 * anyone on the roster; an assignee can move their own task and comment. The
 * self-service view lives at /hr/tasks/me and a card is appended to /hr/me.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';       /* hr_tabs(), hr_is_manager() */
require_once __DIR__ . '/hr_tasks.php';

/* ------------------------------------------------------------------- router ---- */

function hr_tasks_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/tasks':        hr_page_tasks(); return true;
            case $path === '/tasks/new':    hr_page_task_new(); return true;
            case $path === '/tasks/me':     hr_page_tasks_me(); return true;
            case preg_match('#^/tasks/(\d+)$#', $path, $m) === 1:
                hr_page_task((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/tasks/save':          hr_post_task_save(); return true;
            case $path === '/tasks/delete':        hr_post_task_delete(); return true;
            case $path === '/tasks/comment/delete': hr_post_task_comment_delete(); return true;
            case preg_match('#^/tasks/(\d+)/status$#', $path, $m) === 1:
                hr_post_task_status((int) $m[1]); return true;
            case preg_match('#^/tasks/(\d+)/comment$#', $path, $m) === 1:
                hr_post_task_comment((int) $m[1]); return true;
            case preg_match('#^/tasks/(\d+)/delete$#', $path, $m) === 1:
                hr_post_task_delete_id((int) $m[1]); return true;
        }
        return false;
    }
    return false;
}

/* ------------------------------------------------------------------ helpers ---- */

function hts_employee_options(array $u, $selected = ''): void {
    echo '<select class="form-select form-select-sm" name="assignee_email"><option value="">&mdash; unassigned &mdash;</option>';
    foreach (hr_visible_employees($u) as $e) {
        $em = strtolower((string) $e['email']);
        $sel = $em === strtolower((string) $selected) ? ' selected' : '';
        echo '<option value="' . esc($em) . '"' . $sel . '>' . esc(employee_full_name($e) . ' <' . $em . '>') . '</option>';
    }
    echo '</select>';
}

function hts_task_name(array $t): string {
    $nm = employee_full_name($t);
    return $nm !== '' ? $nm : (string) ($t['assignee_email'] ?: 'Unassigned');
}

function hts_progress_bar(int $pct): string {
    $pct = max(0, min(100, $pct));
    $cls = $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-primary' : 'bg-warning');
    return '<div class="progress" style="height:6px"><div class="progress-bar ' . $cls . '" style="width:' . $pct . '%"></div></div>';
}

/** A compact self-service card appended to /hr/me. Safe when nothing is assigned. */
function hr_tasks_me_card(string $email): void {
    $email = strtolower(trim($email));
    $tasks = hr_tasks(['assignee' => $email, 'open_only' => true, 'limit' => 5]);
    if ($tasks === []) return;
    $stats = hr_tasks_stats($email);
    ?>
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0"><i class="bi bi-list-task me-1 text-primary"></i>My Tasks</div>
        <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/tasks/me')) ?>">Open</a>
      </div>
      <div class="small text-muted mb-2"><?= (int) $stats['open'] ?> open<?= $stats['overdue'] > 0 ? ' &middot; <span class="text-danger">' . (int) $stats['overdue'] . ' overdue</span>' : '' ?></div>
      <table class="table table-sm mb-0">
        <tbody>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td class="small"><?= esc((string) $t['title']) ?></td>
            <td><?= hr_task_priority_badge((string) $t['priority']) ?></td>
            <td class="text-end small <?= hr_task_is_overdue($t) ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= esc((string) ($t['due_date'] ?: '-')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/* ----------------------------------------------------------------- overview ---- */

function hr_page_tasks(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'MANAGER']);
    hr_seed();
    $view = (string) ($_GET['view'] ?? 'list');
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $fPriority = strtoupper(trim((string) ($_GET['priority'] ?? '')));
    $q = trim((string) ($_GET['q'] ?? ''));
    $filters = ['limit' => 500];
    if ($fStatus !== '') $filters['status'] = $fStatus;
    if ($fPriority !== '') $filters['priority'] = $fPriority;
    if ($q !== '') $filters['q'] = $q;
    $stats = hr_tasks_stats();
    $tasks = hr_tasks($filters);

    layout_open('Tasks & Work', $u, '/hr/tasks');
    hr_tabs('/hr/tasks');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-list-task me-2 text-primary"></i>Tasks &amp; Work</h4>
    <div class="small text-muted">Assign work across the roster and track it to done.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/tasks/new')) ?>"><i class="bi bi-plus-lg me-1"></i>New task</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/tasks/me')) ?>">My tasks</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Open</div><div class="fs-4 fw-bold text-primary"><?= (int) $stats['open'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Overdue</div><div class="fs-4 fw-bold text-danger"><?= (int) $stats['overdue'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Due in 7 days</div><div class="fs-4 fw-bold"><?= (int) $stats['due_week'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Done (Month)</div><div class="fs-4 fw-bold text-success"><?= (int) $stats['done_month'] ?></div></div></div>
</div>

<div class="card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= esc($q) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><option value="">All</option>
        <?php foreach (hr_task_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Priority</label>
      <select class="form-select form-select-sm" name="priority"><option value="">All</option>
        <?php foreach (hr_task_priorities() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= $fPriority === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">View</label>
      <select class="form-select form-select-sm" name="view"><option value="list" <?= $view === 'list' ? 'selected' : '' ?>>List</option><option value="board" <?= $view === 'board' ? 'selected' : '' ?>>Board</option></select></div>
    <div class="col-12 col-md-3"><button class="btn btn-outline-primary btn-sm">Apply</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('/hr/tasks')) ?>">Reset</a></div>
  </form>
</div>

<?php if ($view === 'board'):
    $board = hr_tasks_board($filters); ?>
  <div class="row g-3">
    <?php foreach (hr_task_open_statuses() as $s): ?>
      <div class="col-12 col-md-4">
        <div class="card p-2">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold small"><?= esc(hr_task_statuses()[$s]) ?></div>
            <span class="badge bg-secondary"><?= count($board[$s] ?? []) ?></span>
          </div>
          <?php foreach (($board[$s] ?? []) as $t): ?>
            <div class="border rounded p-2 mb-2">
              <a class="small fw-semibold text-decoration-none" href="<?= esc(base_url('/hr/tasks/' . (int) $t['id'])) ?>"><?= esc((string) $t['title']) ?></a>
              <div class="d-flex justify-content-between align-items-center mt-1">
                <?= hr_task_priority_badge((string) $t['priority']) ?>
                <span class="text-muted small <?= hr_task_is_overdue($t) ? 'text-danger' : '' ?>"><?= esc((string) ($t['due_date'] ?: '-')) ?></span>
              </div>
              <div class="text-muted" style="font-size:.72rem"><?= esc(hts_task_name($t)) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (($board[$s] ?? []) === []): ?><div class="text-muted small text-center py-2">Empty</div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card p-3">
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light"><tr><th>Task</th><th>Assignee</th><th>Priority</th><th>Progress</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): ?>
        <tr>
          <td class="small"><a class="text-decoration-none fw-semibold" href="<?= esc(base_url('/hr/tasks/' . (int) $t['id'])) ?>"><?= esc((string) $t['title']) ?></a></td>
          <td class="small"><?= esc(hts_task_name($t)) ?></td>
          <td><?= hr_task_priority_badge((string) $t['priority']) ?></td>
          <td style="min-width:110px"><?= hts_progress_bar((int) $t['progress']) ?><div class="text-muted" style="font-size:.72rem"><?= (int) $t['progress'] ?>%</div></td>
          <td class="small <?= hr_task_is_overdue($t) ? 'text-danger fw-semibold' : '' ?>"><?= esc((string) ($t['due_date'] ?: '-')) ?></td>
          <td><?= hr_task_status_badge((string) $t['status']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/tasks/' . (int) $t['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($tasks === []): ?><tr><td colspan="7" class="text-center text-muted py-3">No tasks match.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>
    <?php
    layout_close();
}

function hr_page_task_new(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'MANAGER']);
    $maps = hr_org_maps();

    layout_open('New Task', $u, '/hr/tasks');
    hr_tabs('/hr/tasks');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-plus-square me-2 text-primary"></i>New Task</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/tasks')) ?>">Back</a>
</div>
<div class="card p-3">
  <form method="post" action="<?= esc(base_url('/hr/tasks/save')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2">
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Title</label><input class="form-control form-control-sm" name="title" required></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Assignee</label><?php hts_employee_options($u); ?></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Priority</label>
        <select class="form-select form-select-sm" name="priority"><?php foreach (hr_task_priorities() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= $k === 'MEDIUM' ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
        <select class="form-select form-select-sm" name="status"><?php foreach (hr_task_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Department</label><?php hts_org_select($maps['departments'], 'department_id', 0); ?></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Branch</label><?php hts_org_select($maps['branches'], 'branch_id', 0); ?></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Start date</label><input type="date" class="form-control form-control-sm" name="start_date"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Due date</label><input type="date" class="form-control form-control-sm" name="due_date"></div>
      <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="3"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Create task</button></div>
    </div>
  </form>
</div>
    <?php
    layout_close();
}

function hts_org_select(array $map, string $name, $selected): void {
    echo '<select class="form-select form-select-sm" name="' . esc($name) . '"><option value="">&mdash;</option>';
    foreach ($map as $id => $label) {
        $sel = (int) $selected === (int) $id ? ' selected' : '';
        echo '<option value="' . (int) $id . '"' . $sel . '>' . esc((string) $label) . '</option>';
    }
    echo '</select>';
}

function hr_page_task(int $id): void {
    $u = require_login();
    $isManager = hr_is_manager($u);
    $t = hr_task($id);
    if ($t === null) { http_response_code(404); echo 'Task not found.'; exit; }
    $mine = strtolower((string) ($t['assignee_email'] ?? '')) === strtolower((string) $u['email']);
    if (!$isManager && !$mine) { http_response_code(403); echo 'Not allowed.'; exit; }
    $comments = hr_task_comments($id);
    $maps = hr_org_maps();

    layout_open('Task - ' . (string) $t['title'], $u, '/hr/tasks');
    hr_tabs('/hr/tasks');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $t['title']) ?></h4>
    <div class="small text-muted"><?= esc(hts_task_name($t)) ?>
      &middot; <?= esc((string) ($maps['departments'][(int) ($t['department_id'] ?? 0)] ?? '-')) ?>
      &middot; due <?= esc((string) ($t['due_date'] ?: '-')) ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_task_priority_badge((string) $t['priority']) ?>
    <?= hr_task_status_badge((string) $t['status']) ?>
    <?php if ($isManager): ?>
      <form method="post" action="<?= esc(base_url('/hr/tasks/delete')) ?>" onsubmit="return confirm('Delete this task?')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
      </form>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/tasks')) ?>">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">Details</div>
      <div class="small mb-2" style="white-space:pre-wrap"><?= esc((string) ($t['description'] !== '' ? $t['description'] : 'No description.')) ?></div>
      <div class="small text-muted mb-1">Progress: <?= (int) $t['progress'] ?>%</div>
      <?= hts_progress_bar((int) $t['progress']) ?>
    </div>

    <div class="card p-3">
      <div class="sec-title mb-2">Comments (<?= count($comments) ?>)</div>
      <?php foreach ($comments as $c): ?>
        <div class="border-bottom py-2">
          <div class="d-flex justify-content-between">
            <div class="small fw-semibold"><?= esc((string) $c['author_email']) ?></div>
            <div class="d-flex gap-2 align-items-center">
              <span class="text-muted" style="font-size:.72rem"><?= esc(dt_fmt((string) $c['created_at'])) ?></span>
              <?php if ($isManager || strtolower((string) $c['author_email']) === strtolower((string) $u['email'])): ?>
                <form method="post" action="<?= esc(base_url('/hr/tasks/comment/delete')) ?>" onsubmit="return confirm('Remove this comment?')">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                  <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <div class="small" style="white-space:pre-wrap"><?= esc((string) $c['body']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if ($comments === []): ?><div class="small text-muted">No comments yet.</div><?php endif; ?>
      <form method="post" action="<?= esc(base_url('/hr/tasks/' . (int) $t['id'] . '/comment')) ?>" class="mt-2">
        <?= csrf_field() ?>
        <textarea class="form-control form-control-sm mb-2" name="body" rows="2" placeholder="Add a comment" required></textarea>
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-chat-dots me-1"></i>Comment</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <?php if ($isManager || $mine): ?>
      <div class="card p-3 mb-3">
        <div class="sec-title mb-2">Update</div>
        <form method="post" action="<?= esc(base_url('/hr/tasks/' . (int) $t['id'] . '/status')) ?>" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <div class="col-6"><label class="form-label small mb-1">Status</label>
            <select class="form-select form-select-sm" name="status"><?php foreach (hr_task_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $t['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label small mb-1">Progress %</label><input type="number" min="0" max="100" class="form-control form-control-sm" name="progress" value="<?= (int) $t['progress'] ?>"></div>
          <div class="col-12"><button class="btn btn-primary btn-sm">Update task</button></div>
        </form>
      </div>
    <?php endif; ?>
    <?php if ($isManager): ?>
      <div class="card p-3">
        <div class="sec-title mb-2">Edit Task</div>
        <form method="post" action="<?= esc(base_url('/hr/tasks/save')) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <div class="mb-2"><label class="form-label small mb-1">Title</label><input class="form-control form-control-sm" name="title" value="<?= esc((string) $t['title']) ?>" required></div>
          <div class="mb-2"><label class="form-label small mb-1">Assignee</label><?php hts_employee_options($u, (string) $t['assignee_email']); ?></div>
          <div class="row g-2">
            <div class="col-6"><label class="form-label small mb-1">Priority</label>
              <select class="form-select form-select-sm" name="priority"><?php foreach (hr_task_priorities() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $t['priority']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small mb-1">Due date</label><input type="date" class="form-control form-control-sm" name="due_date" value="<?= esc((string) ($t['due_date'] ?? '')) ?>"></div>
            <div class="col-6"><label class="form-label small mb-1">Department</label><?php hts_org_select($maps['departments'], 'department_id', $t['department_id'] ?? 0); ?></div>
            <div class="col-6"><label class="form-label small mb-1">Branch</label><?php hts_org_select($maps['branches'], 'branch_id', $t['branch_id'] ?? 0); ?></div>
            <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="3"><?= esc((string) ($t['description'] ?? '')) ?></textarea></div>
          </div>
          <input type="hidden" name="status" value="<?= esc((string) $t['status']) ?>">
          <input type="hidden" name="progress" value="<?= (int) $t['progress'] ?>">
          <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-save me-1"></i>Save changes</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_tasks_me(): void {
    $u = require_login();
    $email = strtolower((string) $u['email']);
    $stats = hr_tasks_stats($email);
    $tasks = hr_tasks(['assignee' => $email, 'limit' => 500]);

    layout_open('My Tasks', $u, '/hr/me');
    $isManager = hr_is_manager($u);
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-list-task me-2 text-primary"></i>My Tasks</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/me')) ?>">Back to My HR</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Open</div><div class="fs-4 fw-bold text-primary"><?= (int) $stats['open'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Overdue</div><div class="fs-4 fw-bold text-danger"><?= (int) $stats['overdue'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Due in 7 days</div><div class="fs-4 fw-bold"><?= (int) $stats['due_week'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Done (Month)</div><div class="fs-4 fw-bold text-success"><?= (int) $stats['done_month'] ?></div></div></div>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>Task</th><th>Priority</th><th>Due</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t): $terminal = in_array(strtoupper((string) $t['status']), ['DONE', 'CANCELLED'], true); ?>
      <tr>
        <td class="small"><a class="text-decoration-none" href="<?= esc(base_url('/hr/tasks/' . (int) $t['id'])) ?>"><?= esc((string) $t['title']) ?></a></td>
        <td><?= hr_task_priority_badge((string) $t['priority']) ?></td>
        <td class="small <?= hr_task_is_overdue($t) ? 'text-danger fw-semibold' : '' ?>"><?= esc((string) ($t['due_date'] ?: '-')) ?></td>
        <td><?= hr_task_status_badge((string) $t['status']) ?></td>
        <td class="text-end">
          <?php if (!$terminal): ?>
            <form method="post" action="<?= esc(base_url('/hr/tasks/' . (int) $t['id'] . '/status')) ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="status" value="DONE">
              <button class="btn btn-sm btn-outline-success" title="Mark done"><i class="bi bi-check-lg"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($tasks === []): ?><tr><td colspan="5" class="text-center text-muted py-3">Nothing assigned to you.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------ posts ---- */

function hr_post_task_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'MANAGER']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $newId = hr_task_save($id, $_POST, (string) $u['email']);
    if ($newId === 0) { flash('danger', 'A task needs a title.'); redirect(base_url('/hr/tasks/new')); }
    activity_log($u, $id ? 'HR_TASK_SAVED' : 'HR_TASK_CREATED', 'Task #' . $newId);
    flash('success', 'Task saved.');
    redirect(base_url('/hr/tasks/' . $newId));
}

function hr_post_task_status(int $id): void {
    $u = require_login();
    require_csrf();
    $t = hr_task($id);
    if ($t === null) { http_response_code(404); echo 'Task not found.'; exit; }
    $mine = strtolower((string) ($t['assignee_email'] ?? '')) === strtolower((string) $u['email']);
    if (!hr_is_manager($u) && !$mine) { http_response_code(403); echo 'Not allowed.'; exit; }
    $progress = isset($_POST['progress']) ? (int) $_POST['progress'] : -1;
    if (!hr_task_set_status($id, (string) ($_POST['status'] ?? ''), (string) $u['email'], $progress)) {
        flash('danger', 'Unknown status.');
    } else {
        flash('success', 'Task updated.');
    }
    redirect(strpos((string) ($_SERVER['HTTP_REFERER'] ?? ''), '/hr/tasks/me') !== false
        ? base_url('/hr/tasks/me') : base_url('/hr/tasks/' . $id));
}

function hr_post_task_comment(int $id): void {
    $u = require_login();
    require_csrf();
    $t = hr_task($id);
    if ($t === null) { http_response_code(404); echo 'Task not found.'; exit; }
    $mine = strtolower((string) ($t['assignee_email'] ?? '')) === strtolower((string) $u['email']);
    if (!hr_is_manager($u) && !$mine) { http_response_code(403); echo 'Not allowed.'; exit; }
    if (hr_task_comment_add($id, (string) ($_POST['body'] ?? ''), (string) $u['email']) === 0) {
        flash('danger', 'Comment cannot be empty.');
    } else {
        flash('success', 'Comment added.');
    }
    redirect(base_url('/hr/tasks/' . $id));
}

function hr_post_task_comment_delete(): void {
    $u = require_login();
    require_csrf();
    $c = hr_task_comment((int) ($_POST['id'] ?? 0));
    if ($c === null) { http_response_code(404); echo 'Comment not found.'; exit; }
    if (!hr_is_manager($u) && strtolower((string) $c['author_email']) !== strtolower((string) $u['email'])) {
        http_response_code(403); echo 'Not allowed.'; exit;
    }
    hr_task_comment_delete((int) $c['id']);
    flash('success', 'Comment removed.');
    redirect(base_url('/hr/tasks/' . (int) ($_POST['task_id'] ?? $c['task_id'])));
}

function hr_post_task_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'MANAGER']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_task_delete($id);
    activity_log($u, 'HR_TASK_DELETED', 'Task #' . $id);
    flash('success', 'Task deleted.');
    redirect(base_url('/hr/tasks'));
}

function hr_post_task_delete_id(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR', 'MANAGER']);
    require_csrf();
    hr_task_delete($id);
    activity_log($u, 'HR_TASK_DELETED', 'Task #' . $id);
    flash('success', 'Task deleted.');
    redirect(base_url('/hr/tasks'));
}
