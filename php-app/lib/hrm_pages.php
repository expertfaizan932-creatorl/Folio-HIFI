<?php
/**
 * WorkFolio PHP - screens for the HRM employee information form.
 *
 * Kept apart from pages.php on purpose: that file already carries the supervisor
 * talk UI, and this feature should not risk disturbing it.
 *
 * Two audiences, two very different pages. HR sees a status board and can fill in
 * their own section 8. The employee sees one long form at a public token URL and
 * never sees HR's half at all. The split is enforced in lib/hrm.php as well, so
 * it does not rest on the pages happening to leave the inputs out.
 */

/* ------------------------------------------------------- shared rendering ---- */

/** One labelled input, laid out from the field map. */
function hrm_input(string $key, array $f, $value, bool $disabled = false): void {
    [$label, $type, $req, $options, $hint] = $f + [4 => ''];
    $id = 'f_' . $key;
    $common = 'class="form-control form-control-sm" id="' . esc($id) . '" name="' . esc($key) . '"'
            . ($req ? ' required' : '')
            . ($disabled ? ' disabled' : '');
    ?>
    <div class="col-12 col-md-6">
      <label class="form-label small mb-1" for="<?= esc($id) ?>">
        <?= esc($label) ?><?= $req ? ' <span class="text-danger">*</span>' : '' ?>
      </label>
      <?php if ($type === 'select'): ?>
        <select <?= $common ?>>
          <option value="">&mdash;</option>
          <?php foreach ($options as $o): ?>
            <option<?= (string) $value === (string) $o ? ' selected' : '' ?>><?= esc($o) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($type === 'textarea'): ?>
        <textarea <?= $common ?> rows="2"><?= esc((string) $value) ?></textarea>
      <?php else: ?>
        <input type="<?= esc($type === 'url' ? 'url' : ($type === 'date' ? 'date' : $type)) ?>"
               <?= $common ?> value="<?= esc((string) $value) ?>">
      <?php endif; ?>
      <?php if ($hint !== ''): ?><div class="form-text"><?= esc($hint) ?></div><?php endif; ?>
    </div>
    <?php
}

/** A read-only value shown in the review tables. */
function hrm_readonly_value(string $key, array $f, $value): void {
    [$label] = $f;
    if ($key === 'account_number') {
        /* Never render a full account number in a review list. */
        echo '<div class="col-12 col-md-6"><div class="small text-muted">' . esc($label) . '</div>';
        echo '<div class="fw-semibold">' . esc(hrm_mask_account((string) $value)) . '</div></div>';
        return;
    }
    $v = (string) $value;
    echo '<div class="col-12 col-md-6"><div class="small text-muted">' . esc($label) . '</div>';
    echo '<div class="fw-semibold">' . ($v === '' ? '<span class="text-muted">-</span>' : nl2br(esc($v))) . '</div></div>';
}

function hrm_status_badge(string $status): string {
    $map = [
        'DRAFT' => 'bg-secondary', 'SUBMITTED' => 'bg-success',
        'VERIFIED' => 'bg-primary', 'REOPENED' => 'bg-warning text-dark',
    ];
    $s = strtoupper(trim($status));
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">'
         . esc($s === '' ? 'NOT STARTED' : $s) . '</span>';
}

/** Progress across the seven employee-owned sections, for the overview table. */
function hrm_completeness(array $rec, array $assets, array $docs): int {
    $done = 0; $total = 0;
    foreach (hrm_fields() as $key => $f) {
        if (empty($f[2])) continue;
        $total++;
        if (trim((string) ($rec[$key] ?? '')) !== '') $done++;
    }
    foreach ($assets as $a) { $total++; if ((int) $a['acknowledged'] === 1) $done++; }
    $have = []; foreach ($docs as $d) $have[(string) $d['doc_key']] = true;
    foreach (hrm_document_keys() as $k => $label) { $total++; if (isset($have[$k])) $done++; }
    if ((int) ($rec['bank_confirmed'] ?? 0) === 1) { $total++; $done++; }
    if (trim((string) ($rec['signature_name'] ?? '')) !== '' || trim((string) ($rec['signature_png'] ?? '')) !== '') {
        $total++; $done++;
    }
    return $total === 0 ? 0 : (int) round($done * 100 / $total);
}

/* ---------------------------------------------------------- public layout ---- */

