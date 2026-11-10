<?php
/**
 * WorkFolio PHP - HR module screens: Assets.
 *
 * Mounted through hr_assets_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/assets* path. HR/Admin run the register; every
 * employee can see what they currently hold at /hr/assets/me.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';
require_once __DIR__ . '/hr_assets.php';

/* ------------------------------------------------------------------- router ---- */

function hr_assets_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/assets':                    hr_page_assets_overview(); return true;
            case $path === '/assets/new':                hr_page_assets_edit(0); return true;
            case $path === '/assets/categories':         hr_page_assets_categories(); return true;
            case $path === '/assets/me':                 hr_page_assets_me(); return true;
            case preg_match('#^/assets/(\d+)$#', $path, $m) === 1:      hr_page_assets_view((int) $m[1]); return true;
            case preg_match('#^/assets/(\d+)/edit$#', $path, $m) === 1: hr_page_assets_edit((int) $m[1]); return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/assets/save':               hr_post_assets_save(); return true;
            case $path === '/assets/delete':             hr_post_assets_delete(); return true;
            case $path === '/assets/assign':             hr_post_assets_assign(); return true;
            case $path === '/assets/return':             hr_post_assets_return(); return true;
            case $path === '/assets/status':             hr_post_assets_status(); return true;
            case $path === '/assets/categories/save':    hr_post_assets_category_save(); return true;
            case $path === '/assets/categories/delete':  hr_post_assets_category_delete(); return true;
        }
        return false;
    }
    return false;
}

/* ---------------------------------------------------------------- helpers ---- */

function ha_money_fmt(string $v): string {
    return number_format((float) $v, 2);
}

