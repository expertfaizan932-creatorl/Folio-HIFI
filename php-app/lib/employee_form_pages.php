<?php
/**
 * WorkFolio PHP - screens for the Employee Form (hiring intake).
 *
 * One page does both jobs the sidebar promises: the hiring form itself at the
 * top, and every submission underneath it. They belong on one screen because the
 * person filling this in is the same person who has to see what has already come
 * in - a form that lands in a separate list is a form whose results nobody looks
 * at until somebody remembers to go looking.
 *
 * Kept out of pages.php on purpose: that file already carries the supervisor talk
 * UI, and a new feature should not risk disturbing it.
 */

/* ------------------------------------------------------- shared rendering ---- */

/** Bootstrap tint for a pipeline status, so the list is scannable at a glance. */
function ef_badge(string $status): string {
    $map = [
        'SUBMITTED'    => 'bg-secondary',
        'UNDER_REVIEW' => 'bg-info text-dark',
        'SHORTLISTED'  => 'bg-primary',
        'INTERVIEW'    => 'bg-warning text-dark',
        'HIRED'        => 'bg-success',
        'REJECTED'     => 'bg-danger',
    ];
    $s = strtoupper(trim($status));
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">'
        . esc(isset(ef_statuses()[$s]) ? ef_status_label($s) : $s) . '</span>';
}

/** One labelled input, laid out from the field map. */
function ef_input(string $key, array $f, $value): void {
    [$label, $type, $req, $options, $hint] = $f + [4 => ''];
    $max = (int) ($f[5] ?? 200);
    $id = 'ef_' . $key;
    $attrs = 'class="form-control" id="' . esc($id) . '" name="' . esc($key) . '"'
        . ' maxlength="' . esc((string) $max) . '"'
        . ($req ? ' required' : '');
    ?>
    <div class="col-12 col-md-6">
      <label class="form-label" for="<?= esc($id) ?>">
        <?= esc($label) ?><?= $req ? ' <span class="text-danger">*</span>' : '' ?>
      </label>
      <?php if ($type === 'select'): ?>
        <select <?= $attrs ?> data-default="">
          <option value="">&mdash;</option>
          <?php foreach ($options as $o): ?>
            <option<?= (string) $value === (string) $o ? ' selected' : '' ?>><?= esc($o) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($type === 'textarea'): ?>
        <textarea <?= $attrs ?> rows="3"><?= esc((string) $value) ?></textarea>
      <?php else: ?>
        <input type="<?= esc($type) ?>" <?= $attrs ?> value="<?= esc((string) $value) ?>">
      <?php endif; ?>
      <?php if ($hint !== ''): ?><div class="form-text"><?= esc($hint) ?></div><?php endif; ?>
    </div>
    <?php
}

/** A read-only value for the detail page. Empty values show as a dash. */
function ef_readonly(string $key, array $f, $value): void {
    $v = trim((string) $value);
    echo '<div class="col-12 col-md-4"><div class="small text-muted">' . esc($f[0]) . '</div>';
    echo '<div class="fw-semibold">'
        . ($v === '' ? '<span class="text-muted">-</span>'
                     : (in_array($f[1], ['url'], true) && $v !== ''
                        ? '<a href="' . esc($v) . '" target="_blank" rel="noopener noreferrer">' . esc($v) . '</a>'
                        : nl2br(esc($v))))
        . '</div></div>';
}

/** Marks the section a first-time reader is looking at, in both tabs. */
function ef_section_tabs(array $sections, string $active): string {
    $out = '<ul class="nav nav-pills flex-wrap gap-1 mb-3">';
    foreach ($sections as $s) {
        $out .= '<li class="nav-item"><button type="button" class="nav-link py-1 px-3 small'
            . ($s['key'] === $active ? ' active' : '')
            . '" data-ef-jump="' . esc($s['key']) . '">' . esc($s['title']) . '</button></li>';
    }
    return $out . '</ul>';
}

/* ================================================================= the page === */

/**
 * GET renders the form and every submission. POST is handled by
 * page_employee_form_save() so the two are separately addressable in the router.
 */
