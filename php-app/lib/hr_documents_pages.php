<?php
/**
 * WorkFolio PHP - HR module screens: Employee Documents (Phase 1, section 8).
 *
 * Mounted through hr_documents_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/documents* path. Two audiences share the screens:
 * HR roles manage every document of the employees they can see, and an employee
 * gets a read-only view of their own permitted documents (the sensitive
 * categories stay hidden). Download goes through hr_document_serve(), which
 * re-checks the caller every time.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';       /* hr_tabs(), hr_visible_employees() */
require_once __DIR__ . '/hr_documents.php';

/* ------------------------------------------------------------------- router ---- */

function hr_documents_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/documents':            hr_page_documents(); return true;
            case $path === '/documents/categories': hr_page_document_categories(); return true;
            case preg_match('#^/documents/file/(\d+)$#', $path, $m) === 1:
                hr_get_document_file((int) $m[1]); return true;
            case preg_match('#^/documents/view/(\d+)$#', $path, $m) === 1:
                hr_page_document((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/documents/upload':            hr_post_document_upload(); return true;
            case $path === '/documents/category/save':     hr_post_document_category_save(); return true;
            case $path === '/documents/category/delete':   hr_post_document_category_delete(); return true;
            case preg_match('#^/documents/(\d+)/update$#', $path, $m) === 1:
                hr_post_document_update((int) $m[1]); return true;
            case preg_match('#^/documents/(\d+)/replace$#', $path, $m) === 1:
                hr_post_document_replace((int) $m[1]); return true;
            case preg_match('#^/documents/(\d+)/delete$#', $path, $m) === 1:
                hr_post_document_delete((int) $m[1]); return true;
        }
        return false;
    }
    return false;
}

/* ----------------------------------------------------------------- helpers ---- */

/** A small file input + category select, reused by the list and detail screens. */
function hr_document_upload_form(array $u, string $action, ?string $fixedEmail = null): void {
    $cats = hr_doc_categories(true);
    $employees = $fixedEmail !== null ? [] : hr_visible_employees($u);
    ?>
<form method="post" action="<?= esc(base_url($action)) ?>" enctype="multipart/form-data" class="row g-2">
  <?= csrf_field() ?>
  <?php if ($fixedEmail !== null): ?>
    <input type="hidden" name="email" value="<?= esc($fixedEmail) ?>">
  <?php else: ?>
    <div class="col-12 col-md-3"><label class="form-label small mb-1">Employee</label>
      <select class="form-select form-select-sm" name="email" required>
        <option value="">Select employee&hellip;</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?= esc((string) $e['email']) ?>"><?= esc(employee_full_name($e)) ?> (<?= esc((string) $e['email']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
  <?php endif; ?>
  <div class="col-12 col-md-3"><label class="form-label small mb-1">Category</label>
    <select class="form-select form-select-sm" name="category_id" required>
      <option value="">Select category&hellip;</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= esc((string) $c['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-12 col-md-3"><label class="form-label small mb-1">Title (optional)</label>
    <input class="form-control form-control-sm" name="title" placeholder="Defaults to the category name"></div>
  <div class="col-12 col-md-3"><label class="form-label small mb-1">File</label>
    <input type="file" class="form-control form-control-sm" name="document" required></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-1">Issued</label>
    <input type="date" class="form-control form-control-sm" name="issued_date"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-1">Expiry</label>
    <input type="date" class="form-control form-control-sm" name="expiry_date"></div>
  <div class="col-12 col-md-5"><label class="form-label small mb-1">Notes</label>
    <input class="form-control form-control-sm" name="notes" placeholder="Optional note for HR"></div>
  <div class="col-12 col-md-3 d-flex align-items-end">
    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-upload me-1"></i>Upload document</button></div>
</form>
    <?php
}

/** One row of the documents table, with the actions the viewer may take. */
function hr_document_row(array $u, array $d): void {
    $canManage = hr_document_can_manage($u, $d);
    $empLabel = employee_full_name($d);
    if (trim($empLabel) === '') $empLabel = (string) $d['email'];
    ?>
<tr>
  <td class="small">
    <div class="fw-semibold"><?= esc((string) $d['title']) ?></div>
    <div class="text-muted"><?= esc((string) ($d['orig_name'] ?? '')) ?></div>
  </td>
  <td class="small"><?= esc($empLabel) ?><?php if ((int) ($d['category_sensitive'] ?? 0) === 1): ?>
    <span class="badge bg-dark ms-1" title="Visible to HR only">Sensitive</span><?php endif; ?></td>
  <td class="small"><?= esc((string) ($d['category_name'] ?? '-')) ?></td>
  <td class="small"><?= hr_document_expiry_badge($d) ?></td>
  <td class="small text-muted"><?= esc(fmt_bytes((int) $d['size_bytes'])) ?></td>
  <td class="text-end text-nowrap">
    <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/documents/view/' . (int) $d['id'])) ?>">View</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/documents/file/' . (int) $d['id'] . '?download=1')) ?>"><i class="bi bi-download"></i></a>
    <?php if ($canManage): ?>
      <form method="post" action="<?= esc(base_url('/hr/documents/' . (int) $d['id'] . '/delete')) ?>" class="d-inline"
            onsubmit="return confirm('Delete &quot;<?= esc(addslashes((string) $d['title'])) ?>&quot;? The file is removed. This cannot be undone.')">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
      </form>
    <?php endif; ?>
  </td>
</tr>
    <?php
}

