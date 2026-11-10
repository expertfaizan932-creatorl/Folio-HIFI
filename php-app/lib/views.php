<?php
/**
 * WorkFolio PHP - shared page layout plus small HTML helpers.
 *
 * The shell is a real app console: fixed sidebar (collapsible on desktop,
 * drawer on phones), sticky top bar with live 12-hour clock and theme toggle.
 * Styling and behaviour live in /assets/wf.css and /assets/wf.js.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';

function pwa_head(): string {
    $b = app_base();
    return '<link rel="manifest" href="' . esc(base_url('/manifest.webmanifest')) . '">'
        . '<meta name="theme-color" content="#7237f5">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-title" content="Folio">'
        . '<link rel="icon" type="image/png" sizes="192x192" href="' . esc(base_url('/icons/icon-192.png')) . '?v=20261005a">'
        . '<link rel="apple-touch-icon" href="' . esc(base_url('/icons/icon-192.png')) . '?v=20261005a">'
        . '<script defer src="' . esc(base_url('/js/pwa.js')) . '"></script>';
}

/**
 * Sidebar links grouped the way a console reads: overview first, then the
 * tools a role is allowed to open. Each entry is [url, label, icon].
 */
function nav_items(array $u): array {
    $role = $u['role'] ?? '';

    $workspace = [
        ['/dashboard', 'Dashboard', 'bi-grid-1x2-fill'],
    ];
    $manage = [];
    if ($role === 'SUPER_ADMIN' || $role === 'ADMIN' || $role === 'HR') {
        $manage[] = ['/employees', 'Employees', 'bi-people-fill'];
        $manage[] = ['/monitor', 'Live Monitor', 'bi-broadcast-pin'];
        $manage[] = ['/portal/history', 'History / Reports', 'bi-calendar3'];
        $manage[] = ['/employee-form', 'Employee Form', 'bi-file-earmark-person-fill'];
        $manage[] = ['/admin/usage', 'Activity Report', 'bi-graph-up-arrow'];
        $manage[] = ['/admin/agent-config', 'Agent Settings', 'bi-sliders'];
    }
    $admin = [];
    if ($role === 'SUPER_ADMIN' || $role === 'ADMIN') {
        $admin[] = ['/portal/users', 'Credentials', 'bi-key-fill'];
        $admin[] = ['/admin/activity', 'Activity Tracker', 'bi-eye-fill'];
        $admin[] = ['/admin/backups', 'Database Backup', 'bi-database-down'];
    }
    $hr = [];
    if ($role === 'SUPER_ADMIN' || $role === 'ADMIN' || $role === 'HR') {
        $hr[] = ['/hr', 'HR Dashboard', 'bi-speedometer2'];
        $hr[] = ['/hr/org', 'Branches & Departments', 'bi-diagram-3'];
        $hr[] = ['/hr/employees', 'Employees', 'bi-person-vcard'];
        $hr[] = ['/hr/recruitment', 'Recruitment', 'bi-briefcase'];
        $hr[] = ['/hr/onboarding', 'Onboarding', 'bi-person-plus'];
        $hr[] = ['/hr/attendance', 'Attendance', 'bi-calendar-check'];
        $hr[] = ['/hr/leave', 'Leave', 'bi-calendar2-x'];
        $hr[] = ['/hr/documents', 'Documents', 'bi-folder2-open'];
        $hr[] = ['/hr/performance', 'Performance', 'bi-graph-up-arrow'];
        $hr[] = ['/hr/tasks', 'Tasks & Work', 'bi-list-task'];
        $hr[] = ['/hr/payroll', 'Payroll', 'bi-cash-stack'];
        $hr[] = ['/hr/assets', 'Assets', 'bi-box-seam'];
        $hr[] = ['/hr/offboarding', 'Offboarding', 'bi-person-dash'];
        $hr[] = ['/hr/reports', 'Reports & Analytics', 'bi-clipboard-data'];
        $hr[] = ['/hr/approvals', 'Approvals', 'bi-check2-square'];
        $hr[] = ['/hr/announcements', 'Announcements', 'bi-megaphone'];
        $hr[] = ['/hr/notifications', 'Notifications', 'bi-bell'];
        $hr[] = ['/hr/settings', 'HR Settings', 'bi-gear'];
    }
    $personal = [
        ['/time', 'My Time', 'bi-clock-fill'],
        ['/hr/me', 'My HR', 'bi-person-vcard'],
        ['/profile', 'My Profile', 'bi-person-badge-fill'],
    ];

    $groups = [];
    if ($workspace) $groups[] = ['Workspace', $workspace];
    if ($manage) $groups[] = ['Manage', $manage];
    if ($hr) $groups[] = ['HR', $hr];
    if ($admin) $groups[] = ['Administration', $admin];
    if ($personal) $groups[] = ['Personal', $personal];
    return $groups;
}