function hrm_public_head(string $title): void {
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?= esc($title) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= esc(base_url('/assets/wf.css')) ?>?v=20261005a" rel="stylesheet">
<script>
(function () { try { var t = localStorage.getItem('wf-theme');
  if (!t) t = (window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t); } catch (e) {} })();
</script>
<style>
    .hrm-wrap { max-width: 980px; margin: 0 auto; }
    /* Solid brand colour first, then the gradient override. A browser that cannot
       do background-clip:text falls back to the solid colour instead of painting
       nothing, and inline-block plus padding keeps the clip off the ascenders -
       without them the cap-height of this heading was visibly sliced. */
    .hrm-head { color: var(--wf-violet); display: inline-block; padding: .05em .08em .07em 0;
        background: var(--wf-grad); background-size: 100% 100%;
        -webkit-background-clip: text; background-clip: text;
        -webkit-text-fill-color: transparent; font-weight: 800; letter-spacing: -.02em; }
    @supports not ((-webkit-background-clip: text) or (background-clip: text)) {
        .hrm-head { background: none; -webkit-text-fill-color: currentColor; }
    }
    .sec-title { font-size: 1.05rem; font-weight: 700; }
    .req-note { border-left: 3px solid var(--wf-accent, #8b5cf6); }
    #sigPad { touch-action: none; border: 1px dashed var(--wf-border-strong); border-radius: 8px;
        background: var(--wf-surface-2, #fff); cursor: crosshair; display: block; }
    .sig-preview { max-width: 260px; max-height: 90px; }
</style>
</head>
<body class="p-3 p-md-4">
    <?php
}

function hrm_public_foot(): void {
    ?>
<div class="text-center mt-4 small text-muted">
    <i class="bi bi-shield-lock me-1"></i>Your entries travel over an encrypted connection. Only
    HR and you can open this form.
</div>
</body>
</html>
    <?php
}

/* ============================================================== employee's === */

function page_hrm_public(string $token): void {
    $emp = hrm_employee_by_token($token);
    if ($emp === null) { http_response_code(404); hrm_public_head('Link not valid'); ?>
        <div class="card p-4 hrm-wrap"><h5 class="mb-2">This link is not valid</h5>
        <p class="text-muted mb-0">It may have been replaced by a newer link. Please ask HR to send
        you the current one.</p></div><?php hrm_public_foot(); return; }

    $rec = hrm_record((string) $emp['email']);
    if ($rec === null) { http_response_code(404); exit; }

    $errors = [];
    $submit = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($submit) {
        if (getenv('WF_DEBUG_POST')) {
            error_log('WF_POST_KEYS ' . json_encode(array_keys($_POST)));
            error_log('WF_FILES ' . json_encode(array_map(function ($k) {
                return is_array($k) ? implode(',', $k) : $k;
            }, array_keys((array) ($_FILES['docs'] ?? [])))));
        }
        require_csrf();

        /* Sections 1-5, plus the bank confirmation flag. */
        $missing = hrm_save_employee($rec, $_POST);
        if ($missing !== []) {
            $names = array_map(function ($k) { return hrm_fields()[$k][0]; }, $missing);
            $errors[] = 'Please complete the required fields: ' . implode(', ', $names) . '.';
        }

        /* Section 6. */
        hrm_save_assets((string) $rec['email'], $_POST);

        /* Section 7, one file at a time so a single bad file does not sink the rest. */
        if (!empty($_FILES['docs']) && is_array($_FILES['docs']['name'] ?? null)) {
            foreach (array_keys(hrm_document_keys()) as $dk) {
                if (!isset($_FILES['docs']['name'][$dk])) continue;
                $one = [
                    'name'     => $_FILES['docs']['name'][$dk],
                    'type'     => $_FILES['docs']['type'][$dk] ?? '',
                    'tmp_name' => $_FILES['docs']['tmp_name'][$dk] ?? '',
                    'error'    => $_FILES['docs']['error'][$dk] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $_FILES['docs']['size'][$dk] ?? 0,
                ];
                $label = hrm_document_keys()[$dk];
                if (!is_uploaded_file((string) $one['tmp_name']) && (int) $one['error'] === UPLOAD_ERR_OK) {
                    $errors[] = $label . ': the upload could not be read. Try again.';
                    continue;
                }
                $msg = null;
                try {
                    [, $msg] = hrm_store_upload((string) $rec['email'], $dk, $one);
                } catch (Throwable $e) {
                    error_log('folio: hrm upload failed: ' . $e->getMessage());
                    $msg = 'The file could not be saved.';
                }
                if ($msg !== null && $msg !== 'Nothing sent.') $errors[] = $label . ': ' . $msg;
            }
        }

        /* Section 9. */
        $sigErr = hrm_save_signature($rec, $_POST);
        if ($sigErr !== null) $errors[] = $sigErr;

        /* Re-read: everything below must judge the state that was just written. */
        $rec = hrm_record((string) $rec['email']) ?? $rec;
        $assets = hrm_assets((string) $rec['email']);
        $blockers = hrm_blockers($rec, $assets);

        if ($errors === [] && $blockers === []) {
            q('UPDATE employee_hrm_records SET status=?, submitted_at=?, confirmed_at=?, confirmed_ip=?, last_saved_at=?
               WHERE email=?', [
                'SUBMITTED', now_sql(), now_sql(), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), now_sql(),
                $rec['email'],
            ]);
            /* No signed-in user here: the employee's own email is the actor. */
            activity_log(['email' => (string) $rec['email']], 'HRM_FORM_SUBMITTED', 'Employee information form submitted');
            redirect(base_url('/h/' . $token . '/done'));
        }
        foreach ($blockers as $b) $errors[] = $b;
    }

    $assets = hrm_assets((string) $rec['email']);
    $docs   = hrm_documents((string) $rec['email']);
    $posted = $submit ? $_POST : [];
    $val = function (string $k) use ($rec, $posted) {
        return array_key_exists($k, $posted) ? (string) $posted[$k] : (string) ($rec[$k] ?? '');
    };
    $readonly = hrm_readonly_fields();
    $isDraft  = (string) ($rec['status'] ?? '') === 'DRAFT'
             || (string) ($rec['status'] ?? '') === 'REOPENED';
    /* After a failed submit the tick must reflect what they just sent, not the old
       value, so they can see and correct it. An unticked box is simply absent from
       $_POST, which is why this cannot fall back to the stored value here. */
    $bankTicked = $submit ? !empty($_POST['bank_confirmed'])
                          : (int) ($rec['bank_confirmed'] ?? 0) === 1;

    hrm_public_head('Employee Information Form');
    ?>
<form method="post" enctype="multipart/form-data" id="hrmForm">
  <?= csrf_field() ?>
  <input type="hidden" name="signature_png" id="sigData">
  <input type="hidden" name="asset_id[]" value="">
  <div class="hrm-wrap">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h4 class="hrm-head mb-1">Employee Information Form</h4>
        <div class="small text-muted"><?= esc(employee_full_name($emp)) ?> &middot;
          <?= esc($emp['email']) ?></div>
      </div>
      <div><?= hrm_status_badge((string) ($rec['status'] ?? '')) ?>
        <span class="badge bg-light text-dark"><?= hrm_completeness($rec, $assets, $docs) ?>% complete</span>
      </div>
    </div>

    <?php if ($errors !== []): ?>
      <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>
          <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?></div>
        <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if (!$isDraft): ?>
      <div class="alert alert-info req-note">
        <i class="bi bi-info-circle me-1"></i>You have already submitted this form
        (<?= esc((string) ($rec['submitted_at'] ?? '')) ?>). You can still open it to check what you sent.
        <?php if (count($docs) === 0): ?>
          <div class="mt-1 small">Documents can still be uploaded below if HR asked for any.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card p-3 mb-3">
      <div class="small text-muted mb-2"><i class="bi bi-asterisk text-danger"></i> marks a required
        field. Sections 1&ndash;7 and 9 are yours to fill. Section 8 (HR Record) is completed by HR.</div>
    </div>

    <?php
    $group = 0;
    $titles = ['personal' => '1. Personal Information', 'family' => '2. Father / Family Information',
        'education' => '3. Education & Qualification', 'professional' => '4. Professional Information',
        'salary' => '5. Salary & Bank Information', 'assets' => '6. Company Assets',
        'documents' => '7. Required Documents', 'confirm' => '9. Employee Confirmation'];
    $fields = hrm_fields();
    foreach ($titles as $key => $heading) {
      if ($key === 'assets' || $key === 'documents' || $key === 'confirm') continue;
      $grp = ['personal' => ['full_name','father_name','cnic','dob','gender','marital_status','contact_number','personal_email','current_address','permanent_address'],
              'family' => ['father_cnic','father_contact','father_occupation','emergency_contact_name','emergency_contact_number','emergency_relationship'],
              'education' => ['highest_degree','degree_program','institute','graduation_year','certifications','skills'],
              'professional' => ['employee_code','joining_date','designation','department','employment_type','reporting_manager','previous_company','previous_experience','portfolio_link','linkedin'],
              'salary' => ['salary','bank_name','account_title','account_number']][$key];
      ?>
      <div class="card p-3 mb-3">
        <div class="sec-title mb-3"><?= esc($heading) ?></div>
        <div class="row g-3">
          <?php foreach ($grp as $k):
            if (!isset($fields[$k])) continue;
            hrm_input($k, $fields[$k], $val($k), in_array($k, $readonly, true));
          endforeach; ?>
        </div>

        <?php if ($key === 'salary'): ?>
          <div class="row g-3 mt-1">
            <div class="col-12 col-md-6">
              <div class="form-check p-0 mt-2">
                <input class="form-check-input" type="checkbox" name="bank_confirmed" value="1" id="bankConfirmed"
                       <?= $bankTicked ? 'checked' : '' ?> required>
                <label class="form-check-label small fw-semibold" for="bankConfirmed">
                  Account Number Confirmation
                  <span class="text-danger">*</span>
                </label>
                <div class="form-text">
                  Yes &ndash; I confirm the bank name, account title and account number above are
                  correct, the account is in my own name, and payroll should be paid into it.
                </div>
              </div>
              <?php if (!empty($rec['bank_confirm_date'])): ?>
                <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i>
                  Confirmed on <?= esc((string) $rec['bank_confirm_date']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <?php
    }
    ?>

    <!-- Section 6: repeatable assets -->
    <div class="card p-3 mb-3">
      <div class="sec-title mb-1">6. Company Assets</div>
      <div class="small text-muted mb-3">List every item the company has issued you. If you have none,
        leave the row empty. Each item needs its own acknowledgement.</div>
      <div id="assetRows">
        <?php
        $rows = $assets !== [] ? $assets : [null];
        foreach ($rows as $a):
          $an = $a['asset_name'] ?? ''; $at = $a['asset_type'] ?? ''; $as = $a['asset_serial'] ?? '';
          $ai = $a['issued_date'] ?? ''; $ac = $a['condition_issued'] ?? '';
          $ad = $a['return_date'] ?? ''; $af = $a['condition_return'] ?? ''; $ar = $a['remarks'] ?? '';
          $ack = (int) ($a['acknowledged'] ?? 0) === 1;
        ?>
        <div class="row g-2 mb-2 p-2 rounded asset-row" style="background:var(--wf-surface-2)">
          <input type="hidden" name="asset_id[]" value="<?= esc((string) ($a['id'] ?? '')) ?>">
          <div class="col-12 col-md-4"><label class="form-label small mb-1">Asset Name</label>
            <input class="form-control form-control-sm" name="asset_name[]" value="<?= esc((string) $an) ?>"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">Type</label>
            <input class="form-control form-control-sm" name="asset_type[]" placeholder="Laptop" value="<?= esc((string) $at) ?>"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">ID / Serial</label>
            <input class="form-control form-control-sm" name="asset_serial[]" value="<?= esc((string) $as) ?>"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">Issued</label>
            <input class="form-control form-control-sm" type="date" name="issued_date[]" value="<?= esc((string) $ai) ?>"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">Condition</label>
            <input class="form-control form-control-sm" name="condition_issued[]" value="<?= esc((string) $ac) ?>"></div>
          <div class="col-12 col-md-4"><label class="form-label small mb-1">Return Date</label>
            <input class="form-control form-control-sm" type="date" name="return_date[]" value="<?= esc((string) $ad) ?>"></div>
          <div class="col-12 col-md-4"><label class="form-label small mb-1">Condition on Return</label>
            <input class="form-control form-control-sm" name="condition_return[]" value="<?= esc((string) $af) ?>"></div>
          <div class="col-12 col-md-4"><label class="form-label small mb-1">Remarks</label>
            <input class="form-control form-control-sm" name="asset_remarks[]" value="<?= esc((string) $ar) ?>"></div>
          <div class="col-12">
            <div class="form-check mt-1">
              <input class="form-check-input" type="checkbox" name="acknowledged[]" value="1" id=""
                     <?= $ack ? 'checked' : '' ?> required>
              <label class="form-check-label small">
                Employee Acknowledgement <span class="text-danger">*</span> &ndash;
                I have received this item in the condition noted, I will use it for company work,
                and I will return it on request in the condition noted.
              </label>
            </div>
            <?php if ($ack && !empty($a['ack_date'])): ?>
              <div class="small text-success"><i class="bi bi-check-circle me-1"></i>
                Acknowledged on <?= esc((string) $a['ack_date']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="addAsset">
        <i class="bi bi-plus-lg me-1"></i>Add another item
      </button>
    </div>

    <!-- Section 7: documents -->
    <div class="card p-3 mb-3">
      <div class="sec-title mb-3">7. Required Documents</div>
      <div class="row g-3">
        <?php foreach (hrm_document_keys() as $dk => $label):
          $have = null; $orig = '';
          foreach ($docs as $d) if ((string) $d['doc_key'] === $dk) { $have = $d; $orig = (string) $d['orig_name']; }
        ?>
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1" for="doc_<?= esc($dk) ?>"><?= esc($label) ?></label>
          <?php if ($have !== null): ?>
            <div class="small mb-1">
              <i class="bi bi-paperclip"></i> <?= esc($orig) ?>
              <a class="ms-2" target="_blank" rel="noopener"
                 href="<?= esc(base_url('/h/' . $token . '/doc/' . $dk . '/' . rawurlencode((string) $have['file_name']))) ?>">view</a>
              <span class="text-muted">(<?= esc(hrm_format_bytes((int) $have['size_bytes'])) ?>)</span>
            </div>
          <?php endif; ?>
          <input class="form-control form-control-sm" type="file" id="doc_<?= esc($dk) ?>"
                 name="docs[<?= esc($dk) ?>]"
                 accept=".<?= esc(implode(',.', hrm_doc_allow()[$dk])) ?>">
          <div class="form-text">Max 6 MB. Uploading again replaces nothing &ndash; both copies are kept.</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Section 9: declaration and signature -->
    <div class="card p-3 mb-3">
      <div class="sec-title mb-3">9. Employee Confirmation</div>
      <div class="p-2 rounded small mb-3" style="background:var(--wf-surface-2)">
        <div class="fw-semibold mb-1">Employee Declaration</div>
        I confirm that the information given in this form is true and correct to the best of my
        knowledge. I confirm the bank account details above are mine and may be used for salary
        payment. I acknowledge the company assets listed above and agree to return them on request.
        I understand that a false statement or a wrong bank account may lead to recovery of any
        amount wrongly paid.
      </div>
      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-5">
          <label class="form-label small mb-1" for="sigCanvas">Draw your signature</label>
          <canvas id="sigCanvas" class="form-control form-control-sm" width="420" height="150"
                  style="width:100%;height:150px"></canvas>
          <div class="d-flex gap-2 mt-1">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="sigClear">Clear</button>
            <span class="small text-muted align-self-center" id="sigState">Not signed yet</span>
          </div>
        </div>
        <div class="col-12 col-md-5">
          <label class="form-label small mb-1" for="sigName">Or type your full name</label>
          <input class="form-control form-control-sm" id="sigName" name="signature_name"
                 maxlength="120" value="<?= esc((string) ($rec['signature_name'] ?? '')) ?>"
                 placeholder="As it appears on your CNIC">
          <div class="form-text">Use this if signing with a mouse is difficult.</div>
        </div>
        <div class="col-12 col-md-2">
          <label class="form-label small mb-1">Date of confirmation</label>
          <div class="form-control form-control-sm bg-body-tertiary"><?= esc(today()) ?></div>
        </div>
      </div>
      <?php if (!empty($rec['signature_name']) || !empty($rec['signature_png'])): ?>
        <div class="small text-success mt-2"><i class="bi bi-check-circle me-1"></i>
          Already signed<?= !empty($rec['confirmed_at']) ? ' on ' . esc((string) $rec['confirmed_at']) : '' ?>.
          Signing again replaces it.</div>
      <?php endif; ?>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
      <button class="btn btn-primary btn-lg" type="submit" id="hrmSubmit">
        <i class="bi bi-send me-1"></i>Submit form
      </button>
      <span class="small text-muted">Your progress is saved as you go.</span>
    </div>
  </div>
</form>
<script>
(function () {
  var cv = document.getElementById('sigCanvas');
  var data = document.getElementById('sigData');
  var state = document.getElementById('sigState');
  var nameEl = document.getElementById('sigName');
  if (!cv || !data) return;
  var ctx = cv.getContext('2d');
  ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';

  function resize() {
    /* The canvas is styled to the column width; keep the backing store matched to
       it or the signature comes out stretched. */
    var r = cv.getBoundingClientRect(), d = Math.max(1, devicePixelRatio || 1);
    var prev = data.value;
    cv.width = Math.round(r.width * d); cv.height = Math.round(r.height * d);
    ctx.lineWidth = 2.2 * d; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.scale(d, d);
    if (prev) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, r.width, r.height); }; im.src = prev; }
  }
  window.addEventListener('resize', resize);
  if (window.ResizeObserver) new ResizeObserver(resize).observe(cv);
  resize();

  var drawing = false, dirty = false;
  function pos(e) {
    var r = cv.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }
  function start(e) { e.preventDefault(); drawing = true; dirty = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p.x + .1, p.y); ctx.stroke(); }
  function move(e) { if (!drawing) return; e.preventDefault(); var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
  function end() { drawing = false; }

  cv.addEventListener('mousedown', start); window.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
  cv.addEventListener('touchstart', start, { passive: false }); cv.addEventListener('touchmove', move, { passive: false });
  cv.addEventListener('touchend', end);

  document.getElementById('sigClear').addEventListener('click', function () {
    ctx.clearRect(0, 0, cv.width, cv.height); data.value = ''; dirty = false;
    state.textContent = 'Not signed yet'; state.className = 'small text-muted align-self-center';
  });

  function syncState() {
    if (data.value || (nameEl.value || '').trim()) {
      state.textContent = 'Signed'; state.className = 'small text-success align-self-center';
    } else {
      state.textContent = 'Not signed yet'; state.className = 'small text-muted align-self-center';
    }
  }
  nameEl.addEventListener('input', syncState);
  syncState();

  document.getElementById('hrmForm').addEventListener('submit', function () {
    if (dirty) { try { data.value = cv.toDataURL('image/png'); } catch (err) {} }
    /* A drawn signature wins, but the typed box must not fight it: clear the typed
       name so the server never sees two different signatures at once. */
    if (data.value) nameEl.value = '';
    var b = document.getElementById('hrmSubmit');
    b.disabled = true; b.textContent = 'Submitting...';
  });

  /* Repeater for assets, cloned from the last row with its values wiped. */
  var wrap = document.getElementById('assetRows');
  document.getElementById('addAsset').addEventListener('click', function () {
    var last = wrap.lastElementChild;
    if (!last) return;
    var clone = last.cloneNode(true);
    clone.querySelectorAll('input').forEach(function (i) {
      i.checked = false;
      if (i.type !== 'checkbox' && i.type !== 'hidden') i.value = '';
      if (i.name === 'asset_id[]') i.value = '';
      i.removeAttribute('id');
    });
    clone.querySelectorAll('label[for]').forEach(function (l) { l.removeAttribute('for'); });
    var cb = clone.querySelector('.form-check-input');
    if (cb) cb.removeAttribute('required');
    wrap.appendChild(clone);
  });
})();
</script>
    <?php
    hrm_public_foot();
}

