<?php
/**
 * WorkFolio PHP - HR module screens: Performance Management.
 *
 * Mounted through hr_performance_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/performance* path. HR/Admin manage cycles, reviews
 * and goals; any signed-in person can open /hr/performance/me to add goals,
 * save a self-rating and acknowledge a submitted review.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';       /* hr_tabs(), hr_is_manager() */
require_once __DIR__ . '/hr_performance.php';

/* ------------------------------------------------------------------- router ---- */

function hr_performance_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/performance':                hr_page_performance_overview(); return true;
            case $path === '/performance/cycles':         hr_page_performance_cycles(); return true;
            case $path === '/performance/reviews':        hr_page_performance_reviews(); return true;
            case $path === '/performance/me':             hr_page_performance_me(); return true;
            case preg_match('#^/performance/cycles/(\d+)$#', $path, $m) === 1:
                hr_page_performance_cycle((int) $m[1]); return true;
            case preg_match('#^/performance/reviews/(\d+)$#', $path, $m) === 1:
                hr_page_performance_review((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/performance/cycle/save':     hr_post_performance_cycle_save(); return true;
            case $path === '/performance/cycle/delete':   hr_post_performance_cycle_delete(); return true;
            case $path === '/performance/review/save':    hr_post_performance_review_save(); return true;
            case $path === '/performance/review/generate': hr_post_performance_generate(); return true;
            case $path === '/performance/review/submit':  hr_post_performance_review_submit(); return true;
            case $path === '/performance/review/delete':  hr_post_performance_review_delete(); return true;
            case $path === '/performance/goal/save':      hr_post_performance_goal_save(); return true;
            case $path === '/performance/goal/delete':    hr_post_performance_goal_delete(); return true;
            case preg_match('#^/performance/me/review/(\d+)/save$#', $path, $m) === 1:
                hr_post_performance_me_review_save((int) $m[1]); return true;
            case preg_match('#^/performance/me/review/(\d+)/ack$#', $path, $m) === 1:
                hr_post_performance_me_review_ack((int) $m[1]); return true;
        }
        return false;
    }
    return false;
}

/* ------------------------------------------------------------------ helpers ---- */

function hpf_employee_options(array $u, $selected = ''): void {
    echo '<select class="form-select form-select-sm" name="email"><option value="">&mdash; choose &mdash;</option>';
    foreach (hr_visible_employees($u) as $e) {
        $em = strtolower((string) $e['email']);
        $sel = $em === strtolower((string) $selected) ? ' selected' : '';
        echo '<option value="' . esc($em) . '"' . $sel . '>' . esc(employee_full_name($e) . ' <' . $em . '>') . '</option>';
    }
    echo '</select>';
}

function hpf_cycle_options($selected = 0, bool $openOnly = false): void {
    echo '<select class="form-select form-select-sm" name="cycle_id"><option value="">&mdash;</option>';
    foreach (hr_performance_cycles() as $c) {
        if ($openOnly && strtoupper((string) $c['status']) !== 'OPEN') continue;
        $sel = (int) $c['id'] === (int) $selected ? ' selected' : '';
        echo '<option value="' . (int) $c['id'] . '"' . $sel . '>' . esc((string) $c['name']) . '</option>';
    }
    echo '</select>';
}

function hpf_stars(int $n): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<i class="bi bi-star' . ($i <= $n ? '-fill text-warning' : '') . '"></i>';
    }
    return $out;
}

/** A compact self-service card appended to /hr/me. Safe when nothing exists. */
function hr_performance_me_card(string $email): void {
    $email = strtolower(trim($email));
    $reviews = hr_performance_reviews(['email' => $email, 'limit' => 3]);
    $goals = hr_performance_goals(['email' => $email, 'limit' => 5]);
    if ($reviews === [] && $goals === []) return;
    ?>
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0"><i class="bi bi-graph-up-arrow me-1 text-primary"></i>My Performance</div>
        <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/performance/me')) ?>">Open</a>
      </div>
      <?php foreach ($reviews as $r): ?>
        <div class="d-flex justify-content-between align-items-center small">
          <div><?= esc((string) ($r['cycle_name'] ?? 'Review')) ?> <span class="text-muted">&middot; <?= hpf_stars((int) $r['overall_rating']) ?></span></div>
          <?= hr_performance_review_status_badge((string) $r['status']) ?>
        </div>
      <?php endforeach; ?>
      <?php if ($goals !== []): ?>
        <div class="text-muted small mt-2 mb-1">Goals</div>
        <?php foreach ($goals as $g): ?>
          <div class="d-flex justify-content-between align-items-center">
            <div class="small"><?= esc((string) $g['title']) ?></div>
            <div style="min-width:110px"><div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= (int) $g['progress'] ?>%"></div></div></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
}