function initials_of(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $out .= strtoupper(substr($p, 0, 1));
        if (strlen($out) >= 2) break;
    }
    return $out === '' ? '?' : $out;
}

function layout_open(string $title, array $u, string $active = ''): void {
    $name = employee_full_name($u);
    $role = $u['role'] ?? '';
    $logout = base_url('/logout');
    $groups = nav_items($u);
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= esc($title) ?> - Folio</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= esc(base_url('/assets/wf.css')) ?>?v=20261005a" rel="stylesheet">
<script>
/* pick the theme before first paint so the page never flashes the wrong colours */
(function () { try {
  var t = localStorage.getItem('wf-theme');
  if (!t) t = (window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
} catch (e) {} })();
</script>
<?= pwa_head() ?>
</head>
<body>
<button id="pwa-install" type="button" hidden class="btn btn-primary position-fixed bottom-0 end-0 m-3 shadow" style="z-index:1080"><i class="bi bi-download"></i> Install app</button>

<div class="wf-shell">

  <aside class="wf-side" id="wfSide">
    <div class="wf-brand">
      <img src="<?= esc(base_url('/icons/websitelogo.png')) ?>?v=20261005a" alt="Folio">
      <div class="wf-brand-txt">
        <div class="wf-brand-name">Folio</div>
        <div class="wf-brand-sub">Monitoring Suite</div>
      </div>
      <button type="button" class="wf-icon-btn wf-side-close" data-side-close aria-label="Close navigation"><i class="bi bi-x-lg"></i></button>
    </div>

    <nav class="wf-nav">
      <?php foreach ($groups as $g): ?>
      <div class="wf-nav-label"><?= esc($g[0]) ?></div>
      <?php foreach ($g[1] as $it): ?>
      <a class="wf-link <?= $active === $it[0] ? 'active' : '' ?>" href="<?= esc(base_url($it[0])) ?>" title="<?= esc($it[1]) ?>">
        <span class="wf-ico"><i class="bi <?= esc($it[2]) ?>"></i></span><span class="lbl"><?= esc($it[1]) ?></span>
      </a>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <div class="wf-side-foot">
      <div class="wf-user">
        <div class="wf-avatar"><?= esc(initials_of($name)) ?></div>
        <div class="wf-user-txt" style="min-width:0">
          <div class="wf-user-name"><?= esc($name) ?></div>
          <div class="wf-user-role"><?= esc($role) ?></div>
        </div>
      </div>
      <a class="btn btn-outline-secondary w-100" href="<?= esc($logout) ?>"><i class="bi bi-box-arrow-right"></i><span>Sign out</span></a>
    </div>
  </aside>

  <div class="wf-backdrop" data-side-close></div>

  <div class="wf-body">
    <header class="wf-top">
      <button type="button" class="wf-icon-btn wf-burger" data-side-toggle aria-label="Toggle navigation"><i class="bi bi-list"></i></button>
      <button type="button" class="wf-icon-btn wf-rail" data-side-toggle aria-label="Collapse menu" title="Collapse / expand menu"><i class="bi bi-layout-sidebar-inset"></i></button>
      <div class="wf-top-title">
        <h1><?= esc($title) ?></h1>
        <div class="wf-crumb">Folio</div>
      </div>
      <div class="wf-top-right">
        <div class="wf-chip" title="Office time <?= esc(wf_tz()) ?> (12-hour)">
          <i class="bi bi-clock-history"></i>
          <?php /* The browser clock cannot be trusted here: an employee abroad, or
                  anyone whose machine is set to another zone, used to see a time
                  that disagreed with every timestamp in the app. The page hands
                  over the server's own clock and wf.js ticks from that instead. */ ?>
          <span id="wf-clock-time" data-wf-now="<?= esc((string) time()) ?>" data-wf-tz="<?= esc(wf_tz()) ?>">--:--:--</span>
          <span class="text-soft d-none d-xl-inline" id="wf-clock-date"></span>
        </div>
        <button type="button" class="wf-icon-btn" data-theme-toggle title="Switch theme"><i class="bi bi-sun"></i></button>
        <div class="wf-chip wf-chip-user">
          <i class="bi bi-person-circle"></i>
          <span class="lbl-user"><?= esc($name) ?></span>
          <span class="wf-role"><?= esc($role) ?></span>
        </div>
        <a class="wf-icon-btn wf-signout" href="<?= esc($logout) ?>" title="Sign out"><i class="bi bi-box-arrow-right"></i></a>
      </div>
    </header>

    <main class="wf-main wf-in">
<?= flash_render() ?>
<?php
}

function layout_close(): void {
    ?>
    </main>
  </div>
</div>
<div class="wf-toasts"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= esc(base_url('/assets/wf.js')) ?>?v=20261005a"></script>
</body>
</html>
<?php
    exit; /* layout always terminates the page */
}

/* ----------------------------------------------------------- tiny helpers ---- */

function percent(int $part, int $total): int {
    if ($total <= 0) return 0;
    return (int) round($part * 100.0 / $total);
}

function fmt_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return sprintf('%.1f KB', $bytes / 1024);
    if ($bytes < 1073741824) return sprintf('%.1f MB', $bytes / 1048576);
    return sprintf('%.2f GB', $bytes / 1073741824);
}