/* ------------------------------------------------------------------ landing ---- */

function hr_page_documents(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_doc_seed();
    hr_documents_expiry_sweep();

    $filters = [
        'q'           => trim((string) ($_GET['q'] ?? '')),
        'category_id' => (int) ($_GET['category_id'] ?? 0),
        'expiry'      => (string) ($_GET['expiry'] ?? ''),
        'email'       => (string) ($_GET['email'] ?? ''),
    ];
    $selEmail = access_resolve_selection($u, $filters['email']);
    if ($selEmail !== null) $filters['email'] = $selEmail; else unset($filters['email']);
    if ($filters['q'] === '') unset($filters['q']);
    if ($filters['category_id'] <= 0) unset($filters['category_id']);
    if (!in_array($filters['expiry'], ['expired', 'expiring'], true)) unset($filters['expiry']);

    $docs = hr_documents($filters);
    $cats = hr_doc_categories();
    $employees = hr_visible_employees($u);
    $expiring = count(hr_documents_expiring(HR_DOC_EXPIRY_WARN_DAYS, false));
    $expired  = count(hr_documents_expiring(HR_DOC_EXPIRY_WARN_DAYS, true)) - $expiring;

    layout_open('Documents', $u, '/hr/documents');
    hr_tabs('/hr/documents');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-folder2-open me-2 text-primary"></i>Employee Documents</h4>
    <div class="small text-muted">Secure document vault, with expiry tracking and access control.</div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/documents/categories')) ?>"><i class="bi bi-tags me-1"></i>Categories</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Documents</div><div class="fs-4 fw-bold"><?= count(hr_documents()) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Expiring (<?= HR_DOC_EXPIRY_WARN_DAYS ?>d)</div><div class="fs-4 fw-bold text-warning"><?= $expiring ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Expired</div><div class="fs-4 fw-bold text-danger"><?= max(0, $expired) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">Categories</div><div class="fs-4 fw-bold"><?= count($cats) ?></div></div></div>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Upload a Document</div>
  <?php hr_document_upload_form($u, '/hr/documents/upload'); ?>
</div>

<div class="card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Search</label>
      <input class="form-control form-control-sm" name="q" value="<?= esc((string) ($filters['q'] ?? '')) ?>" placeholder="Title or file name"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Employee</label>
      <select class="form-select form-select-sm" name="email">
        <option value="">All employees</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?= esc((string) $e['email']) ?>" <?= ($filters['email'] ?? '') === $e['email'] ? 'selected' : '' ?>><?= esc(employee_full_name($e)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Category</label>
      <select class="form-select form-select-sm" name="category_id">
        <option value="">All categories</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($filters['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Expiry</label>
      <select class="form-select form-select-sm" name="expiry">
        <option value="">Any</option>
        <option value="expiring" <?= ($filters['expiry'] ?? '') === 'expiring' ? 'selected' : '' ?>>Expiring soon</option>
        <option value="expired" <?= ($filters['expiry'] ?? '') === 'expired' ? 'selected' : '' ?>>Expired</option>
      </select></div>
    <div class="col-12 col-md-1 d-grid"><button class="btn btn-outline-primary btn-sm">Filter</button></div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light"><tr><th>Document</th><th>Employee</th><th>Category</th><th>Expiry</th><th>Size</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): hr_document_row($u, $d); endforeach; ?>
    <?php if ($docs === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No documents match.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------- detail ---- */

function hr_page_document(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $d = hr_document($id);
    if ($d === null) { http_response_code(404); echo 'Document not found.'; exit; }
    if (!hr_document_can_view($u, $d)) { http_response_code(403); echo 'Not allowed.'; exit; }
    $cats = hr_doc_categories(true);
    $history = hr_document_history($id);
    $emp = employee_find_by_email((string) $d['email']);
    $empLabel = $emp !== null ? employee_full_name($emp) : (string) $d['email'];

    layout_open('Document', $u, '/hr/documents');
    hr_tabs('/hr/documents');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $d['title']) ?></h4>
    <div class="small text-muted"><?= esc($empLabel) ?> &middot; <?= esc((string) ($d['category_name'] ?? '-')) ?>
      <?php if ((int) ($d['category_sensitive'] ?? 0) === 1): ?><span class="badge bg-dark ms-1">Sensitive</span><?php endif; ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/documents/file/' . $id)) ?>" target="_blank"><i class="bi bi-eye me-1"></i>View file</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/documents/file/' . $id . '?download=1')) ?>"><i class="bi bi-download me-1"></i>Download</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/documents')) ?>">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">Details</div>
      <form method="post" action="<?= esc(base_url('/hr/documents/' . $id . '/update')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12 col-md-4"><label class="form-label small mb-1">Employee</label>
          <input class="form-control form-control-sm" value="<?= esc($empLabel) ?>" disabled></div>
        <div class="col-12 col-md-4"><label class="form-label small mb-1">Category</label>
          <select class="form-select form-select-sm" name="category_id">
            <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $d['category_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12 col-md-4"><label class="form-label small mb-1">Title</label>
          <input class="form-control form-control-sm" name="title" value="<?= esc((string) $d['title']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Issued</label>
          <input type="date" class="form-control form-control-sm" name="issued_date" value="<?= esc((string) ($d['issued_date'] ?? '')) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Expiry</label>
          <input type="date" class="form-control form-control-sm" name="expiry_date" value="<?= esc((string) ($d['expiry_date'] ?? '')) ?>"></div>
        <div class="col-12"><label class="form-label small mb-1">Notes</label>
          <textarea class="form-control form-control-sm" name="notes" rows="2"><?= esc((string) ($d['notes'] ?? '')) ?></textarea></div>
        <div class="col-12"><button class="btn btn-primary btn-sm">Save details</button></div>
      </form>
    </div>

    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">Replace File</div>
      <form method="post" action="<?= esc(base_url('/hr/documents/' . $id . '/replace')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-12 col-md-8"><input type="file" class="form-control form-control-sm" name="document" required></div>
        <div class="col-12 col-md-4"><button class="btn btn-outline-warning btn-sm w-100" onclick="return confirm('Replace the current file? The old file is removed.')">Replace file</button></div>
      </form>
      <div class="small text-muted mt-2">Current file: <?= esc((string) ($d['orig_name'] ?? $d['file_name'])) ?> (<?= esc(fmt_bytes((int) $d['size_bytes'])) ?>)</div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card p-3">
      <div class="sec-title mb-2"><i class="bi bi-clock-history me-1 text-primary"></i>History</div>
      <?php if ($history === []): ?>
        <div class="small text-muted">No history recorded.</div>
      <?php else: ?>
        <ul class="list-unstyled mb-0">
        <?php foreach ($history as $h): ?>
          <li class="border-bottom py-1">
            <div class="small fw-semibold"><?= esc(str_replace('_', ' ', (string) $h['action'])) ?></div>
            <div class="small text-muted"><?= esc(dt_fmt((string) $h['created_at'])) ?> &middot; <?= esc((string) $h['actor_email']) ?></div>
            <?php if (trim((string) ($h['note'] ?? '')) !== ''): ?><div class="small"><?= esc((string) $h['note']) ?></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

/* --------------------------------------------------------------- categories ---- */

function hr_page_document_categories(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_doc_seed();
    $cats = hr_doc_categories();
    layout_open('Document Categories', $u, '/hr/documents');
    hr_tabs('/hr/documents');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-tags me-2 text-primary"></i>Document Categories</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/documents')) ?>">Back to Documents</a>
</div>

<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Add a Category</div>
  <form method="post" action="<?= esc(base_url('/hr/documents/category/save')) ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-12 col-md-3"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" required></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Code</label><input class="form-control form-control-sm" name="code" placeholder="e.g. visa"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Sort</label><input type="number" class="form-control form-control-sm" name="sort_order" value="100"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Flags</label>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="sensitive" value="1" id="nc-sens"><label class="form-check-label small" for="nc-sens">Sensitive (HR only)</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="expiry_tracked" value="1" id="nc-exp"><label class="form-check-label small" for="nc-exp">Track expiry</label></div>
    </div>
    <div class="col-6 col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" checked id="nc-act"><label class="form-check-label small" for="nc-act">Active</label></div></div>
    <div class="col-12 col-md-1 d-grid"><button class="btn btn-primary btn-sm">Add</button></div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>Name</th><th>Code</th><th>Documents</th><th>Flags</th><th>Sort</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cats as $c): $n = (int) val('SELECT COUNT(*) FROM hr_employee_documents WHERE category_id=?', [(int) $c['id']]); ?>
      <tr>
        <td class="fw-semibold"><?= esc((string) $c['name']) ?></td>
        <td class="small text-muted"><?= esc((string) $c['code']) ?></td>
        <td class="small"><?= $n ?></td>
        <td class="small">
          <?php if ((int) $c['sensitive'] === 1): ?><span class="badge bg-dark">Sensitive</span><?php endif; ?>
          <?php if ((int) $c['expiry_tracked'] === 1): ?><span class="badge bg-info text-dark">Expiry</span><?php endif; ?>
        </td>
        <td class="small"><?= (int) $c['sort_order'] ?></td>
        <td><?= (int) $c['active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Off</span>' ?></td>
        <td class="text-end">
          <?php if ($n === 0): ?>
          <form method="post" action="<?= esc(base_url('/hr/documents/category/delete')) ?>" class="d-inline"
                onsubmit="return confirm('Delete this category? Documents filed under it move to Other HR Document.')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
          <?php else: ?>
            <span class="small text-muted" title="In use - documents would be reassigned">in use</span>
          <?php endif; ?>
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

/* ---------------------------------------------------------- self-service card ---- */

/** The "My Documents" card for /hr/me. Only permitted (non-sensitive) files. */
function hr_documents_me_card(string $email): void {
    $u = cur_user();
    if ($u === null) return;
    $docs = [];
    foreach (hr_documents_for($email) as $d) {
        if (hr_document_can_view($u, $d)) $docs[] = $d;
    }
    ?>
<div class="card p-3 mt-3">
  <div class="sec-title mb-2"><i class="bi bi-folder2-open me-1 text-primary"></i>My Documents</div>
  <?php if ($docs === []): ?>
    <div class="small text-muted">No documents shared with you yet.</div>
  <?php else: ?>
    <ul class="list-unstyled mb-0">
    <?php foreach ($docs as $d): ?>
      <li class="d-flex justify-content-between align-items-center border-bottom py-1">
        <div>
          <div class="small fw-semibold"><?= esc((string) $d['title']) ?></div>
          <div class="small text-muted"><?= esc((string) ($d['category_name'] ?? '-')) ?>
            <?php if (trim((string) ($d['expiry_date'] ?? '')) !== ''): ?> &middot; expires <?= esc((string) $d['expiry_date']) ?><?php endif; ?></div>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/documents/file/' . (int) $d['id'] . '?download=1')) ?>"><i class="bi bi-download"></i></a>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
    <?php
}

/* ---------------------------------------------------------------- POST acts ---- */

function hr_post_document_upload(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = (string) ($_POST['email'] ?? '');
    if (!access_can_view_email($u, $email)) {
        flash('danger', 'That employee is outside your permitted scope.');
        redirect(base_url('/hr/documents'));
    }
    $res = hr_document_upload($email, $_POST, $_FILES['document'] ?? [], (string) $u['email']);
    if ($res['error'] !== null || $res['id'] <= 0) {
        flash('danger', $res['error'] ?? 'The document could not be uploaded.');
        redirect(base_url('/hr/documents'));
    }
    activity_log($u, 'HR_DOC_UPLOAD', 'Uploaded document #' . $res['id'] . ' for ' . strtolower($email), 'web');
    flash('success', 'Document uploaded.');
    redirect(base_url('/hr/documents/view/' . $res['id']));
}

function hr_post_document_update(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $d = hr_document($id);
    if ($d === null) { http_response_code(404); echo 'Document not found.'; exit; }
    if (!hr_document_can_manage($u, $d)) { http_response_code(403); echo 'Not allowed.'; exit; }
    $err = hr_document_update($id, $_POST, (string) $u['email']);
    if ($err !== '') flash('danger', $err); else { activity_log($u, 'HR_DOC_UPDATE', 'Updated document #' . $id, 'web'); flash('success', 'Document updated.'); }
    redirect(base_url('/hr/documents/view/' . $id));
}

function hr_post_document_replace(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $d = hr_document($id);
    if ($d === null) { http_response_code(404); echo 'Document not found.'; exit; }
    if (!hr_document_can_manage($u, $d)) { http_response_code(403); echo 'Not allowed.'; exit; }
    $err = hr_document_replace($id, $_FILES['document'] ?? [], (string) $u['email']);
    if ($err !== '') flash('danger', $err); else { activity_log($u, 'HR_DOC_REPLACE', 'Replaced file on document #' . $id, 'web'); flash('success', 'File replaced.'); }
    redirect(base_url('/hr/documents/view/' . $id));
}

function hr_post_document_delete(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $d = hr_document($id);
    if ($d === null) { http_response_code(404); echo 'Document not found.'; exit; }
    if (!hr_document_can_manage($u, $d)) { http_response_code(403); echo 'Not allowed.'; exit; }
    hr_document_delete($id, (string) $u['email']);
    activity_log($u, 'HR_DOC_DELETE', 'Deleted document #' . $id . ' for ' . (string) $d['email'], 'web');
    flash('success', 'Document deleted.');
    redirect(base_url('/hr/documents'));
}

function hr_post_document_category_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $new = hr_doc_category_save($id, (string) ($_POST['name'] ?? ''), (string) ($_POST['code'] ?? ''),
        !empty($_POST['sensitive']), !empty($_POST['expiry_tracked']),
        (int) ($_POST['sort_order'] ?? 100), !empty($_POST['active']));
    if ($new <= 0) flash('danger', 'A category needs a name and a code.');
    else { activity_log($u, 'HR_DOC_CATEGORY_SAVE', 'Saved document category #' . $new, 'web'); flash('success', 'Category saved.'); }
    redirect(base_url('/hr/documents/categories'));
}

function hr_post_document_category_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) { hr_doc_category_delete($id); activity_log($u, 'HR_DOC_CATEGORY_DELETE', 'Deleted document category #' . $id, 'web'); flash('success', 'Category deleted.'); }
    redirect(base_url('/hr/documents/categories'));
}

function hr_get_document_file(int $id): void {
    $u = require_login();
    hr_document_serve($id, $u, !empty($_GET['download']));
}
