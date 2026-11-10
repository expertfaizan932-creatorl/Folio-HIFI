<?php
/**
 * WorkFolio PHP - HR module screens, Phase 2: Recruitment / ATS.
 *
 * Mounted through hr_recruitment_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/recruitment* path. The candidate pool is the existing
 * Employee Form intake; this layer adds vacancies, a pipeline board, interviews
 * and offers on top, without changing the intake screens themselves.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';       /* hr_tabs(), hr_is_manager() */
require_once __DIR__ . '/employee_form.php';
require_once __DIR__ . '/hr_recruitment.php';

/* ------------------------------------------------------------------- router ---- */

function hr_recruitment_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/recruitment':                 hr_page_recruitment_overview(); return true;
            case $path === '/recruitment/openings':        hr_page_openings(); return true;
            case $path === '/recruitment/candidates':      hr_page_candidates(); return true;
            case $path === '/recruitment/interviews':      hr_page_interviews(); return true;
            case preg_match('#^/recruitment/openings/(\d+)$#', $path, $m) === 1:
                hr_page_opening((int) $m[1]); return true;
            case preg_match('#^/recruitment/candidates/(\d+)$#', $path, $m) === 1:
                hr_page_candidate((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/recruitment/opening/save':    hr_post_opening_save(); return true;
            case $path === '/recruitment/opening/delete':  hr_post_opening_delete(); return true;
            case $path === '/recruitment/opening/status':  hr_post_opening_status(); return true;
            case preg_match('#^/recruitment/candidate/(\d+)/link$#', $path, $m) === 1:
                hr_post_candidate_link((int) $m[1]); return true;
            case preg_match('#^/recruitment/candidate/(\d+)/stage$#', $path, $m) === 1:
                hr_post_candidate_stage((int) $m[1]); return true;
            case preg_match('#^/recruitment/candidate/(\d+)/meta$#', $path, $m) === 1:
                hr_post_candidate_meta((int) $m[1]); return true;
            case preg_match('#^/recruitment/candidate/(\d+)/comm$#', $path, $m) === 1:
                hr_post_candidate_comm((int) $m[1]); return true;
            case preg_match('#^/recruitment/comm/(\d+)/delete$#', $path, $m) === 1:
                hr_post_comm_delete((int) $m[1]); return true;
            case preg_match('#^/recruitment/candidate/(\d+)/interview$#', $path, $m) === 1:
                hr_post_candidate_interview((int) $m[1]); return true;
            case preg_match('#^/recruitment/candidate/(\d+)/offer$#', $path, $m) === 1:
                hr_post_candidate_offer((int) $m[1]); return true;
            case preg_match('#^/recruitment/interview/(\d+)/update$#', $path, $m) === 1:
                hr_post_interview_update((int) $m[1]); return true;
            case preg_match('#^/recruitment/interview/(\d+)/delete$#', $path, $m) === 1:
                hr_post_interview_delete((int) $m[1]); return true;
        }
        return false;
    }
    return false;
}

/* ------------------------------------------------------------------ overview ---- */