function page_employee_form(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);

    /* A rejected submission comes back to a blank form by POST/redirect/GET, so
       the re-render reads what was typed from the session rather than asking the
       person to type it all again. */
    $sticky = $_SESSION['ef_sticky'] ?? [];
    if (is_array($sticky)) unset($_SESSION['ef_sticky']);
    $errors = $_SESSION['ef_errors'] ?? [];
    if (is_array($errors)) unset($_SESSION['ef_errors']);

    $filter = strtoupper(trim((string) ($_GET['status'] ?? '')));
    if ($filter !== '' && !isset(ef_statuses()[$filter])) $filter = '';
    $list = ef_all($filter);
    $counts = ef_status_counts();
    $sections = ef_sections();

    layout_open('Employee Form', $u, '/employee-form');
    ?>
<style>
    /* Two columns on a wide screen: the form is long, and nobody should have to
       scroll past all of it to reach the submissions they came to read. */
    .ef-split { display: grid; gap: 1rem; grid-template-columns: 1fr; }
    @media (min-width: 1200px) { .ef-split { grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr); align-items: start; } }
    .ef-sec + .ef-sec { margin-top: 1.25rem; }
    .ef-sticky { position: sticky; top: 1rem; }
    @media (max-width: 1199.98px) { .ef-sticky { position: static; } }
    .ef-scroll { max-height: 620px; overflow-y: auto; }
    .ef-list-name { font-weight: 600; }
    .ef-sub { font-size: .8rem; }
    .ef-chiprow .btn { border-radius: 999px; font-size: .8rem; }
    .ef-drop { border: 1px dashed var(--wf-border-strong, #bbb); border-radius: .5rem; padding: .75rem; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-file-earmark-person me-2 text-primary"></i>Employee Form</h4>
    <div class="text-muted ef-sub">Hiring intake. Fill this in for a candidate and submit - every submission is listed below.</div>
  </div>
  <div class="ef-chiprow d-flex flex-wrap gap-1">
    <a class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>"
       href="<?= esc(base_url('/employee-form')) ?>">All <?php $all = array_sum($counts); echo (int) $all; ?></a>
    <?php foreach (ef_statuses() as $key => $label): ?>
      <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
         href="<?= esc(base_url('/employee-form?status=' . rawurlencode($key))) ?>"><?= esc($label) ?>
         <?= (int) ($counts[$key] ?? 0) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (is_array($errors) && $errors !== []): ?>
  <div class="alert alert-danger">
    <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>This application was not saved - <?= count($errors) ?> thing<?= count($errors) === 1 ? '' : 's' ?> to fix:</div>
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="ef-split">

  <!-- ------------------------------------------------ the hiring form ---- -->
  <div class="card p-3 p-md-4">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h5 class="mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>New application</h5>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="efClear"><i class="bi bi-eraser"></i> Clear</button>
    </div>
    <p class="text-muted ef-sub mb-3">Fields marked <span class="text-danger">*</span> are required. Attach the CV if you have it.</p>

    <?= ef_section_tabs($sections, 'candidate') ?>

    <form method="post" action="<?= esc(base_url('/employee-form/save')) ?>" enctype="multipart/form-data" id="efForm" novalidate>
      <?= csrf_field() ?>

      <?php foreach ($sections as $s): ?>
        <div class="ef-sec" id="efsec-<?= esc($s['key']) ?>">
          <h6 class="fw-bold text-uppercase ef-sub text-primary mb-2"><?= esc($s['title']) ?></h6>
          <?php if ($s['hint'] !== ''): ?><div class="text-muted ef-sub mb-2"><?= esc($s['hint']) ?></div><?php endif; ?>
          <div class="row g-3">
            <?php foreach ($s['keys'] as $key):
                $f = ef_fields()[$key];
                ef_input($key, $f, $sticky[$key] ?? ''); ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="ef-sec ef-drop mt-3">
        <label class="form-label mb-1" for="ef_cv"><i class="bi bi-paperclip me-1"></i>CV / Resume</label>
        <input type="file" class="form-control" id="ef_cv" name="cv" accept=".pdf,.doc,.docx">
        <div class="form-text">PDF, DOC or DOCX, up to 6 MB. Optional.</div>
      </div>

      <div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Submit application</button>
        <a class="btn btn-outline-secondary" href="<?= esc(base_url('/employee-form')) ?>">Cancel</a>
      </div>
    </form>
  </div>

  <!-- --------------------------------------------- the submissions ---- -->
  <div class="card p-3 ef-sticky">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h5 class="mb-0"><i class="bi bi-inbox me-2 text-primary"></i>Submissions</h5>
      <span class="text-muted ef-sub"><?= count($list) ?> shown<?= $filter !== '' ? ' - ' . esc(ef_status_label($filter)) : '' ?></span>
    </div>

    <?php if ($list === []): ?>
      <div class="text-center text-muted py-4">
        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
        <?= $filter !== '' ? 'Nothing in ' . esc(ef_status_label($filter)) . ' yet.' : 'No applications yet. Fill the form above and submit the first one.' ?>
      </div>
    <?php else: ?>
      <div class="table-responsive ef-scroll">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>Candidate</th><th>Position</th><th>Status</th><th>Received</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td>
                <div class="ef-list-name"><?= esc($r['full_name']) ?></div>
                <div class="text-muted ef-sub"><?= esc($r['email']) ?><?= trim((string) $r['phone']) !== '' ? ' &middot; ' . esc($r['phone']) : '' ?></div>
                <?php if ((string) ($r['cv_file'] ?? '') !== ''): ?>
                  <a class="ef-sub" href="<?= esc(base_url('/employee-form/cv/' . (int) $r['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> CV</a>
                <?php endif; ?>
              </td>
              <td>
                <div><?= esc($r['position_title']) ?></div>
                <div class="text-muted ef-sub"><?= esc($r['department'] ?: '-') ?></div>
              </td>
              <td><?= ef_badge((string) $r['status']) ?></td>
              <td class="ef-sub text-nowrap"><?= esc(dt_fmt((string) ($r['created_at'] ?? ''), 'd M')) ?><br><span class="text-muted"><?= esc(dt_fmt((string) ($r['created_at'] ?? ''), 'h:i a')) ?></span></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/employee-form/' . (int) $r['id'])) ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('efForm');

  /* The browser's own required/type messages are replaced rather than stacked on
     top of, because a half-translated native bubble on a dark form reads as a
     bug. The server checks the same list again regardless - this is only about
     telling somebody what to fix without a round trip. */
  form.addEventListener('submit', function (ev) {
    var problems = [];
    var nodes = form.querySelectorAll('input[name], select[name], textarea[name]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var v = (el.value || '').trim();
      if (el.required && v === '') { problems.push(el.name); continue; }
      if (v === '' || el.type === 'file') continue;
      if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) problems.push(el.name);
      if (el.type === 'date' && isNaN(Date.parse(v))) problems.push(el.name);
    }
    if (problems.length === 0) return;
    ev.preventDefault();
    var first = form.querySelector('[name="' + problems[0] + '"]');
    alert('Please complete: ' + problems.join(', ') + '.');
    if (first) { first.focus(); if (first.scrollIntoView) first.scrollIntoView({ block: 'center' }); }
  });

  document.getElementById('efClear').addEventListener('click', function () {
    if (!confirm('Clear the whole form?')) return;
    form.reset();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  var tabs = document.querySelectorAll('[data-ef-jump]');
  for (var j = 0; j < tabs.length; j++) {
    tabs[j].addEventListener('click', function () {
      for (var k = 0; k < tabs.length; k++) tabs[k].classList.remove('active');
      this.classList.add('active');
      var target = document.getElementById('efsec-' + this.getAttribute('data-ef-jump'));
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
})();
</script>
    <?php
    layout_close();
}

/* ================================================================== saving === */

/**
 * Stores one application, then bounces back to the form so a refresh cannot
 * resubmit it. On failure the typed values ride along in the session, because
 * making somebody retype a thirty-field form because of one missing box is how
 * people end up abandoning the form halfway.
 */
function page_employee_form_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();

    $cv = null;
    if (isset($_FILES['cv']) && is_array($_FILES['cv'])) $cv = $_FILES['cv'];

    /* Cleaned once here so the confirmation can name the candidate without
       re-reading the raw request, and so the sticky copy below is exactly what
       was validated. */
    $values = ef_clean_post($_POST);
    $out = ef_create($values, $u, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $cv);

    if (isset($out['errors']) && $out['errors'] !== []) {
        $_SESSION['ef_errors'] = $out['errors'];
        $_SESSION['ef_sticky'] = $values;
        redirect(base_url('/employee-form'));
    }

    if (!empty($out['warning'])) flash('warning', 'Application saved, but the CV was not: ' . $out['warning']);
    flash('success', 'Application submitted for ' . $values['full_name'] . '.');
    redirect(base_url('/employee-form?status=SUBMITTED'));
}

/* ============================================================ one record === */

function page_employee_form_view(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $r = ef_find($id);
    if ($r === null) {
        flash('danger', 'That application no longer exists.');
        redirect(base_url('/employee-form'));
    }

    $fields = ef_fields();
    $sections = ef_sections();
    layout_open('Employee Form - ' . $r['full_name'], $u, '/employee-form');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-file-earmark-person me-2 text-primary"></i><?= esc($r['full_name']) ?></h4>
    <div class="text-muted ef-sub"><?= esc($r['position_title']) ?> &middot; received <?= esc(dt_fmt((string) ($r['created_at'] ?? ''))) ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php if ((string) ($r['cv_file'] ?? '') !== ''): ?>
      <a class="btn btn-outline-primary" href="<?= esc(base_url('/employee-form/cv/' . $id)) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1"></i>Open CV</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= esc(base_url('/employee-form')) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="ef-split">
  <div class="card p-3 p-md-4">
    <?php foreach ($sections as $s): ?>
      <div class="ef-sec">
        <h6 class="fw-bold text-uppercase ef-sub text-primary mb-2"><?= esc($s['title']) ?></h6>
        <div class="row g-3">
          <?php foreach ($s['keys'] as $key): ef_readonly($key, $fields[$key], $r[$key] ?? ''); endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="ef-sec">
      <h6 class="fw-bold text-uppercase ef-sub text-primary mb-2">Record</h6>
      <div class="row g-3">
        <?php ef_readonly('received', ['Received', 'text'], dt_fmt((string) ($r['created_at'] ?? ''))); ?>
        <?php ef_readonly('entered_by', ['Entered By', 'text'], (string) ($r['submitted_by'] ?? '')); ?>
        <?php ef_readonly('status', ['Status', 'text'], ef_status_label((string) $r['status'])); ?>
        <?php ef_readonly('status_changed', ['Status Changed', 'text'], dt_fmt((string) ($r['status_updated_at'] ?? ''))); ?>
        <?php if ((string) ($r['cv_orig'] ?? '') !== ''): ef_readonly('cv', ['CV File', 'text'], (string) $r['cv_orig']); endif; ?>
      </div>
    </div>
  </div>

  <div class="card p-3 ef-sticky">
    <h5 class="mb-3"><i class="bi bi-signpost-split me-2 text-primary"></i>Hiring status</h5>
    <form method="post" action="<?= esc(base_url('/employee-form/' . $id . '/status')) ?>">
      <?= csrf_field() ?>
      <div class="mb-2">
        <label class="form-label" for="efStatus">Move to</label>
        <select class="form-select" name="status" id="efStatus">
          <?php foreach (ef_statuses() as $key => $label): ?>
            <option value="<?= esc($key) ?>"<?= (string) $r['status'] === $key ? ' selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label" for="efRemarks">Remarks</label>
        <textarea class="form-control" name="remarks" id="efRemarks" rows="4" maxlength="2000"
                  placeholder="Interview outcome, offer details, anything the next person needs to know"><?= esc((string) ($r['remarks'] ?? '')) ?></textarea>
      </div>
      <button class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i>Save status</button>
    </form>

    <div class="mt-3 pt-3 border-top">
      <div class="small text-muted mb-1">Current</div>
      <div><?= ef_badge((string) $r['status']) ?></div>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

function page_employee_form_status(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();

    $status = strtoupper(trim((string) ($_POST['status'] ?? '')));
    if (!isset(ef_statuses()[$status])) {
        flash('danger', 'That is not a hiring status.');
        redirect(base_url('/employee-form/' . $id));
    }
    if (!ef_set_status($id, $status, (string) ($_POST['remarks'] ?? ''), $u)) {
        flash('danger', 'That application no longer exists.');
        redirect(base_url('/employee-form'));
    }
    flash('success', 'Marked as ' . ef_status_label($status) . '.');
    redirect(base_url('/employee-form/' . $id));
}