/** Self-service card for /hr/me: the assets one currently holds. */
function hr_assets_me_card(string $email): void {
    $held = hr_assets_for_email($email);
    if ($held === []) return;
    ?>
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2"><i class="bi bi-box-seam me-1 text-primary"></i>My Assets (<?= count($held) ?>)</div>
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Tag</th><th>Item</th><th>Since</th><th>Condition</th></tr></thead>
        <tbody>
        <?php foreach ($held as $a): ?>
          <tr>
            <td class="small text-muted"><?= esc((string) $a['asset_tag']) ?></td>
            <td class="small"><?= esc((string) $a['name']) ?><?php if (trim((string) $a['brand']) !== ''): ?><div class="text-muted" style="font-size:.72rem"><?= esc((string) $a['brand'] . ' ' . (string) $a['model']) ?></div><?php endif; ?></td>
            <td class="small"><?= esc(dt_fmt((string) $a['assigned_at'])) ?></td>
            <td class="small"><?= esc(hr_asset_condition_label((string) $a['condition_out'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <a class="btn btn-sm btn-outline-primary mt-2" href="<?= esc(base_url('/hr/assets/me')) ?>">Open my assets</a>
    </div>
    <?php
}

/* ---------------------------------------------------------------- overview ---- */

function hr_page_assets_overview(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $stats = hr_assets_stats();
    $recent = hr_assets(['limit' => 10]);

    layout_open('Assets', $u, '/hr/assets');
    hr_tabs('/hr/assets');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>Assets</h4>
    <div class="small text-muted">Company equipment register and who holds what.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-primary" href="<?= esc(base_url('/hr/assets/new')) ?>"><i class="bi bi-plus-lg me-1"></i>Add asset</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/assets/categories')) ?>">Categories</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Total Assets</div><div class="fs-4 fw-bold"><?= (int) $stats['total'] ?></div></div></div>
  <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Available</div><div class="fs-4 fw-bold text-success"><?= (int) $stats['available'] ?></div></div></div>
  <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Assigned</div><div class="fs-4 fw-bold text-primary"><?= (int) $stats['assigned'] ?></div></div></div>
  <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Returned</div><div class="fs-4 fw-bold text-info"><?= (int) $stats['returned'] ?></div></div></div>
  <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Maintenance</div><div class="fs-4 fw-bold text-warning"><?= (int) $stats['maintenance'] ?></div></div></div>
  <div class="col-6 col-md-2"><div class="card p-3"><div class="text-muted small">Book Value</div><div class="fs-5 fw-bold"><?= esc(ha_money_fmt((string) $stats['value'])) ?></div></div></div>
</div>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="sec-title mb-0">Recent Assets</div>
    <a class="small" href="<?= esc(base_url('/hr/assets/me')) ?>">My assets</a>
  </div>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Tag</th><th>Name</th><th>Category</th><th>Holder</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $a): ?>
      <tr>
        <td class="small text-muted"><?= esc((string) $a['asset_tag']) ?></td>
        <td class="small fw-semibold"><?= esc((string) $a['name']) ?></td>
        <td class="small"><?= esc((string) ($a['category_name'] ?? '-')) ?></td>
        <td class="small"><?= esc((string) ($a['holder'] ?? '-')) ?></td>
        <td><?= hr_asset_status_badge((string) $a['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/hr/assets/' . (int) $a['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($recent === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No assets yet. <a href="<?= esc(base_url('/hr/assets/new')) ?>">Add one</a>.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_assets_categories(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $cats = hr_asset_categories();
    $counts = [];
    foreach (rows('SELECT category_id, COUNT(*) AS n FROM hr_assets GROUP BY category_id') as $r) {
        $counts[(int) $r['category_id']] = (int) $r['n'];
    }
    layout_open('Asset Categories', $u, '/hr/assets');
    hr_tabs('/hr/assets');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-tags me-2 text-primary"></i>Asset Categories</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/assets')) ?>">Back to Assets</a>
</div>
<div class="row g-3">
  <div class="col-12 col-md-7">
    <div class="card p-3">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Name</th><th>Code</th><th>Assets</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td class="small"><?= esc((string) $c['name']) ?></td>
            <td class="small text-muted"><?= esc((string) $c['code']) ?></td>
            <td class="small"><?= (int) ($counts[(int) $c['id']] ?? 0) ?></td>
            <td class="text-end">
              <form method="post" action="<?= esc(base_url('/hr/assets/categories/delete')) ?>" class="d-inline" onsubmit="return confirm('Delete this category? Assets keep running, just uncategorised.')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($cats === []): ?><tr><td colspan="4" class="text-center text-muted py-3">No categories yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-12 col-md-5">
    <div class="card p-3">
      <div class="sec-title mb-2">Add category</div>
      <form method="post" action="<?= esc(base_url('/hr/assets/categories/save')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" required></div>
        <div class="col-12"><label class="form-label small mb-1">Code (optional)</label><input class="form-control form-control-sm" name="code" placeholder="e.g. LAPTOP"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
      </form>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

function hr_page_assets_edit(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $a = $id ? hr_asset($id) : null;
    if ($id && $a === null) { http_response_code(404); echo 'Asset not found.'; exit; }
    $cats = hr_asset_categories();

    layout_open($id ? 'Edit Asset' : 'Add Asset', $u, '/hr/assets');
    hr_tabs('/hr/assets');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-box-seam me-2 text-primary"></i><?= $id ? 'Edit' : 'Add' ?> Asset</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/assets')) ?>">Back</a>
</div>
<div class="card p-3">
  <form method="post" action="<?= esc(base_url('/hr/assets/save')) ?>">
    <?= csrf_field() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="name" required value="<?= esc((string) ($a['name'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Asset tag</label><input class="form-control form-control-sm" name="asset_tag" value="<?= esc((string) ($a['asset_tag'] ?? '')) ?>" placeholder="auto if blank"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Category</label>
        <select class="form-select form-select-sm" name="category_id">
          <option value="">- none -</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($a['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
        <select class="form-select form-select-sm" name="status">
          <?php foreach (hr_asset_statuses() as $k => $lbl): if ($k === 'ASSIGNED') continue; ?>
            <option value="<?= esc($k) ?>" <?= strtoupper((string) ($a['status'] ?? 'AVAILABLE')) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Serial no</label><input class="form-control form-control-sm" name="serial_no" value="<?= esc((string) ($a['serial_no'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Brand</label><input class="form-control form-control-sm" name="brand" value="<?= esc((string) ($a['brand'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Model</label><input class="form-control form-control-sm" name="model" value="<?= esc((string) ($a['model'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Condition</label>
        <select class="form-select form-select-sm" name="asset_condition">
          <?php foreach (hr_asset_conditions() as $k => $lbl): ?><option value="<?= esc($k) ?>" <?= strtoupper((string) ($a['asset_condition'] ?? 'GOOD')) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Purchase date</label><input type="date" class="form-control form-control-sm" name="purchase_date" value="<?= esc((string) ($a['purchase_date'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Purchase cost</label><input class="form-control form-control-sm" name="purchase_cost" value="<?= esc((string) ($a['purchase_cost'] ?? '')) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">Warranty until</label><input type="date" class="form-control form-control-sm" name="warranty_until" value="<?= esc((string) ($a['warranty_until'] ?? '')) ?>"></div>
      <div class="col-12"><label class="form-label small mb-1">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= esc((string) ($a['notes'] ?? '')) ?></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save asset</button></div>
    </div>
  </form>
</div>
    <?php
    layout_close();
}

function hr_page_assets_view(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $a = hr_asset($id);
    if ($a === null) { http_response_code(404); echo 'Asset not found.'; exit; }
    $cats = hr_asset_category_map();
    $current = hr_asset_current_assignment($id);
    $history = hr_asset_assignments($id);

    layout_open('Asset - ' . (string) $a['asset_tag'], $u, '/hr/assets');
    hr_tabs('/hr/assets');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= esc((string) $a['name']) ?></h4>
    <div class="small text-muted"><?= esc((string) $a['asset_tag']) ?>
      <?php if (!empty($a['category_id'])): ?> &middot; <?= esc((string) ($cats[(int) $a['category_id']] ?? '-')) ?><?php endif; ?>
      &middot; <?= esc(hr_asset_condition_label((string) $a['asset_condition'])) ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= hr_asset_status_badge((string) $a['status']) ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/assets/' . $id . '/edit')) ?>"><i class="bi bi-pencil"></i></a>
    <form method="post" action="<?= esc(base_url('/hr/assets/delete')) ?>" onsubmit="return confirm('Delete this asset and its assignment history?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/assets')) ?>">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card p-3 mb-3">
      <div class="sec-title mb-2">Current Holder</div>
      <?php if ($current === null): ?>
        <div class="small text-muted mb-2">Not assigned.</div>
        <?php if (!in_array(strtoupper((string) $a['status']), ['RETIRED', 'LOST'], true)): ?>
          <form method="post" action="<?= esc(base_url('/hr/assets/assign')) ?>" class="row g-2">
            <?= csrf_field() ?><input type="hidden" name="asset_id" value="<?= $id ?>">
            <div class="col-12"><input type="email" class="form-control form-control-sm" name="email" placeholder="Employee email" required></div>
            <div class="col-6">
              <select class="form-select form-select-sm" name="condition_out">
                <?php foreach (hr_asset_conditions() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-6"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-person-plus me-1"></i>Assign</button></div>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <div class="mb-2"><span class="fw-semibold"><?= esc((string) $current['email']) ?></span>
          <div class="small text-muted">since <?= esc(dt_fmt((string) $current['assigned_at'])) ?> &middot; out as <?= esc(hr_asset_condition_label((string) $current['condition_out'])) ?></div></div>
        <form method="post" action="<?= esc(base_url('/hr/assets/return')) ?>" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="asset_id" value="<?= $id ?>">
          <div class="col-6">
            <select class="form-select form-select-sm" name="condition_in">
              <?php foreach (hr_asset_conditions() as $k => $lbl): ?><option value="<?= esc($k) ?>"><?= esc($lbl) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-box-arrow-in-down me-1"></i>Return</button></div>
        </form>
      <?php endif; ?>
      <?php if ($current === null && !in_array(strtoupper((string) $a['status']), ['ASSIGNED'], true)): ?>
        <hr class="my-3">
        <form method="post" action="<?= esc(base_url('/hr/assets/status')) ?>" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="asset_id" value="<?= $id ?>">
          <div class="col-8"><select class="form-select form-select-sm" name="status">
            <?php foreach (hr_asset_statuses() as $k => $lbl): if ($k === 'ASSIGNED') continue; ?><option value="<?= esc($k) ?>" <?= strtoupper((string) $a['status']) === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
          </select></div>
          <div class="col-4"><button class="btn btn-outline-secondary btn-sm w-100">Set</button></div>
        </form>
      <?php endif; ?>
    </div>
    <div class="card p-3">
      <div class="sec-title mb-2">Details</div>
      <table class="table table-sm mb-0">
        <tbody>
          <tr><th class="text-muted small">Serial</th><td class="small"><?= esc((string) ($a['serial_no'] ?: '-')) ?></td></tr>
          <tr><th class="text-muted small">Brand / Model</th><td class="small"><?= esc(trim((string) $a['brand'] . ' ' . (string) $a['model']) ?: '-') ?></td></tr>
          <tr><th class="text-muted small">Purchased</th><td class="small"><?= esc((string) ($a['purchase_date'] ?: '-')) ?></td></tr>
          <tr><th class="text-muted small">Cost</th><td class="small"><?= esc(ha_money_fmt((string) $a['purchase_cost'])) ?></td></tr>
          <tr><th class="text-muted small">Warranty</th><td class="small"><?= esc((string) ($a['warranty_until'] ?: '-')) ?></td></tr>
          <?php if (trim((string) $a['notes']) !== ''): ?><tr><th class="text-muted small">Notes</th><td class="small"><?= esc((string) $a['notes']) ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card p-3">
      <div class="sec-title mb-2">Assignment History</div>
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Holder</th><th>Out</th><th>In</th><th>Condition</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td class="small"><?= esc((string) $h['email']) ?></td>
            <td class="small"><?= esc(dt_fmt((string) $h['assigned_at'])) ?></td>
            <td class="small"><?= $h['returned_at'] ? esc(dt_fmt((string) $h['returned_at'])) : '<span class="badge bg-primary">Active</span>' ?></td>
            <td class="small"><?= esc(hr_asset_condition_label((string) ($h['condition_in'] ?: $h['condition_out']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($history === []): ?><tr><td colspan="4" class="text-center text-muted py-3">No assignments yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------- posts ---- */

function hr_post_assets_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_int_or_null($_POST['id'] ?? null);
    $newId = hr_asset_save($id, $_POST, (string) $u['email']);
    if ($newId === 0) { flash('danger', 'A name is required and the asset tag must be unique.'); redirect(base_url('/hr/assets/' . ($id ? $id . '/edit' : 'new'))); }
    activity_log($u, $id ? 'HR_ASSET_UPDATED' : 'HR_ASSET_CREATED', 'Asset #' . $newId);
    flash('success', $id ? 'Asset updated.' : 'Asset added.');
    redirect(base_url('/hr/assets/' . $newId));
}

function hr_post_assets_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    hr_asset_delete($id);
    activity_log($u, 'HR_ASSET_DELETED', 'Asset #' . $id);
    flash('success', 'Asset deleted.');
    redirect(base_url('/hr/assets'));
}

function hr_post_assets_assign(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['asset_id'] ?? 0);
    if (!hr_asset_assign($id, (string) ($_POST['email'] ?? ''), (string) $u['email'],
        (string) ($_POST['condition_out'] ?? 'GOOD'), (string) ($_POST['notes'] ?? ''))) {
        flash('danger', 'Could not assign (bad email or already assigned).');
    } else {
        activity_log($u, 'HR_ASSET_ASSIGNED', 'Asset #' . $id . ' -> ' . (string) ($_POST['email'] ?? ''));
        flash('success', 'Asset assigned.');
    }
    redirect(base_url('/hr/assets/' . $id));
}

function hr_post_assets_return(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['asset_id'] ?? 0);
    if (!hr_asset_return($id, (string) $u['email'], (string) ($_POST['condition_in'] ?? 'GOOD'), (string) ($_POST['notes'] ?? ''))) {
        flash('danger', 'That asset is not currently assigned.');
    } else {
        flash('success', 'Asset returned.');
    }
    redirect(base_url('/hr/assets/' . $id));
}

function hr_post_assets_status(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['asset_id'] ?? 0);
    if (!hr_asset_set_status($id, (string) ($_POST['status'] ?? ''), (string) $u['email'])) {
        flash('danger', 'Unknown status, or assign it through an assignment.');
    } else {
        flash('success', 'Asset status updated.');
    }
    redirect(base_url('/hr/assets/' . $id));
}

function hr_post_assets_category_save(): void {
    require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = hr_asset_category_save($_POST);
    flash($id ? 'success' : 'danger', $id ? 'Category saved.' : 'Category name required.');
    redirect(base_url('/hr/assets/categories'));
}

function hr_post_assets_category_delete(): void {
    require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    hr_asset_category_delete((int) ($_POST['id'] ?? 0));
    flash('success', 'Category deleted.');
    redirect(base_url('/hr/assets/categories'));
}

/* ------------------------------------------------------------- self-service ---- */

function hr_page_assets_me(): void {
    $u = require_login();
    $email = strtolower((string) $u['email']);
    $held = hr_assets_for_email($email);

    layout_open('My Assets', $u, '/hr/me');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>My Assets</h4>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr/me')) ?>">Back to My HR</a>
</div>
<div class="card p-3 mb-3">
  <div class="sec-title mb-2">Currently Held (<?= count($held) ?>)</div>
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Tag</th><th>Item</th><th>Brand / Model</th><th>Since</th><th>Condition</th></tr></thead>
    <tbody>
    <?php foreach ($held as $a): ?>
      <tr>
        <td class="small text-muted"><?= esc((string) $a['asset_tag']) ?></td>
        <td class="small fw-semibold"><?= esc((string) $a['name']) ?></td>
        <td class="small"><?= esc(trim((string) $a['brand'] . ' ' . (string) $a['model']) ?: '-') ?></td>
        <td class="small"><?= esc(dt_fmt((string) $a['assigned_at'])) ?></td>
        <td class="small"><?= esc(hr_asset_condition_label((string) $a['condition_out'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($held === []): ?><tr><td colspan="5" class="text-center text-muted py-3">You hold no company assets.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
    <?php
    layout_close();
}