function hr_page_recruitment_overview(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $open = hr_openings_count_open();
    $candidates = hr_candidates(['limit' => 500]);
    $stages = ef_statuses();
    $counts = hr_candidate_stage_counts();
    $inInterview = (int) ($counts['INTERVIEW'] ?? 0);
    $hired = (int) ($counts['HIRED'] ?? 0);
    $upcoming = hr_interviews_all(['upcoming' => true, 'stage' => 'SCHEDULED', 'limit' => 8]);

    layout_open('Recruitment', $u, '/hr/recruitment');
    hr_tabs('/hr/recruitment');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-briefcase me-2 text-primary"></i>Recruitment</h4>
    <div class="small text-muted">Applicant tracking over the Employee Form intake.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/recruitment/openings')) ?>"><i class="bi bi-plus-lg me-1"></i>Vacancies</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/recruitment/candidates')) ?>">All candidates</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/employee-form')) ?>">Intake form</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Open Vacancies</div><div class="fs-4 fw-bold text-success"><?= $open ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Candidates</div><div class="fs-4 fw-bold"><?= count($candidates) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">In Interview</div><div class="fs-4 fw-bold text-primary"><?= $inInterview ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Hired</div><div class="fs-4 fw-bold text-success"><?= $hired ?></div></div></div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Pipeline</div>
  <div class="row g-2">
    <?php foreach ($stages as $key => $label): ?>
      <div class="col-6 col-md-4 col-xl-2">
        <a class="text-decoration-none" href="<?= esc(base_url('/hr/recruitment/candidates?status=' . urlencode($key))) ?>">
          <div class="border rounded p-2 h-100">
            <div class="small text-muted"><?= esc($label) ?></div>
            <div class="fs-5 fw-bold"><?= (int) ($counts[$key] ?? 0) ?></div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card p-3">
  <div class="sec-title mb-2"><i class="bi bi-calendar-event me-1 text-primary"></i>Upcoming Interviews</div>
  <?php if ($upcoming === []): ?>
    <div class="small text-muted">No interviews scheduled.</div>
  <?php else: ?>
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Candidate</th><th>Position</th><th>When</th><th>Mode</th><th>Interviewer</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($upcoming as $iv): ?>
        <tr>
          <td class="small fw-semibold"><?= esc((string) $iv['full_name']) ?></td>
          <td class="small"><?= esc((string) $iv['position_title']) ?></td>
          <td class="small"><?= esc(dt_fmt((string) $iv['scheduled_at'])) ?></td>
          <td class="small"><?= esc((string) $iv['mode']) ?></td>
          <td class="small"><?= esc((string) $iv['interviewer']) ?></td>
          <td class="text-end"><a class="small" href="<?= esc(base_url('/hr/recruitment/candidates/' . (int) $iv['submission_id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------ openings ---- */