/* ----------------------------------------------------------------- overview ---- */

function hr_page_performance_overview(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $stats = hr_performance_stats();
    $cycles = hr_performance_cycles(['limit' => 8]);
    $reviews = hr_performance_reviews(['limit' => 10]);

    layout_open('Performance', $u, '/hr/performance');
    hr_tabs('/hr/performance');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Performance Management</h4>
    <div class="small text-muted">Appraisal cycles, reviews and goals for the roster.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/performance/cycles')) ?>"><i class="bi bi-plus-lg me-1"></i>Cycles</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/performance/reviews')) ?>">Reviews</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Open Cycles</div><div class="fs-4 fw-bold text-primary"><?= (int) $stats['open_cycles'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Pending Reviews</div><div class="fs-4 fw-bold"><?= (int) $stats['pending_reviews'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Acknowledged (Month)</div><div class="fs-4 fw-bold text-success"><?= (int) $stats['ack_month'] ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Active Goals</div><div class="fs-4 fw-bold"><?= (int) $stats['active_goals'] ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card p-3">
      <div class="sec-title mb-2">Cycles</div>
      <?php if ($cycles === []): ?><div class="small text-muted">No cycles yet.</div><?php else: ?>
        <table class="table table-sm align-middle mb-0">
          <tbody>
          <?php foreach ($cycles as $c): ?>
            <tr>
              <td class="small"><a href="<?= esc(base_url('/hr/performance/cycles/' . (int) $c['id'])) ?>"><?= esc((string) $c['name']) ?></a>
                <div class="text-muted" style="font-size:.72rem"><?= (int) $c['review_count'] ?> reviews</div></td>
              <td><?= hr_performance_cycle_status_badge((string) $c['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card p-3">
      <div class="sec-title mb-2">Recent Reviews</div>
      <?php if ($reviews === []): ?><div class="small text-muted">No reviews yet.</div><?php else: ?>
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>Employee</th><th>Cycle</th><th>Rating</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($reviews as $r): $nm = employee_full_name($r); ?>
            <tr>
              <td class="small"><a href="<?= esc(base_url('/hr/performance/reviews/' . (int) $r['id'])) ?>"><?= esc($nm !== '' ? $nm : (string) $r['email']) ?></a></td>
              <td class="small"><?= esc((string) ($r['cycle_name'] ?? '-')) ?></td>
              <td><?= hpf_stars((int) $r['overall_rating']) ?></td>
              <td><?= hr_performance_review_status_badge((string) $r['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------ cycles ---- */

function hr_page_performance_cycles(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $cycles = hr_performance_cycles();

    layout_open('Performance Cycles', $u, '/hr/performance');
    hr_tabs('/hr/performance');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-calendar3-range me-2 text-primary"></i>Appraisal Cycles</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/performance')) ?>">Back</a>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">New Cycle</div>
  <form method="post" action="<?= esc(base_url('/hr/performance/cycle/save')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" placeholder="e.g. 2026 H1 Review" required></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Period Start</label><input type="date" class="form-control form-control-sm" name="period_start"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Period End</label><input type="date" class="form-control form-control-sm" name="period_end"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
        <select class="form-select form-select-sm" name="status"><?php foreach (hr_performance_cycle_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Create</button></div>
      <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="2"></textarea></div>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Name</th><th>Period</th><th>Reviews</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cycles as $c): ?>
      <tr>
        <td class="small fw-semibold"><?= esc((string) $c['name']) ?></td>
        <td class="small"><?= esc((string) ($c['period_start'] ?: '-')) ?> &rarr; <?= esc((string) ($c['period_end'] ?: '-')) ?></td>
        <td class="small"><?= (int) $c['review_count'] ?></td>
        <td><?= hr_performance_cycle_status_badge((string) $c['status']) ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/performance/cycles/' . (int) $c['id'])) ?>">Open</a>
          <form method="post" action="<?= esc(base_url('/hr/performance/cycle/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this cycle? Reviews keep their data but lose the link.')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($cycles === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No cycles yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_performance_cycle(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $c = hr_performance_cycle($id);
    if ($c === null) { http_response_code(404); echo 'Cycle not found.'; exit; }
    $reviews = hr_performance_reviews(['cycle_id' => $id, 'limit' => 500]);

    layout_open('Cycle - ' . (string) $c['name'], $u, '/hr/performance');
    hr_tabs('/hr/performance');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $c['name']) ?></h4>
    <div class="small text-muted"><?= esc((string) ($c['period_start'] ?: '-')) ?> &rarr; <?= esc((string) ($c['period_end'] ?: '-')) ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_performance_cycle_status_badge((string) $c['status']) ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/performance/cycles')) ?>">Back</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Cycle Settings</div>
  <form method="post" action="<?= esc(base_url('/hr/performance/cycle/save')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" value="<?= esc((string) $c['name']) ?>" required></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Period Start</label><input type="date" class="form-control form-control-sm" name="period_start" value="<?= esc((string) ($c['period_start'] ?? '')) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Period End</label><input type="date" class="form-control form-control-sm" name="period_end" value="<?= esc((string) ($c['period_end'] ?? '')) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
        <select class="form-select form-select-sm" name="status"><?php foreach (hr_performance_cycle_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $c['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="2"><?= esc((string) ($c['description'] ?? '')) ?></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save settings</button></div>
    </div>
  </form>
</div>

<div class="card p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="sec-title mb-0">Reviews (<?= count($reviews) ?>)</div>
    <form method="post" action="<?= esc(base_url('/hr/performance/review/generate')) ?>">
      <?= csrf_field() ?><input type="hidden" name="cycle_id" value="<?= (int) $c['id'] ?>">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-magic me-1"></i>Start reviews for all employees</button>
    </form>
  </div>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>Employee</th><th>Rating</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reviews as $r): $nm = employee_full_name($r); ?>
      <tr>
        <td class="small"><a href="<?= esc(base_url('/hr/performance/reviews/' . (int) $r['id'])) ?>"><?= esc($nm !== '' ? $nm : (string) $r['email']) ?></a></td>
        <td><?= hpf_stars((int) $r['overall_rating']) ?></td>
        <td><?= hr_performance_review_status_badge((string) $r['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/performance/reviews/' . (int) $r['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($reviews === []): ?><tr><td colspan="4" class="text-center text-muted py-3">No reviews in this cycle yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= esc(base_url('/hr/performance/review/save')) ?>" class="row g-2 mt-1 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="cycle_id" value="<?= (int) $c['id'] ?>">
    <div class="col-12 col-md-5"><label class="form-label small mb-1">Add a review for</label><?php hpf_employee_options($u); ?></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Type</label>
      <select class="form-select form-select-sm" name="review_type"><?php foreach (hr_performance_review_types() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-4"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add review</button></div>
  </form>
</div>
    <?php
    layout_close();
}

/* ----------------------------------------------------------------- reviews ---- */

function hr_page_performance_reviews(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $fCycle = (int) ($_GET['cycle_id'] ?? 0);
    $filters = ['limit' => 500];
    if ($fCycle > 0) $filters['cycle_id'] = $fCycle;
    $reviews = hr_performance_reviews($filters);

    layout_open('Reviews', $u, '/hr/performance');
    hr_tabs('/hr/performance');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-clipboard-check me-2 text-primary"></i>Performance Reviews</h4>
  <div class="d-flex gap-2 align-items-center">
    <form method="get" class="d-flex gap-2 align-items-center">
      <select class="form-select form-select-sm" name="cycle_id" onchange="this.form.submit()">
        <option value="">All cycles</option>
        <?php foreach (hr_performance_cycles() as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $fCycle === (int) $c['id'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/performance')) ?>">Back</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Employee</th><th>Cycle</th><th>Type</th><th>Rating</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reviews as $r): $nm = employee_full_name($r); ?>
      <tr>
        <td class="small"><div class="fw-semibold"><?= esc($nm !== '' ? $nm : (string) $r['email']) ?></div><div class="text-muted"><?= esc((string) $r['email']) ?></div></td>
        <td class="small"><?= esc((string) ($r['cycle_name'] ?? '-')) ?></td>
        <td class="small"><?= esc(hr_performance_review_types()[strtoupper((string) $r['review_type'])] ?? (string) $r['review_type']) ?></td>
        <td><?= hpf_stars((int) $r['overall_rating']) ?></td>
        <td><?= hr_performance_review_status_badge((string) $r['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/performance/reviews/' . (int) $r['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($reviews === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No reviews match.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_performance_review(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $r = hr_performance_review($id);
    if ($r === null) { http_response_code(404); echo 'Review not found.'; exit; }
    $emp = employee_find_by_email((string) $r['email']);
    $nm = $emp !== null ? employee_full_name($emp) : (string) $r['email'];
    $goals = hr_performance_goals(['email' => (string) $r['email'], 'cycle_id' => (int) ($r['cycle_id'] ?? 0)]);

    layout_open('Review - ' . $nm, $u, '/hr/performance');
    hr_tabs('/hr/performance');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc($nm) ?></h4>
    <div class="small text-muted"><?= esc((string) $r['email']) ?>
      &middot; <?= esc(hr_performance_review_types()[strtoupper((string) $r['review_type'])] ?? '') ?>
      <?php if ((int) ($r['cycle_id'] ?? 0) > 0): $cy = hr_performance_cycle((int) $r['cycle_id']); ?>
        &middot; <?= esc((string) ($cy['name'] ?? '')) ?>
      <?php endif; ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_performance_review_status_badge((string) $r['status']) ?>
    <?php if (strtoupper((string) $r['status']) === 'DRAFT'): ?>
      <form method="post" action="<?= esc(base_url('/hr/performance/review/submit')) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Submit</button>
      </form>
    <?php endif; ?>
    <form method="post" action="<?= esc(base_url('/hr/performance/review/delete')) ?>" onsubmit="return confirm('Delete this review?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/performance/reviews')) ?>">Back</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Appraisal</div>
  <form method="post" action="<?= esc(base_url('/hr/performance/review/save')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Employee</label><?php hpf_employee_options($u, (string) $r['email']); ?></div>
      <div class="col-6 col-md-4"><label class="form-label small mb-1">Cycle</label><?php hpf_cycle_options((int) ($r['cycle_id'] ?? 0)); ?></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Type</label>
        <select class="form-select form-select-sm" name="review_type"><?php foreach (hr_performance_review_types() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $r['review_type']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-12 col-md-2"><label class="form-label small mb-1">Reviewer email</label><input class="form-control form-control-sm" name="reviewer_email" value="<?= esc((string) ($r['reviewer_email'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Overall rating (0-5)</label><input type="number" min="0" max="5" class="form-control form-control-sm" name="overall_rating" value="<?= (int) $r['overall_rating'] ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Self rating (0-5)</label><input type="number" min="0" max="5" class="form-control form-control-sm" name="self_rating" value="<?= (int) $r['self_rating'] ?>"></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Strengths</label><textarea class="form-control form-control-sm" name="strengths" rows="2"><?= esc((string) ($r['strengths'] ?? '')) ?></textarea></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Areas to improve</label><textarea class="form-control form-control-sm" name="improvements" rows="2"><?= esc((string) ($r['improvements'] ?? '')) ?></textarea></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Employee comments</label><textarea class="form-control form-control-sm" name="comments" rows="2"><?= esc((string) ($r['comments'] ?? '')) ?></textarea></div>
      <div class="col-12 col-md-6"><label class="form-label small mb-1">Manager comments</label><textarea class="form-control form-control-sm" name="manager_comments" rows="2"><?= esc((string) ($r['manager_comments'] ?? '')) ?></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save review</button></div>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="sec-title mb-2">Goals (<?= count($goals) ?>)</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>Goal</th><th>Weight</th><th>Progress</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($goals as $g): ?>
      <tr>
        <td class="small"><?= esc((string) $g['title']) ?><?php if (trim((string) $g['description']) !== ''): ?><div class="text-muted" style="font-size:.72rem"><?= esc((string) $g['description']) ?></div><?php endif; ?></td>
        <td class="small"><?= (int) $g['weight'] ?>%</td>
        <td style="min-width:120px"><div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= (int) $g['progress'] ?>%"></div></div><div class="text-muted" style="font-size:.72rem"><?= (int) $g['progress'] ?>%</div></td>
        <td><?= hr_performance_goal_status_badge((string) $g['status']) ?></td>
        <td class="text-end">
          <form method="post" action="<?= esc(base_url('/hr/performance/goal/delete')) ?>" class="d-inline" onsubmit="return confirm('Remove this goal?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($goals === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No goals yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= esc(base_url('/hr/performance/goal/save')) ?>" class="row g-2 mt-1 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="email" value="<?= esc((string) $r['email']) ?>">
    <input type="hidden" name="cycle_id" value="<?= (int) ($r['cycle_id'] ?? 0) ?>">
    <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
    <div class="col-12 col-md-4"><label class="form-label small mb-1">New goal</label><input class="form-control form-control-sm" name="title" required></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Weight %</label><input type="number" min="0" max="100" class="form-control form-control-sm" name="weight" value="0"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
      <select class="form-select form-select-sm" name="status"><?php foreach (hr_performance_goal_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Due</label><input type="date" class="form-control form-control-sm" name="due_date"></div>
    <div class="col-6 col-md-2"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Add goal</button></div>
  </form>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------ posts ---- */

function hr_post_performance_cycle_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $newId = hr_performance_cycle_save($id, $_POST, (string) $u['email']);
    if ($newId === 0) { flash('danger', 'A cycle needs a name.'); redirect(base_url('/hr/performance/cycles')); }
    activity_log($u, $id ? 'HR_PERFORMANCE_CYCLE_SAVED' : 'HR_PERFORMANCE_CYCLE_CREATED', (string) ($_POST['name'] ?? ''));
    flash('success', 'Cycle saved.');
    redirect(base_url('/hr/performance/cycles/' . $newId));
}

function hr_post_performance_cycle_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_performance_cycle_delete($id);
    activity_log($u, 'HR_PERFORMANCE_CYCLE_DELETED', 'Cycle #' . $id);
    flash('success', 'Cycle deleted.');
    redirect(base_url('/hr/performance/cycles'));
}

function hr_post_performance_review_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $newId = hr_performance_review_save($id, $_POST, (string) $u['email']);
    if ($newId === 0) { flash('danger', 'A review needs a valid employee email.'); redirect(base_url('/hr/performance/reviews')); }
    activity_log($u, 'HR_PERFORMANCE_REVIEW_SAVED', 'Review #' . $newId);
    flash('success', 'Review saved.');
    redirect(base_url('/hr/performance/reviews/' . $newId));
}

function hr_post_performance_generate(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $cycleId = (int) ($_POST['cycle_id'] ?? 0);
    if (hr_performance_cycle($cycleId) === null) { http_response_code(404); echo 'Cycle not found.'; exit; }
    $n = hr_performance_reviews_generate($cycleId, hr_visible_emails($u), (string) $u['email']);
    activity_log($u, 'HR_PERFORMANCE_GENERATED', 'Cycle #' . $cycleId . ' (' . $n . ')');
    flash('success', $n . ' review(s) started.');
    redirect(base_url('/hr/performance/cycles/' . $cycleId));
}

function hr_post_performance_review_submit(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!hr_performance_review_submit($id, (string) $u['email'])) { flash('danger', 'Review not found.'); }
    else { flash('success', 'Review submitted to the employee.'); }
    redirect(base_url('/hr/performance/reviews/' . $id));
}

function hr_post_performance_review_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_performance_review_delete($id);
    activity_log($u, 'HR_PERFORMANCE_REVIEW_DELETED', 'Review #' . $id);
    flash('success', 'Review deleted.');
    redirect(base_url('/hr/performance/reviews'));
}

function hr_post_performance_goal_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $newId = hr_performance_goal_save($id, $_POST, (string) $u['email']);
    if ($newId === 0) { flash('danger', 'A goal needs a title and a valid employee email.'); redirect(base_url('/hr/performance')); }
    flash('success', 'Goal saved.');
    if (isset($_POST['review_id']) && (int) $_POST['review_id'] > 0) {
        redirect(base_url('/hr/performance/reviews/' . (int) $_POST['review_id']));
    }
    redirect(base_url('/hr/performance'));
}

function hr_post_performance_goal_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $g = hr_performance_goal($id);
    hr_performance_goal_delete($id);
    flash('success', 'Goal removed.');
    if ($g !== null && (int) ($g['review_id'] ?? 0) > 0) redirect(base_url('/hr/performance/reviews/' . (int) $g['review_id']));
    redirect(base_url('/hr/performance'));
}

/* -------------------------------------------------------------- self-service -- */

function hr_page_performance_me(): void {
    $u = require_login();
    $email = strtolower((string) $u['email']);
    $reviews = hr_performance_reviews(['email' => $email, 'limit' => 50]);
    $goals = hr_performance_goals(['email' => $email, 'limit' => 100]);

    layout_open('My Performance', $u, '/hr/me');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>My Performance</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/me')) ?>">Back to My HR</a>
</div>

<?php foreach ($reviews as $r):
    $editable = strtoupper((string) $r['status']) !== 'ACKNOWLEDGED';
    $pending = strtoupper((string) $r['status']) === 'SUBMITTED'; ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="sec-title mb-0"><?= esc((string) ($r['cycle_name'] ?? 'Review')) ?></div>
      <?= hr_performance_review_status_badge((string) $r['status']) ?>
    </div>
    <div class="small mb-2">Manager rating: <?= hpf_stars((int) $r['overall_rating']) ?>
      &middot; Your rating: <?= hpf_stars((int) $r['self_rating']) ?></div>
    <?php if (trim((string) $r['strengths']) !== ''): ?><div class="small mb-1"><span class="text-muted">Strengths:</span> <?= esc((string) $r['strengths']) ?></div><?php endif; ?>
    <?php if (trim((string) $r['improvements']) !== ''): ?><div class="small mb-1"><span class="text-muted">Improve:</span> <?= esc((string) $r['improvements']) ?></div><?php endif; ?>
    <?php if (trim((string) $r['manager_comments']) !== ''): ?><div class="small mb-2"><span class="text-muted">Manager:</span> <?= esc((string) $r['manager_comments']) ?></div><?php endif; ?>

    <?php if ($editable): ?>
      <form method="post" action="<?= esc(base_url('/hr/performance/me/review/' . (int) $r['id'] . '/save')) ?>" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Your rating</label><input type="number" min="0" max="5" class="form-control form-control-sm" name="self_rating" value="<?= (int) $r['self_rating'] ?>"></div>
        <div class="col-12 col-md-7"><label class="form-label small mb-1">Your comments</label><input class="form-control form-control-sm" name="comments" value="<?= esc((string) ($r['comments'] ?? '')) ?>"></div>
        <div class="col-6 col-md-3"><button class="btn btn-outline-primary btn-sm w-100">Save my input</button></div>
      </form>
    <?php endif; ?>
    <?php if ($pending): ?>
      <form method="post" action="<?= esc(base_url('/hr/performance/me/review/' . (int) $r['id'] . '/ack')) ?>" class="mt-2">
        <?= csrf_field() ?>
        <button class="btn btn-success btn-sm"><i class="bi bi-check2-circle me-1"></i>Acknowledge</button>
      </form>
    <?php elseif (strtoupper((string) $r['status']) === 'ACKNOWLEDGED' && trim((string) $r['acknowledged_at']) !== ''): ?>
      <div class="small text-success mt-1">Acknowledged on <?= esc(dt_fmt((string) $r['acknowledged_at'])) ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($reviews === []): ?>
  <div class="card p-3 mb-3"><div class="small text-muted">No reviews assigned to you yet.</div></div>
<?php endif; ?>

<div class="card p-3">
  <div class="sec-title mb-2">My Goals (<?= count($goals) ?>)</div>
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Goal</th><th>Weight</th><th>Progress</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($goals as $g): ?>
      <tr>
        <td class="small"><?= esc((string) $g['title']) ?></td>
        <td class="small"><?= (int) $g['weight'] ?>%</td>
        <td style="min-width:120px"><div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= (int) $g['progress'] ?>%"></div></div><div class="text-muted" style="font-size:.72rem"><?= (int) $g['progress'] ?>%</div></td>
        <td><?= hr_performance_goal_status_badge((string) $g['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($goals === []): ?><tr><td colspan="4" class="text-center text-muted py-3">No goals yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
    <?php
    layout_close();
}

function hr_post_performance_me_review_save(int $id): void {
    $u = require_login();
    require_csrf();
    if (!hr_performance_review_save_self($id, (string) $u['email'], $_POST)) {
        http_response_code(403); echo 'Not allowed.'; exit;
    }
    flash('success', 'Your input was saved.');
    redirect(base_url('/hr/performance/me'));
}

function hr_post_performance_me_review_ack(int $id): void {
    $u = require_login();
    require_csrf();
    if (!hr_performance_review_acknowledge($id, (string) $u['email'])) {
        http_response_code(403); echo 'Not allowed.'; exit;
    }
    flash('success', 'Review acknowledged.');
    redirect(base_url('/hr/performance/me'));
}
