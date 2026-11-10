<?php
/**
 * WorkFolio PHP - HR module screens: Settings.
 *
 * Mounted through hr_settings_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/settings* path. The form is generated from
 * hr_settings_schema(), so adding a setting is a data-layer edit only.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';
require_once __DIR__ . '/hr_settings.php';

/* ------------------------------------------------------------------- router ---- */

function hr_settings_route(string $method, string $path): bool {
    if ($method === 'GET' && $path === '/settings') { hr_page_hr_settings(); return true; }
    if ($method === 'POST' && $path === '/settings/save') { hr_post_hr_settings_save(); return true; }
    return false;
}

/* -------------------------------------------------------------------- page ---- */

function hr_setting_input(string $key, array $def, string $current): string {
    $type = (string) ($def['type'] ?? 'text');
    $name = esc($key);
    switch ($type) {
        case 'checkbox':
            return '<div class="form-check"><input class="form-check-input" type="checkbox" name="' . $name . '" value="1" '
                . ($current === '1' ? 'checked' : '') . ' id="set_' . $name . '"><label class="form-check-label small" for="set_' . $name . '">Enabled</label></div>';
        case 'number':
            return '<input type="number" step="any" class="form-control form-control-sm" name="' . $name . '" value="' . esc($current) . '">';
        case 'time':
            return '<input type="time" class="form-control form-control-sm" name="' . $name . '" value="' . esc($current) . '">';
        case 'select':
            $out = '<select class="form-select form-select-sm" name="' . $name . '">';
            foreach (($def['options'] ?? []) as $val => $lbl) {
                $out .= '<option value="' . esc((string) $val) . '"' . ((string) $val === $current ? ' selected' : '') . '>' . esc((string) $lbl) . '</option>';
            }
            return $out . '</select>';
        case 'textarea':
            return '<textarea class="form-control form-control-sm" name="' . $name . '" rows="3">' . esc($current) . '</textarea>';
        default:
            return '<input type="text" class="form-control form-control-sm" name="' . $name . '" value="' . esc($current) . '">';
    }
}

function hr_page_hr_settings(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    $schema = hr_settings_schema();

    layout_open('HR Settings', $u, '/hr/settings');
    hr_tabs('/hr/settings');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-gear me-2 text-primary"></i>HR Settings</h4>
    <div class="small text-muted">Organisation-wide defaults used across attendance, leave, payroll and documents.</div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/hr')) ?>">Back to Dashboard</a>
</div>

<form method="post" action="<?= esc(base_url('/hr/settings/save')) ?>">
  <?= csrf_field() ?>
  <div class="row g-3">
    <?php foreach ($schema as $gk => $group): ?>
      <div class="col-12 col-lg-6">
        <div class="card p-3 h-100">
          <div class="sec-title mb-3"><i class="bi bi-sliders me-1 text-muted"></i><?= esc((string) $group['label']) ?></div>
          <div class="row g-3">
            <?php foreach ($group['fields'] as $key => $def):
                $current = hr_setting($key); ?>
              <div class="col-12 <?= in_array($def['type'] ?? 'text', ['text', 'number', 'time', 'select'], true) ? 'col-md-6' : '' ?>">
                <label class="form-label small mb-1"><?= esc((string) $def['label']) ?></label>
                <?= hr_setting_input($key, $def, $current) ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-3">
    <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save settings</button>
  </div>
</form>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------- posts ---- */

function hr_post_hr_settings_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $n = hr_settings_save($_POST, (string) $u['email']);
    activity_log($u, 'HR_SETTINGS_SAVED', $n . ' key(s)');
    flash('success', 'Settings saved (' . $n . ').');
    redirect(base_url('/hr/settings'));
}