function hr_page_openings(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $maps = hr_org_maps();
    $openings = hr_openings_all();
    $counts = hr_opening_application_counts();

    layout_open('Vacancies', $u, '/hr/recruitment');
    hr_tabs('/hr/recruitment');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-briefcase me-2 text-primary"></i>Job Vacancies</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/recruitment')) ?>">Back to Recruitment</a>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Post a Vacancy</div>
  <form method="post" action="<?= esc(base_url('/hr/recruitment/opening/save')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Title</label><input class="form-control form-control-sm" name="title" required></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Department</label>
        <select class="form-select form-select-sm" name="department_id"><option value="">&mdash;</option>
          <?php foreach ($maps['departments'] as $id => $n): ?><option value="<?= (int) $id ?>"><?= esc($n) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Branch</label>
        <select class="form-select form-select-sm" name="branch_id"><option value="">&mdash;</option>
          <?php foreach ($maps['branches'] as $id => $n): ?><option value="<?= (int) $id ?>"><?= esc($n) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Employment Type</label>
        <select class="form-select form-select-sm" name="employment_type"><option value="">&mdash;</option>
          <?php foreach (hr_employment_types() as $t): ?><option><?= esc($t) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Vacancies</label><input type="number" min="1" class="form-control form-control-sm" name="vacancies" value="1"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Work Location</label><input class="form-control form-control-sm" name="work_location"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Salary From</label><input class="form-control form-control-sm" name="salary_min"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Salary To</label><input class="form-control form-control-sm" name="salary_max"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Hiring Manager (email)</label><input class="form-control form-control-sm" name="hiring_manager"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Posted</label><input type="date" class="form-control form-control-sm" name="posted_date" value="<?= esc(today()) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Closing</label><input type="date" class="form-control form-control-sm" name="closing_date"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
        <select class="form-select form-select-sm" name="status">
          <?php foreach (hr_opening_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= $k === 'OPEN' ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-12"><label class="form-label small mb-1">Description</label><textarea class="form-control form-control-sm" name="description" rows="2"></textarea></div>
      <div class="col-12"><label class="form-label small mb-1">Requirements</label><textarea class="form-control form-control-sm" name="requirements" rows="2"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Create vacancy</button></div>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Title</th><th>Department</th><th>Branch</th><th>Type</th><th>Openings</th><th>Applicants</th><th>Closing</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($openings as $o): ?>
      <tr>
        <td class="fw-semibold"><?= esc((string) $o['title']) ?></td>
        <td class="small"><?= esc((string) ($o['department_name'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($o['branch_name'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($o['employment_type'] ?? '-')) ?></td>
        <td class="small"><?= (int) $o['vacancies'] ?></td>
        <td class="small"><?= (int) ($counts[(int) $o['id']] ?? 0) ?></td>
        <td class="small"><?= esc((string) ($o['closing_date'] ?? '-')) ?></td>
        <td><?= hr_opening_status_badge((string) $o['status']) ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/recruitment/openings/' . (int) $o['id'])) ?>">Open</a>
          <form method="post" action="<?= esc(base_url('/hr/recruitment/opening/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this vacancy? Candidates stay but lose the link.')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($openings === []): ?><tr><td colspan="9" class="text-center text-muted py-3">No vacancies yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_opening(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $o = hr_opening($id);
    if ($o === null) { http_response_code(404); echo 'Vacancy not found.'; exit; }
    $maps = hr_org_maps();
    $applicants = hr_candidates(['opening_id' => $id, 'limit' => 300]);

    layout_open('Vacancy - ' . (string) $o['title'], $u, '/hr/recruitment');
    hr_tabs('/hr/recruitment');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $o['title']) ?></h4>
    <div class="small text-muted">
      <?= esc((string) ($maps['departments'][(int) ($o['department_id'] ?? 0)] ?? '-')) ?>
      &middot; <?= esc((string) ($maps['branches'][(int) ($o['branch_id'] ?? 0)] ?? '-')) ?>
      &middot; <?= (int) $o['vacancies'] ?> opening(s)
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_opening_status_badge((string) $o['status']) ?>
    <form method="post" action="<?= esc(base_url('/hr/recruitment/opening/status')) ?>" class="d-flex gap-1">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <?php foreach (hr_opening_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $o['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
      </select>
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/recruitment/openings')) ?>">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Details</div>
      <div class="small"><span class="text-muted">Type:</span> <?= esc((string) ($o['employment_type'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Location:</span> <?= esc((string) ($o['work_location'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Salary:</span> <?= esc((string) ($o['salary_min'] ?? '-')) ?> - <?= esc((string) ($o['salary_max'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Hiring Manager:</span> <?= esc((string) ($o['hiring_manager'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Posted:</span> <?= esc((string) ($o['posted_date'] ?? '-')) ?> &middot; <span class="text-muted">Closing:</span> <?= esc((string) ($o['closing_date'] ?? '-')) ?></div>
      <?php if (trim((string) $o['description']) !== ''): ?><hr class="my-2"><div class="small"><?= nl2br(esc((string) $o['description'])) ?></div><?php endif; ?>
      <?php if (trim((string) $o['requirements']) !== ''): ?><hr class="my-2"><div class="fw-semibold small">Requirements</div><div class="small"><?= nl2br(esc((string) $o['requirements'])) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Applicants (<?= count($applicants) ?>)</div>
      <?php if ($applicants === []): ?>
        <div class="small text-muted">No candidates attached to this vacancy yet. Attach one from a candidate page.</div>
      <?php else: ?>
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Candidate</th><th>Stage</th><th>Applied</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($applicants as $c): ?>
            <tr>
              <td class="small"><div class="fw-semibold"><?= esc((string) $c['full_name']) ?></div><div class="text-muted"><?= esc((string) $c['email']) ?></div></td>
              <td><span class="badge bg-secondary"><?= esc(ef_status_label((string) $c['status'])) ?></span></td>
              <td class="small text-muted"><?= esc(dt_fmt((string) $c['created_at'], 'd M')) ?></td>
              <td class="text-end"><a class="small" href="<?= esc(base_url('/hr/recruitment/candidates/' . (int) $c['id'])) ?>">Open</a></td>
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

function hr_post_opening_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $newId = hr_opening_save($id, $_POST, (string) $u['email']);
    activity_log($u, $id ? 'HR_OPENING_SAVED' : 'HR_OPENING_CREATED', (string) ($_POST['title'] ?? ''));
    flash('success', 'Vacancy saved.');
    redirect(base_url($newId ? '/hr/recruitment/openings/' . $newId : '/hr/recruitment/openings'));
}

function hr_post_opening_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_opening_delete($id);
    activity_log($u, 'HR_OPENING_DELETED', 'Vacancy #' . $id);
    flash('success', 'Vacancy deleted.');
    redirect(base_url('/hr/recruitment/openings'));
}

function hr_post_opening_status(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!hr_opening_set_status($id, (string) ($_POST['status'] ?? ''), (string) $u['email'])) {
        flash('danger', 'Unknown vacancy status.');
    } else {
        flash('success', 'Vacancy status updated.');
    }
    redirect(base_url('/hr/recruitment/openings/' . $id));
}

/* ---------------------------------------------------------------- candidates ---- */

function hr_page_candidates(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
    $fOpening = (int) ($_GET['opening_id'] ?? 0);
    $fSource = strtoupper(trim((string) ($_GET['source'] ?? '')));
    $fRecruiter = strtolower(trim((string) ($_GET['recruiter'] ?? '')));
    $filters = ['limit' => 300];
    if ($fStatus !== '') $filters['status'] = $fStatus;
    if ($fOpening > 0) $filters['opening_id'] = $fOpening;
    if ($fSource !== '') $filters['source'] = $fSource;
    if ($fRecruiter !== '') $filters['recruiter'] = $fRecruiter;
    $list = hr_candidates($filters);
    $openings = hr_openings_all();
    $recruiters = hr_recruiters_in_use();

    layout_open('Candidates', $u, '/hr/recruitment');
    hr_tabs('/hr/recruitment');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Candidates</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/recruitment')) ?>">Back to Recruitment</a>
</div>

<form class="card p-3 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Vacancy</label>
      <select class="form-select form-select-sm" name="opening_id" onchange="this.form.submit()"><option value="">All</option>
        <?php foreach ($openings as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $fOpening === (int) $o['id'] ? 'selected' : '' ?>><?= esc((string) $o['title']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Stage</label>
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()"><option value="">All</option>
        <?php foreach (ef_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Source</label>
      <select class="form-select form-select-sm" name="source" onchange="this.form.submit()"><option value="">All</option>
        <?php foreach (hr_candidate_sources() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= $fSource === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Recruiter</label>
      <select class="form-select form-select-sm" name="recruiter" onchange="this.form.submit()"><option value="">All</option>
        <?php foreach ($recruiters as $em => $n): ?><option value="<?= esc($em) ?>" <?= $fRecruiter === $em ? 'selected' : '' ?>><?= esc($em) ?> (<?= (int) $n ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-12 col-md-2"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('/hr/recruitment/candidates')) ?>">Reset</a></div>
  </div>
</form>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Candidate</th><th>Position</th><th>Vacancy</th><th>Source</th><th>Recruiter</th><th>Stage</th><th>Applied</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr>
        <td class="small"><div class="fw-semibold"><?= esc((string) $c['full_name']) ?></div><div class="text-muted"><?= esc((string) $c['email']) ?></div></td>
        <td class="small"><?= esc((string) $c['position_title']) ?></td>
        <td class="small"><?= esc((string) ($c['opening_title'] ?? '-')) ?></td>
        <td class="small"><?= esc(hr_candidate_source_label((string) ($c['source'] ?? ''))) ?></td>
        <td class="small"><?= esc((string) ($c['recruiter_email'] ?: '-')) ?></td>
        <td><span class="badge bg-secondary"><?= esc(ef_status_label((string) $c['status'])) ?></span></td>
        <td class="small text-muted"><?= esc(dt_fmt((string) $c['created_at'], 'd M')) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/recruitment/candidates/' . (int) $c['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list === []): ?><tr><td colspan="8" class="text-center text-muted py-3">No candidates match.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_candidate(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $s = ef_find($id);
    if ($s === null) { http_response_code(404); echo 'Candidate not found.'; exit; }
    $link = hr_application_link($id);
    $openingId = $link ? hr_int_or_null($link['opening_id']) : null;
    $openings = hr_openings_all();
    $interviews = hr_interviews_for($id);
    $offer = hr_offer($id);
    $source = strtoupper((string) ($link['source'] ?? ''));
    $recruiter = (string) ($link['recruiter_email'] ?? '');
    $comms = hr_candidate_comms($id);

    layout_open('Candidate - ' . (string) $s['full_name'], $u, '/hr/recruitment');
    hr_tabs('/hr/recruitment');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $s['full_name']) ?></h4>
    <div class="small text-muted"><?= esc((string) $s['email']) ?> &middot; applied for <?= esc((string) $s['position_title']) ?>
      <?php if ($source !== ''): ?> &middot; <span class="badge bg-light text-dark border"><?= esc(hr_candidate_source_label($source)) ?></span><?php endif; ?>
      <?php if ($recruiter !== ''): ?> &middot; <i class="bi bi-person-badge"></i> <?= esc($recruiter) ?><?php endif; ?></div>
  </div>
  <div class="d-flex gap-2">
    <span class="badge bg-secondary align-self-center"><?= esc(ef_status_label((string) $s['status'])) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/employee-form/' . $id)) ?>">Full application</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/recruitment/candidates')) ?>">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">Pipeline &amp; Vacancy</div>
      <label class="form-label small mb-1">Move stage</label>
      <form method="post" action="<?= esc(base_url('/hr/recruitment/candidate/' . $id . '/stage')) ?>" class="d-flex gap-2 mb-3">
        <?= csrf_field() ?>
        <select class="form-select form-select-sm" name="status">
          <?php foreach (ef_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $s['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
        </select>
        <input class="form-control form-control-sm" name="remarks" placeholder="Note (optional)">
        <button class="btn btn-primary btn-sm text-nowrap">Update</button>
      </form>
      <label class="form-label small mb-1">Attach to vacancy</label>
      <form method="post" action="<?= esc(base_url('/hr/recruitment/candidate/' . $id . '/link')) ?>" class="d-flex gap-2">
        <?= csrf_field() ?>
        <select class="form-select form-select-sm" name="opening_id">
          <option value="">Not attached</option>
          <?php foreach ($openings as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $openingId === (int) $o['id'] ? 'selected' : '' ?>><?= esc((string) $o['title']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary btn-sm text-nowrap">Link</button>
      </form>
    </div>

    <div class="card p-3">
      <div class="sec-title mb-2">Contact</div>
      <div class="small"><span class="text-muted">Phone:</span> <?= esc((string) ($s['phone'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">CNIC:</span> <?= esc((string) ($s['cnic'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Location:</span> <?= esc((string) ($s['work_location'] ?? '-')) ?></div>
      <div class="small"><span class="text-muted">Experience:</span> <?= esc((string) ($s['total_experience'] ?? '-')) ?></div>
      <?php if (trim((string) ($s['cv_file'] ?? '')) !== ''): ?>
        <a class="btn btn-sm btn-outline-secondary mt-2" href="<?= esc(base_url('/employee-form/cv/' . $id)) ?>"><i class="bi bi-file-earmark-text me-1"></i>View CV</a>
      <?php endif; ?>
    </div>

    <div class="card p-3 mt-3">
      <div class="sec-title mb-2"><i class="bi bi-diagram-3 me-1 text-primary"></i>Sourcing &amp; Recruiter</div>
      <form method="post" action="<?= esc(base_url('/hr/recruitment/candidate/' . $id . '/meta')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-6"><label class="form-label small mb-1">Source</label>
          <select class="form-select form-select-sm" name="source">
            <option value="">- unset -</option>
            <?php foreach (hr_candidate_sources() as $k => $lbl): ?>
              <option value="<?= esc($k) ?>" <?= $source === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
            <?php endforeach; ?>
            <?php if ($source !== '' && !isset(hr_candidate_sources()[$source])): ?>
              <option value="<?= esc($source) ?>" selected><?= esc($source) ?></option>
            <?php endif; ?>
          </select></div>
        <div class="col-6"><label class="form-label small mb-1">Recruiter (email)</label>
          <input type="email" class="form-control form-control-sm" name="recruiter_email" value="<?= esc($recruiter) ?>" placeholder="hr@company.com"></div>
        <div class="col-12"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-save me-1"></i>Save sourcing</button></div>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2"><i class="bi bi-calendar-event me-1 text-primary"></i>Interviews</div>
      <?php if ($interviews === []): ?><div class="small text-muted mb-2">No interviews yet.</div><?php else: ?>
        <table class="table table-sm align-middle">
          <thead class="table-light"><tr><th>Rnd</th><th>When</th><th>Interviewer</th><th>Stage</th><th>Feedback</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($interviews as $iv): $hasFb = trim((string) $iv['feedback']) !== '' || (int) $iv['rating'] > 0; ?>
            <tr>
              <td class="small"><?= (int) $iv['round_no'] ?></td>
              <td class="small"><?= esc((string) ($iv['scheduled_at'] ?: '-')) ?><div class="text-muted"><?= esc((string) $iv['mode']) ?></div></td>
              <td class="small"><?= esc((string) $iv['interviewer']) ?></td>
              <td><?= hr_interview_stage_badge((string) $iv['stage']) ?></td>
              <td class="small" style="max-width:220px">
                <?php if ($hasFb): ?>
                  <div><?= (int) $iv['rating'] ? str_repeat('★', (int) $iv['rating']) . str_repeat('☆', 5 - (int) $iv['rating']) : '' ?></div>
                  <div class="text-muted"><?= esc(substr((string) $iv['feedback'], 0, 120)) ?></div>
                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <?php if (strtoupper((string) $iv['stage']) === 'SCHEDULED'): ?>
                  <form method="post" action="<?= esc(base_url('/hr/recruitment/interview/' . (int) $iv['id'] . '/update')) ?>" class="d-inline">
                    <?= csrf_field() ?><input type="hidden" name="stage" value="DONE">
                    <button class="btn btn-sm btn-success" title="Mark completed"><i class="bi bi-check-lg"></i></button>
                  </form>
                <?php endif; ?>
                <form method="post" action="<?= esc(base_url('/hr/recruitment/interview/' . (int) $iv['id'] . '/delete')) ?>" class="d-inline" onsubmit="return confirm('Remove this interview?')">
                  <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
            <tr class="table-light">
              <td colspan="6" class="py-1">
                <details>
                  <summary class="small text-primary" style="cursor:pointer"><?= $hasFb ? 'Edit feedback' : 'Add feedback' ?></summary>
                  <form method="post" action="<?= esc(base_url('/hr/recruitment/interview/' . (int) $iv['id'] . '/update')) ?>" class="row g-2 mt-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="stage" value="<?= strtoupper((string) $iv['stage']) === 'SCHEDULED' ? 'DONE' : esc((string) $iv['stage']) ?>">
                    <div class="col-6 col-md-2"><label class="form-label small mb-1">Rating</label>
                      <select class="form-select form-select-sm" name="rating">
                        <option value="0">-</option>
                        <?php for ($n = 1; $n <= 5; $n++): ?><option value="<?= $n ?>" <?= (int) $iv['rating'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endfor; ?>
                      </select></div>
                    <div class="col-12 col-md-10"><label class="form-label small mb-1">Feedback</label>
                      <input class="form-control form-control-sm" name="feedback" value="<?= esc((string) $iv['feedback']) ?>" placeholder="Strengths, concerns, recommendation"></div>
                    <div class="col-12"><button class="btn btn-sm btn-outline-primary">Save feedback</button></div>
                  </form>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <details class="mt-2">
        <summary class="small">Schedule an interview</summary>
        <form method="post" action="<?= esc(base_url('/hr/recruitment/candidate/' . $id . '/interview')) ?>" class="row g-2 mt-1">
          <?= csrf_field() ?>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Round</label><input type="number" min="1" class="form-control form-control-sm" name="round_no" value="<?= count($interviews) + 1 ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">When</label><input type="datetime-local" class="form-control form-control-sm" name="scheduled_at"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Mode</label>
            <select class="form-select form-select-sm" name="mode"><?php foreach (hr_interview_modes() as $md): ?><option><?= esc($md) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Interviewer (email)</label><input class="form-control form-control-sm" name="interviewer"></div>
          <div class="col-12 col-md-6"><label class="form-label small mb-1">Location / Link</label><input class="form-control form-control-sm" name="location"></div>
          <div class="col-12 col-md-6"><label class="form-label small mb-1">Notes</label><input class="form-control form-control-sm" name="notes"></div>
          <div class="col-12"><button class="btn btn-outline-primary btn-sm">Add interview</button></div>
        </form>
      </details>
    </div>

    <div class="card p-3">
      <div class="sec-title mb-2"><i class="bi bi-envelope-paper me-1 text-primary"></i>Offer</div>
      <?php if ($offer !== null): ?>
        <div class="small mb-2">Current: <?= hr_offer_status_badge((string) $offer['status']) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= esc(base_url('/hr/recruitment/candidate/' . $id . '/offer')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-6 col-md-4"><label class="form-label small mb-1">Position</label><input class="form-control form-control-sm" name="position_title" value="<?= esc((string) ($offer['position_title'] ?? $s['position_title'])) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label small mb-1">Salary</label><input class="form-control form-control-sm" name="offered_salary" value="<?= esc((string) ($offer['offered_salary'] ?? $s['salary_offered'])) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label small mb-1">Joining Date</label><input type="date" class="form-control form-control-sm" name="joining_date" value="<?= esc((string) ($offer['joining_date'] ?? $s['joining_date'])) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label small mb-1">Status</label>
          <select class="form-select form-select-sm" name="status">
            <?php foreach (hr_offer_statuses() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) ($offer['status'] ?? 'DRAFT')) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12 col-md-8"><label class="form-label small mb-1">Notes</label><input class="form-control form-control-sm" name="notes" value="<?= esc((string) ($offer['notes'] ?? '')) ?>"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save offer</button></div>
      </form>
    </div>

    <div class="card p-3 mt-3">
      <div class="sec-title mb-2"><i class="bi bi-chat-left-text me-1 text-primary"></i>Communication History (<?= count($comms) ?>)</div>
      <?php if ($comms === []): ?><div class="small text-muted mb-2">No contact logged yet.</div><?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-2">
          <thead class="table-light"><tr><th>When</th><th>Channel</th><th>Dir</th><th>Summary</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($comms as $c): ?>
            <tr>
              <td class="small text-nowrap"><?= esc(dt_fmt((string) $c['created_at'])) ?></td>
              <td class="small"><?= esc(hr_comm_channels()[strtoupper((string) $c['channel'])] ?? (string) $c['channel']) ?></td>
              <td class="small"><span class="badge <?= strtoupper((string) $c['direction']) === 'IN' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= strtoupper((string) $c['direction']) === 'IN' ? 'In' : 'Out' ?></span></td>
              <td class="small"><?= esc((string) ($c['summary'] ?: substr((string) $c['body'], 0, 80))) ?>
                <div class="text-muted" style="font-size:.72rem">by <?= esc((string) $c['logged_by']) ?></div></td>
              <td class="text-end">
                <form method="post" action="<?= esc(base_url('/hr/recruitment/comm/' . (int) $c['id'] . '/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this entry?')">
                  <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= esc(base_url('/hr/recruitment/candidate/' . $id . '/comm')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Channel</label>
          <select class="form-select form-select-sm" name="channel"><?php foreach (hr_comm_channels() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Direction</label>
          <select class="form-select form-select-sm" name="direction"><?php foreach (hr_comm_directions() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-6"><label class="form-label small mb-1">Summary</label>
          <input class="form-control form-control-sm" name="summary" placeholder="Short subject"></div>
        <div class="col-12"><label class="form-label small mb-1">Details</label>
          <textarea class="form-control form-control-sm" name="body" rows="2"></textarea></div>
        <div class="col-12"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Log communication</button></div>
      </form>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

function hr_post_candidate_link(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    if (ef_find($id) === null) { http_response_code(404); echo 'Candidate not found.'; exit; }
    hr_application_link_save($id, hr_int_or_null($_POST['opening_id'] ?? null), (string) $u['email']);
    activity_log($u, 'HR_CANDIDATE_LINKED', 'Application #' . $id);
    flash('success', 'Vacancy link updated.');
    redirect(base_url('/hr/recruitment/candidates/' . $id));
}

function hr_post_candidate_stage(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $status = strtoupper(trim((string) ($_POST['status'] ?? '')));
    if (!ef_set_status($id, $status, (string) ($_POST['remarks'] ?? ''), $u)) {
        flash('danger', 'Unknown pipeline status.');
    } else {
        flash('success', 'Candidate moved to ' . ef_status_label($status) . '.');
    }
    redirect(base_url('/hr/recruitment/candidates/' . $id));
}

function hr_post_candidate_meta(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    if (ef_find($id) === null) { http_response_code(404); echo 'Candidate not found.'; exit; }
    hr_candidate_meta_save($id, $_POST, (string) $u['email']);
    activity_log($u, 'HR_CANDIDATE_SOURCING', 'Application #' . $id);
    flash('success', 'Sourcing details saved.');
    redirect(base_url('/hr/recruitment/candidates/' . $id));
}

function hr_post_candidate_comm(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    if (ef_find($id) === null) { http_response_code(404); echo 'Candidate not found.'; exit; }
    if (hr_candidate_comm_add($id, $_POST, (string) $u['email']) === 0) {
        flash('danger', 'Enter a summary or some detail to log.');
    } else {
        activity_log($u, 'HR_CANDIDATE_COMM', 'Application #' . $id);
        flash('success', 'Communication logged.');
    }
    redirect(base_url('/hr/recruitment/candidates/' . $id));
}

function hr_post_comm_delete(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $sub = (int) val('SELECT submission_id FROM hr_candidate_comms WHERE id=?', [$id]);
    hr_candidate_comm_delete($id);
    flash('success', 'Entry deleted.');
    redirect(base_url('/hr/recruitment/candidates/' . $sub));
}

function hr_post_candidate_interview(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    if (ef_find($id) === null) { http_response_code(404); echo 'Candidate not found.'; exit; }
    $link = hr_application_link($id);
    hr_interview_save(null, $id, $link ? hr_int_or_null($link['opening_id']) : null, $_POST, (string) $u['email']);
    activity_log($u, 'HR_INTERVIEW_SCHEDULED', 'Application #' . $id);
    flash('success', 'Interview scheduled.');
    redirect(base_url('/hr/recruitment/candidates/' . $id));
}

function hr_post_candidate_offer(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    if (ef_find($id) === null) { http_response_code(404); echo 'Candidate not found.'; exit; }
    $link = hr_application_link($id);
    hr_offer_save($id, $link ? hr_int_or_null($link['opening_id']) : null, $_POST, (string) $u['email']);
    activity_log($u, 'HR_OFFER_SAVED', 'Application #' . $id);
    flash('success', 'Offer saved.');
    redirect(base_url('/hr/recruitment/candidates/' . $id));
}

/* ----------------------------------------------------------------- interviews ---- */

function hr_page_interviews(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $fStage = strtoupper(trim((string) ($_GET['stage'] ?? '')));
    $filters = ['limit' => 200];
    if ($fStage !== '') $filters['stage'] = $fStage;
    $list = hr_interviews_all($filters);

    layout_open('Interviews', $u, '/hr/recruitment');
    hr_tabs('/hr/recruitment');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-calendar-event me-2 text-primary"></i>Interviews</h4>
  <div class="btn-group btn-group-sm">
    <?php foreach (['' => 'All'] + hr_interview_stages() as $k => $lbl): ?>
      <a class="btn btn-outline-secondary <?= $fStage === $k ? 'active' : '' ?>" href="?stage=<?= esc($k) ?>"><?= esc($lbl) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Candidate</th><th>Round</th><th>When</th><th>Mode</th><th>Interviewer</th><th>Stage</th><th>Rating</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $iv): ?>
      <tr>
        <td class="small"><div class="fw-semibold"><?= esc((string) $iv['full_name']) ?></div><div class="text-muted"><?= esc((string) $iv['position_title']) ?></div></td>
        <td class="small"><?= (int) $iv['round_no'] ?></td>
        <td class="small"><?= esc((string) ($iv['scheduled_at'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) $iv['mode']) ?></td>
        <td class="small"><?= esc((string) $iv['interviewer']) ?></td>
        <td><?= hr_interview_stage_badge((string) $iv['stage']) ?></td>
        <td class="small"><?= (int) $iv['rating'] ?: '-' ?></td>
        <td class="text-end"><a class="small" href="<?= esc(base_url('/hr/recruitment/candidates/' . (int) $iv['submission_id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list === []): ?><tr><td colspan="8" class="text-center text-muted py-3">No interviews.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_post_interview_update(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $iv = hr_interview($id);
    if ($iv === null) { http_response_code(404); echo 'Interview not found.'; exit; }
    if (!hr_interview_update($id, (string) ($_POST['stage'] ?? 'DONE'),
        (string) ($_POST['feedback'] ?? ''), (int) ($_POST['rating'] ?? 0), (string) $u['email'])) {
        flash('danger', 'Unknown interview status.');
    } else {
        flash('success', 'Interview updated.');
    }
    redirect(base_url('/hr/recruitment/candidates/' . (int) $iv['submission_id']));
}

function hr_post_interview_delete(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $iv = hr_interview($id);
    if ($iv === null) { http_response_code(404); echo 'Interview not found.'; exit; }
    hr_interview_delete($id);
    activity_log($u, 'HR_INTERVIEW_DELETED', 'Interview #' . $id);
    flash('success', 'Interview removed.');
    redirect(base_url('/hr/recruitment/candidates/' . (int) $iv['submission_id']));
}