function flash_now(): ?string {
    $m = $_GET['msg'] ?? null;
    return $m === null ? null : (string) $m;
}

/**
 * One-shot messages that survive a redirect.
 *
 * Every save/delete used to bounce back to the list silently, so a rejected
 * employee (duplicate email, a role you may not grant) looked exactly like a
 * successful one - and the person then could not sign in with the credentials
 * that were never stored. Anything that redirects now says why, or confirms.
 */
function flash(string $type, string $msg): void {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) $_SESSION['flash'] = [];
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_take(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

function flash_render(): string {
    $icons = ['success' => 'check-circle', 'warning' => 'exclamation-triangle', 'danger' => 'x-circle'];
    $out = '';
    foreach (flash_take() as $f) {
        $type = (string) ($f['type'] ?? 'danger');
        if ($type !== 'success' && $type !== 'warning') $type = 'danger';
        $out .= '<div class="alert alert-' . $type . ' py-2" data-flash>'
            . '<i class="bi bi-' . $icons[$type] . ' me-1"></i>'
            . esc((string) ($f['msg'] ?? '')) . '</div>';
    }
    return $out;
}

/** Rebuilds the report's own query string so a POST lands back on the same view. */
function page_query_back(array $src, string $fallback = '/admin/usage'): string {
    $keep = [];
    foreach (['scope', 'from', 'to', 'email'] as $k) {
        $v = trim((string) ($src[$k] ?? ''));
        if ($v !== '') $keep[$k] = $v;
    }
    $qs = $keep === [] ? '' : '?' . http_build_query($keep);
    return base_url($fallback) . $qs;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function json_or_redirect(array $params, array $data): void {
    /* used by pages that answer both fetch() callers and form posts */
    if (!empty($params['json'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}

/**
 * Lazy retention sweep. Shared hosting has no cron; the app runs the purge
 * itself at most once a day on a normal page request, guarded by a marker file.
 */
function lazy_retention(): void {
    global $WF;
    $marker = rtrim($WF['storage'], '/\\') . '/.last_purge';
    $run = true;
    if (is_file($marker)) {
        $run = (filemtime($marker) < time() - 24 * 3600);
    }
    if (!$run) return;
    shots_purge_older_than((int) $WF['retention']);
    @touch($marker);
}