function page_hrm_public_done(string $token): void {
    $emp = hrm_employee_by_token($token);
    hrm_public_head('Form submitted');
    ?>
<div class="card p-4 hrm-wrap text-center">
  <i class="bi bi-check-circle text-success" style="font-size:2.6rem"></i>
  <h4 class="mt-3 mb-2">Thank you, your form has been submitted</h4>
  <?php if ($emp !== null): ?>
    <p class="text-muted">HR has been notified.
      <?= esc(employee_full_name($emp)) ?> &middot; <?= esc($emp['email']) ?></p>
  <?php else: ?>
    <p class="text-muted">HR has been notified.</p>
  <?php endif; ?>
  <p class="small text-muted mb-0">Keep this link safe. It is the only way back into your form,
    so do not forward it. If you think you have a mistake to fix, email HR and they can reopen it.</p>
</div>
    <?php
    hrm_public_foot();
}

/** Streams a document to the holder of a valid token, for that token's employee only. */
function page_hrm_public_doc(string $token, string $docKey, string $file): void {
    $emp = hrm_employee_by_token($token);
    if ($emp === null) { http_response_code(404); exit; }
    hrm_serve_document((string) $emp['email'], $docKey, $file, null, (string) $emp['email']);
}

/* ===================================================================== HR === */

