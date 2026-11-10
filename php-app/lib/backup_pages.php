<?php
/**
 * WorkFolio PHP - admin screens: database backup and recovery.
 *
 * Mounted through backup_route(), which index.php delegates to for any
 * /admin/backups path. SUPER_ADMIN and ADMIN may create, list, download and
 * delete backups; writing back over the live database (restore) is reserved
 * for the SUPER_ADMIN, because a restore is the whole database in one click.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/backup.php';

/* ------------------------------------------------------------------- router ---- */

function backup_route(string $method, string $path): bool {
    if ($method === 'GET') {
        switch (true) {
            case $path === '/admin/backups': backup_page(); return true;
            case $path === '/admin/backups/download':
                backup_download((string) ($_GET['name'] ?? ''));
                return true;
        }
        return false;
    }
    if ($method === 'POST') {
        switch (true) {
            case $path === '/admin/backups/create':   backup_post_create(); return true;
            case $path === '/admin/backups/delete':   backup_post_delete(); return true;
            case $path === '/admin/backups/restore':  backup_post_restore(); return true;
        }
        return false;
    }
    return false;
}

/* --------------------------------------------------------------------- page ---- */

function backup_page(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    $isOwner = ($u['role'] ?? '') === 'SUPER_ADMIN';
    $items = backup_list();
    $error = (string) ($_SESSION['backup_error'] ?? '');
    unset($_SESSION['backup_error']);
    $safety = (string) ($_SESSION['backup_safety'] ?? '');
    unset($_SESSION['backup_safety']);

    layout_open('Database Backups', $u, '/admin/backups');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-database-down me-2 text-primary"></i>Database Backups</h4>
    <div class="small text-muted">Point-in-time copies of <?= backup_is_sqlite() ? 'the SQLite file' : 'MySQL (SQL script)' ?>, kept outside the web root.</div>
  </div>
  <form method="post" action="<?= esc(base_url('/admin/backups/create')) ?>">
    <?= csrf_field() ?>
    <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Create backup</button>
  </form>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= esc($error) ?></div>
<?php endif; ?>
<?php if ($safety !== ''): ?>
  <div class="alert alert-success py-2 small"><i class="bi bi-check2-circle me-1"></i>
    Restored. The database as it was before the restore was kept as <code><?= esc($safety) ?></code>.</div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="row g-3">
    <div class="col-6 col-md-3"><div class="text-muted small">Backups on disk</div><div class="fs-4 fw-bold"><?= count($items) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">Driver</div><div class="fs-4 fw-bold"><?= backup_is_sqlite() ? 'SQLite' : 'MySQL' ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">Newest</div><div class="fs-4 fw-bold"><?= count($items) ? esc(date('d M', (int) $items[0]['mtime'])) : '&mdash;' ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">Directory</div><div class="mt-1" style="font-size:.8rem"><code>data/backups</code></div></div>
  </div>
</div>

<div class="card p-3">
  <div class="sec-title mb-2">Available backups</div>
  <?php if ($items === []): ?>
    <div class="small text-muted">No backups yet. Create one before the next release or migration.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>File</th><th>Driver</th><th>Kind</th><th>Size</th><th>Taken</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($items as $b): ?>
      <tr>
        <td class="small"><code><?= esc((string) $b['name']) ?></code></td>
        <td class="small"><?= esc((string) $b['driver']) ?></td>
        <td class="small"><?= $b['note'] === 'pre-restore' ? '<span class="badge bg-secondary">pre-restore</span>' : '<span class="badge bg-success">manual</span>' ?></td>
        <td class="small"><?= esc(backup_bytes((int) $b['size'])) ?></td>
        <td class="small"><?= esc(date('d M Y H:i', (int) $b['mtime'])) ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/admin/backups/download?name=' . rawurlencode((string) $b['name']))) ?>" title="Download"><i class="bi bi-download"></i></a>
          <?php if ($isOwner): ?>
          <form method="post" action="<?= esc(base_url('/admin/backups/restore')) ?>" class="d-inline"
                onsubmit="return confirm('Replace the live database with <?= esc((string) $b['name']) ?>? A safety copy of the current database is taken first.')">
            <?= csrf_field() ?><input type="hidden" name="name" value="<?= esc((string) $b['name']) ?>">
            <button class="btn btn-sm btn-outline-warning" title="Restore (owner only)"><i class="bi bi-arrow-counterclockwise"></i></button>
          </form>
          <?php endif; ?>
          <form method="post" action="<?= esc(base_url('/admin/backups/delete')) ?>" class="d-inline"
                onsubmit="return confirm('Delete <?= esc((string) $b['name']) ?> permanently?')">
            <?= csrf_field() ?><input type="hidden" name="name" value="<?= esc((string) $b['name']) ?>">
            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="card p-3 mt-3">
  <div class="sec-title mb-1">Recovery</div>
  <div class="small text-muted">
    <strong>Create</strong> writes a snapshot of the live database.<br>
    <strong>Restore</strong> replaces the live database with the chosen file. It first writes a
    <em>pre-restore-</em> copy of the current state, so a mistaken restore can be undone by restoring
    that file again.<br>
    <strong>Download</strong> keeps an off-server copy - store it somewhere that is not this host.
  </div>
</div>
    <?php
    layout_close();
}

/* -------------------------------------------------------------------- posts ---- */

function backup_post_create(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    require_csrf();
    try {
        $b = backup_create('manual');
        activity_log($u, 'DB_BACKUP_CREATED', $b['name'] . ' (' . backup_bytes($b['size']) . ')');
        flash('success', 'Backup created: ' . $b['name']);
    } catch (Throwable $e) {
        flash('danger', 'Backup failed: ' . $e->getMessage());
    }
    redirect(base_url('/admin/backups'));
}

function backup_post_delete(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    require_csrf();
    $name = (string) ($_POST['name'] ?? '');
    if (backup_delete($name)) {
        activity_log($u, 'DB_BACKUP_DELETED', $name);
        flash('success', 'Backup deleted.');
    } else {
        flash('danger', 'That backup does not exist.');
    }
    redirect(base_url('/admin/backups'));
}

function backup_post_restore(): void {
    $u = require_role(['SUPER_ADMIN']);
    require_csrf();
    $name = (string) ($_POST['name'] ?? '');
    try {
        $res = backup_restore($name);
        activity_log($u, 'DB_BACKUP_RESTORED', $name . ' (safety copy ' . $res['safety'] . ')');
        $_SESSION['backup_safety'] = (string) $res['safety'];
        flash('success', 'Database restored from ' . $name);
    } catch (Throwable $e) {
        activity_log($u, 'DB_BACKUP_RESTORE_FAILED', $name . ': ' . $e->getMessage());
        $_SESSION['backup_error'] = $e->getMessage();
        flash('danger', 'Restore failed: ' . $e->getMessage());
    }
    redirect(base_url('/admin/backups'));
}

function backup_download(string $name): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    $path = backup_path($name);
    if ($path === null) { http_response_code(404); echo 'Backup not found.'; exit; }
    activity_log($u, 'DB_BACKUP_DOWNLOADED', $name);
    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}