function page_hrm_overview(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $employees = access_visible_employees($u);
    $status = hrm_status_map();

    layout_open('HRM - Employee Information Form', $u, '/hrm');
    ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Employee Information Form</h4>
    <div class="small text-muted">Send each employee their own private link. They fill sections
      1&ndash;7 and 9; you complete section 8.</div>
  </div>
</div>
<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr>
      <th>Employee</th><th>Department</th><th>Status</th><th>Progress</th>
      <th>Bank</th><th>Assets</th><th>Documents</th><th>Private link</th>
    </tr></thead>
    <tbody>
    <?php foreach ($employees as $e):
      $email = strtolower((string) $e['email']);
      $s = $status[$email] ?? null;
      $rec = $s !== null ? hrm_record($email) : null;
      $assets = $rec !== null ? hrm_assets($email) : [];
      $docs   = $rec !== null ? hrm_documents($email) : [];
      $nAssets = count($assets); $nAck = 0;
      foreach ($assets as $a) if ((int) $a['acknowledged'] === 1) $nAck++;
      $missingDocs = $rec !== null ? count(hrm_documents_missing($email)) : 0;
    ?>
      <tr>
        <td>
          <div class="fw-semibold"><?= esc(employee_full_name($e)) ?></div>
          <div class="small text-muted"><?= esc($email) ?></div>
        </td>
        <td class="small"><?= esc($e['department'] ?? '-') ?></td>
        <td><?= hrm_status_badge((string) ($s['status'] ?? '')) ?></td>
        <td style="min-width:120px">
          <?php if ($rec !== null): $pct = hrm_completeness($rec, $assets, $docs); ?>
            <div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
            <div class="small text-muted"><?= $pct ?>%</div>
          <?php else: ?><span class="small text-muted">Not started</span><?php endif; ?>
        </td>
        <td>
          <?php if ($rec !== null && (int) ($rec['bank_confirmed'] ?? 0) === 1): ?>
            <span class="badge bg-success" title="Confirmed <?= esc((string) ($rec['bank_confirm_date'] ?? '')) ?>">
              <i class="bi bi-check-lg"></i> Confirmed</span>
          <?php else: ?><span class="small text-muted">&mdash;</span><?php endif; ?>
        </td>
        <td class="small">
          <?php if ($nAssets === 0): ?><span class="text-muted">&mdash;</span>
          <?php else: ?><span class="<?= $nAck === $nAssets ? 'text-success' : 'text-warning' ?>">
            <?= $nAck ?>/<?= $nAssets ?> acked</span><?php endif; ?>
        </td>
        <td class="small">
          <?php if ($missingDocs > 0 && $rec !== null): ?>
            <span class="text-warning"><?= count(hrm_document_keys()) - $missingDocs ?>/<?= count(hrm_document_keys()) ?></span>
          <?php elseif ($rec !== null): ?>
            <span class="text-success"><?= count(hrm_document_keys()) ?>/<?= count(hrm_document_keys()) ?></span>
          <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?>
        </td>
        <td>
          <div class="d-flex flex-column gap-1" style="min-width:230px">
            <div class="input-group input-group-sm">
              <input class="form-control" readonly id="lnk<?= (int) $e['id'] ?>"
                     value="<?= esc($rec !== null ? hrm_public_url((string) $rec['form_token']) : '') ?>">
              <button class="btn btn-outline-secondary" type="button"
                      onclick="var i=document.getElementById('lnk<?= (int) $e['id'] ?>');i.select();
                        navigator.clipboard.writeText(i.value).then(function(){wfToast('Link copied','ok');});"
                      <?= $rec === null ? 'disabled' : '' ?>>Copy</button>
            </div>
            <div class="d-flex gap-1">
              <a class="btn btn-sm btn-outline-primary flex-fill"
                 href="<?= esc(base_url('/hrm/' . (int) $e['id'])) ?>">Open form</a>
              <?php if ($rec !== null): ?>
                <form method="post" action="<?= esc(base_url('/hrm/' . (int) $e['id'] . '/rotate-link')) ?>" class="d-inline"
                      onsubmit="return confirm('Issue a new link? The current one stops working immediately.')">
                  <?= csrf_field() ?>
                  <button class="btn btn-sm btn-outline-warning" title="Invalidate the current link and issue a fresh one">
                    <i class="bi bi-arrow-repeat"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function page_hrm_review(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $emp = employee_find($id);
    if ($emp === null) { http_response_code(404); echo 'Employee not found.'; return; }
    if (!access_can_view($u, $emp)) { http_response_code(403); echo 'Not allowed.'; return; }

    $email = strtolower((string) $emp['email']);
    $rec = hrm_record($email);
    if ($rec === null) { http_response_code(404); echo 'Record not found.'; return; }
    $assets = hrm_assets($email);
    $docs = hrm_documents($email);
    $fields = hrm_fields();
    $groups = [
        '1. Personal Information' => ['full_name','father_name','cnic','dob','gender','marital_status','contact_number','personal_email','current_address','permanent_address'],
        '2. Father / Family Information' => ['father_cnic','father_contact','father_occupation','emergency_contact_name','emergency_contact_number','emergency_relationship'],
        '3. Education & Qualification' => ['highest_degree','degree_program','institute','graduation_year','certifications','skills'],
        '4. Professional Information' => ['employee_code','joining_date','designation','department','employment_type','reporting_manager','previous_company','previous_experience','portfolio_link','linkedin'],
    ];

    layout_open('HRM - ' . employee_full_name($emp), $u, '/hrm');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc(employee_full_name($emp)) ?></h4>
    <div class="small text-muted"><?= esc($email) ?> &middot; <?= esc($emp['department'] ?? '-') ?>
      &middot; <?= esc($emp['designation'] ?? '-') ?></div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?= hrm_status_badge((string) $rec['status']) ?>
    <span class="badge bg-light text-dark"><?= hrm_completeness($rec, $assets, $docs) ?>% complete</span>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hrm')) ?>">Back</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="small fw-semibold mb-1">Employee's private link</div>
  <div class="input-group input-group-sm mb-2">
    <input class="form-control" readonly value="<?= esc(hrm_public_url((string) $rec['form_token'])) ?>">
    <button class="btn btn-outline-secondary" type="button" onclick="this.previousElementSibling.select();
      navigator.clipboard.writeText(this.previousElementSibling.value).then(function(){wfToast('Link copied','ok');});">Copy</button>
  </div>
  <div class="small text-muted">Send this over WhatsApp or email. It is the only way the employee
    can open the form, and it grants access to their own salary and bank details.</div>
</div>

<?php foreach ($groups as $heading => $keys): ?>
<div class="card p-3 mb-3">
  <div class="sec-title mb-3"><?= esc($heading) ?></div>
  <div class="row g-3">
    <?php foreach ($keys as $k): if (isset($fields[$k])) hrm_readonly_value($k, $fields[$k], $rec[$k] ?? ''); endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="card p-3 mb-3">
  <div class="sec-title mb-3">5. Salary &amp; Bank Information</div>
  <div class="row g-3">
    <?php foreach (['salary','bank_name','account_title','account_number'] as $k)
        hrm_readonly_value($k, $fields[$k], $rec[$k] ?? ''); ?>
  </div>
  <div class="mt-3 p-2 rounded" style="background:var(--wf-surface-2)">
    <?php if ((int) ($rec['bank_confirmed'] ?? 0) === 1): ?>
      <div class="text-success"><i class="bi bi-check-circle me-1"></i>
        <span class="fw-semibold">Account Number Confirmation: YES</span>
        <span class="small text-muted">&mdash; confirmed by the employee on
          <?= esc((string) ($rec['bank_confirm_date'] ?? '')) ?></span></div>
      <div class="small text-muted mt-1">Employee confirmed the account is correct and in their own name.</div>
    <?php else: ?>
      <div class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>
        <span class="fw-semibold">Account Number Confirmation: NOT YET CONFIRMED</span></div>
      <div class="small text-muted mt-1">The employee has not ticked the bank confirmation.
        Do not process payroll from this record until they have.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-3">6. Company Assets</div>
  <?php if ($assets === []): ?>
    <div class="small text-muted">No assets listed.</div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-sm">
    <thead class="table-light"><tr><th>Asset</th><th>Type</th><th>ID / Serial</th><th>Issued</th>
      <th>Condition</th><th>Acknowledgement</th><th>Return</th><th>Remarks</th></tr></thead>
    <tbody><?php foreach ($assets as $a): ?>
      <tr>
        <td><?= esc((string) ($a['asset_name'] ?? '-')) ?></td>
        <td><?= esc((string) ($a['asset_type'] ?? '-')) ?></td>
        <td><?= esc((string) ($a['asset_serial'] ?? '-')) ?></td>
        <td><?= esc((string) ($a['issued_date'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($a['condition_issued'] ?? '-')) ?></td>
        <td>
          <?php if ((int) ($a['acknowledged'] ?? 0) === 1): ?>
            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Acknowledged</span>
            <div class="small text-muted"><?= esc((string) ($a['ack_date'] ?? '')) ?></div>
          <?php else: ?>
            <span class="badge bg-warning text-dark">Not acknowledged</span>
          <?php endif; ?>
        </td>
        <td class="small"><?= esc((string) ($a['return_date'] ?? '-')) ?>
          <?= trim((string) ($a['condition_return'] ?? '')) !== '' ? '<div class="text-muted">' . esc((string) $a['condition_return']) . '</div>' : '' ?></td>
        <td class="small"><?= esc((string) ($a['remarks'] ?? '-')) ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-3">7. Required Documents</div>
  <div class="row g-2">
    <?php foreach (hrm_document_keys() as $dk => $label):
      $have = null; foreach ($docs as $d) if ((string) $d['doc_key'] === $dk) $have = $d; ?>
      <div class="col-12 col-md-6">
        <div class="small fw-semibold"><?= esc($label) ?></div>
        <?php if ($have === null): ?>
          <span class="badge bg-secondary">Missing</span>
        <?php else: ?>
          <a class="small" target="_blank" rel="noopener"
             href="<?= esc(base_url('/hrm/' . (int) $emp['id'] . '/doc/' . $dk . '/' . rawurlencode((string) $have['file_name']))) ?>">
            <i class="bi bi-paperclip"></i> <?= esc((string) $have['orig_name']) ?></a>
          <span class="small text-muted"><?= esc(hrm_format_bytes((int) $have['size_bytes'])) ?>
            &middot; <?= esc((string) $have['uploaded_at']) ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-3">8. HR Record <span class="badge bg-primary-subtle text-primary">HR only</span></div>
  <form method="post" action="<?= esc(base_url('/hrm/' . (int) $emp['id'] . '/hr-record')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <?php foreach (hrm_hr_fields() as $k => $f): hrm_input($k, $f, $rec[$k] ?? ''); endforeach; ?>
    </div>
    <div class="small text-muted mt-2">Last updated
      <?= !empty($rec['hr_updated_at']) ? 'by ' . esc((string) $rec['hr_updated_by']) . ' on ' . esc((string) $rec['hr_updated_at']) : 'never' ?>.</div>
    <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-save me-1"></i>Save HR record</button>
  </form>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-3">9. Employee Confirmation</div>
  <?php if (!empty($rec['signature_png'])): ?>
    <img class="sig-preview border rounded bg-white p-2 mb-2" alt="Employee signature"
         src="data:image/png;base64,<?= esc((string) $rec['signature_png']) ?>">
    <div class="small text-muted">Signed by hand on a signature pad.</div>
  <?php elseif (!empty($rec['signature_name'])): ?>
    <div class="fw-semibold fs-5"><?= esc((string) $rec['signature_name']) ?></div>
    <div class="small text-muted">Signed by typed full name.</div>
  <?php else: ?>
    <div class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Not signed yet.</div>
  <?php endif; ?>
  <div class="small text-muted mt-2">
    Confirmed at <?= esc((string) ($rec['confirmed_at'] ?? '-')) ?>
    <?php if (!empty($rec['confirmed_ip'])): ?>from <?= esc((string) $rec['confirmed_ip']) ?><?php endif; ?>
    &middot; submitted <?= esc((string) ($rec['submitted_at'] ?? '-')) ?>
  </div>
</div>
    <?php
    layout_close();
}

function page_hrm_save_hr(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $emp = employee_find($id);
    if ($emp === null) { http_response_code(404); echo 'Not found.'; return; }
    if (!access_can_view($u, $emp)) { http_response_code(403); echo 'Not allowed.'; return; }
    require_csrf();

    $rec = hrm_record(strtolower((string) $emp['email']));
    if ($rec !== null) {
        hrm_save_hr($rec, $_POST, (string) $u['email']);
        activity_log($u, 'HRM_HR_RECORD_SAVED', 'Updated HR record for ' . $email);
        flash('ok', 'HR record saved.');
    }
    redirect(base_url('/hrm/' . $id));
}

function page_hrm_rotate_link(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $emp = employee_find($id);
    if ($emp === null) { http_response_code(404); echo 'Not found.'; return; }
    if (!access_can_view($u, $emp)) { http_response_code(403); echo 'Not allowed.'; return; }
    require_csrf();

    $email = strtolower((string) $emp['email']);
    hrm_record($email);
    hrm_rotate_token($email);
    activity_log($u, 'HRM_LINK_ROTATED', 'Reissued the public form link for ' . $email);
    flash('ok', 'A new link has been issued. The previous one no longer works.');
    redirect(base_url('/hrm'));
}

/** Streams a document to a signed-in viewer who is allowed to see that employee. */
function page_hrm_admin_doc(int $id, string $docKey, string $file): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $emp = employee_find($id);
    if ($emp === null) { http_response_code(404); exit; }
    if (!access_can_view($u, $emp)) { http_response_code(403); exit; }
    hrm_serve_document(strtolower((string) $emp['email']), $docKey, $file, $u);
}

/** Human-readable file size. */
function hrm_format_bytes(int $n): string {
    if ($n <= 0) return '-';
    if ($n < 1024) return $n . ' B';
    if ($n < 1048576) return round($n / 1024) . ' KB';
    return round($n / 1048576, 1) . ' MB';
}