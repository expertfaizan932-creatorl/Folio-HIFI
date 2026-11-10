<?php
/**
 * WorkFolio PHP server - front controller (router).
 *
 * Every URL goes through index.php (via .htaccess). Static files that really
 * exist (css, js, icons, screenshots are NOT in here) are served by the web
 * server before this script is reached; everything else is routed below.
 */
require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/tracks.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/shots.php';
require_once __DIR__ . '/lib/activity.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/views.php';
require_once __DIR__ . '/lib/landing.php';
require_once __DIR__ . '/lib/api_agent.php';
require_once __DIR__ . '/lib/api_monitor.php';
require_once __DIR__ . '/lib/talk.php';
require_once __DIR__ . '/lib/hrm.php';
require_once __DIR__ . '/lib/hrm_pages.php';
require_once __DIR__ . '/lib/hr.php';
require_once __DIR__ . '/lib/hr_pages.php';
require_once __DIR__ . '/lib/hr_recruitment.php';
require_once __DIR__ . '/lib/hr_recruitment_pages.php';
require_once __DIR__ . '/lib/hr_onboarding.php';
require_once __DIR__ . '/lib/hr_onboarding_pages.php';
require_once __DIR__ . '/lib/hr_documents.php';
require_once __DIR__ . '/lib/hr_documents_pages.php';
require_once __DIR__ . '/lib/hr_performance.php';
require_once __DIR__ . '/lib/hr_performance_pages.php';
require_once __DIR__ . '/lib/hr_tasks.php';
require_once __DIR__ . '/lib/hr_tasks_pages.php';
require_once __DIR__ . '/lib/hr_offboarding.php';
require_once __DIR__ . '/lib/hr_offboarding_pages.php';
require_once __DIR__ . '/lib/hr_assets.php';
require_once __DIR__ . '/lib/hr_assets_pages.php';
require_once __DIR__ . '/lib/hr_payroll.php';
require_once __DIR__ . '/lib/hr_payroll_pages.php';
require_once __DIR__ . '/lib/hr_reports.php';
require_once __DIR__ . '/lib/hr_reports_pages.php';
require_once __DIR__ . '/lib/hr_settings.php';
require_once __DIR__ . '/lib/hr_settings_pages.php';
require_once __DIR__ . '/lib/hr_approvals.php';
require_once __DIR__ . '/lib/hr_approvals_pages.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/backup_pages.php';
require_once __DIR__ . '/lib/employee_form.php';
require_once __DIR__ . '/lib/employee_form_pages.php';
require_once __DIR__ . '/lib/remote_command.php';
require_once __DIR__ . '/lib/remote_shell.php';
require_once __DIR__ . '/lib/remote_files.php';
require_once __DIR__ . '/lib/agent_update.php';
require_once __DIR__ . '/lib/pages.php';

/*
 * Every library is loaded before the schema is created: the seed step writes
 * the default agent policies through config_for() (defined in usage.php).
 */
ensure_schema();

/* shared hosting has no cron: run the retention sweep when it is due */
lazy_retention();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($uri) && $uri !== false) {
    if ($uri !== '/' && substr($uri, -1) === '/') $uri = rtrim($uri, '/');
}
if (!is_string($uri) || $uri === '' || $uri === false) $uri = '/';

$base = app_base();
if ($base !== '' && strpos($uri, $base) === 0) $uri = substr($uri, strlen($base));
if ($uri === '') $uri = '/';

/* --------------------------------------------------------- HRM public link -- */

/**
 * The employee's side of the HRM form, resolved before the page switch.
 *
 * These URLs carry no session: the 64-hex token in the path is the credential,
 * and CSRF still protects the POST. Handling them here rather than as cases in
 * the switch is deliberate. An expired or mistyped link has to produce a plain
 * 404, and routing it through a long switch(true) chain makes that answer depend
 * on unrelated cases sitting above it - which is exactly how a dead token once
 * ended up bouncing an employee to the login page. One regex, one exit, no
 * ordering to get wrong.
 */
function hrm_public_route(string $method, string $uri): bool {
    $re = '#^/h/([a-f0-9]{64})(?:/done|/doc/([a-z0-9_]+)/([A-Za-z0-9._-]+))?$#';
    if (preg_match($re, $uri, $m) !== 1) return false;

    $token = $m[1];
    if (($m[2] ?? '') === 'done') {
        page_hrm_public_done($token);
        return true;
    }
    if (($m[2] ?? '') === 'doc') {
        if ($method !== 'GET') { http_response_code(405); exit; }
        page_hrm_public_doc($token, $m[3], $m[4]);
        return true;
    }
    page_hrm_public($token);        /* GET renders, POST saves */
    return true;
}

if (hrm_public_route($method, $uri)) exit;

/* ------------------------------------------------------------- HR module ---- */

/* The HR module owns every /hr URL (never /hrm - that prefix stays with the HRM
   form above). One hook, one exit, so the switch below is untouched apart from
   the two requires at the top. */
if (hr_route($method, $uri)) exit;

/* ---------------------------------------------------------- DB backup ------- */

/* SUPER_ADMIN only, like the rest of /admin/backups; one hook, one exit. */
if (backup_route($method, $uri)) exit;

/* ------------------------------------------------------------- pages -------- */

switch (true) {
    /* The public front door. Anonymously it is the landing page with the
       sign-in form on it; signed in, the root keeps its old meaning and goes
       straight to the dashboard, so an employee's bookmark still works. */
    case $method === 'GET' && ($uri === '/' || $uri === '/landing'):
        if (cur_user() !== null) redirect(base_url('/dashboard'));
        page_landing();

    case $uri === '/login':
        page_login();                       /* GET form + POST auth */

    case $uri === '/logout':
        page_logout();

    case $method === 'GET' && $uri === '/dashboard':
        page_dashboard();

    case $method === 'GET' && $uri === '/profile':
        page_profile();

    case $method === 'GET' && $uri === '/time':
        page_time();

    case $method === 'GET' && $uri === '/monitor':
        page_monitor();

    case $method === 'GET' && $uri === '/portal/history':
        page_history();

    case $method === 'POST' && $uri === '/portal/history/adjust':
        page_history_adjust();

    case $method === 'GET' && $uri === '/admin/usage':
        page_usage();

    case $method === 'GET' && $uri === '/admin/usage.csv':
        page_usage_csv();

    case $method === 'GET' && $uri === '/admin/usage-targets.csv':
        page_usage_targets_csv();

    case $method === 'POST' && $uri === '/admin/usage/delete-shot':
        page_usage_delete_shot();

    case $method === 'POST' && $uri === '/admin/usage/delete-shots':
        page_usage_delete_shots();

    case $method === 'GET' && $uri === '/admin/agent-config':
        page_agent_config();

    case $method === 'POST' && $uri === '/admin/agent-config/save':
        page_agent_config_save();

    case $method === 'POST' && $uri === '/admin/agent-config/purge':
        page_agent_config_purge();

    case $method === 'GET' && $uri === '/portal/users':
        page_users();

    case $method === 'POST' && $uri === '/portal/users/save':
        page_users_save();

    case $method === 'POST' && $uri === '/portal/users/reset':
        page_users_reset();

    case $method === 'GET' && $uri === '/employees':
        page_employees();

    case $method === 'GET' && $uri === '/employees/new':
        page_employee_form_new();

    case $method === 'POST' && $uri === '/employees/save':
        page_employee_save();

    case $method === 'POST' && $uri === '/employees/update':
        page_employee_update();

    case $method === 'GET' && preg_match('#^/admin/employees/edit/(\d+)$#', $uri, $m) === 1:
        page_employee_form_edit((int) $m[1]);

    case $method === 'POST' && preg_match('#^/admin/employees/password/(\d+)$#', $uri, $m) === 1:
        page_employee_password((int) $m[1]);

    case $method === 'POST' && preg_match('#^/admin/employees/delete/(\d+)$#', $uri, $m) === 1:
        page_employee_delete((int) $m[1]);

    case $method === 'GET' && $uri === '/admin/login-as':
        page_login_as();

    case $method === 'GET' && $uri === '/admin/reports':
        page_reports();

    case $method === 'GET' && $uri === '/admin/activity':
        page_activity();

    /* -------------------------------------------------- remote control ---- */

    /* Owner-only fleet console: the desktop EXE (WorkFolioManager) picks any
       installed PC and runs a cmd line or opens an interactive terminal. The
       browser page was removed; only the JSON endpoints remain. */

    case $method === 'POST' && $uri === '/api/agent/remote/poll':
        api_json_response(api_agent_remote_poll());

    case $method === 'POST' && $uri === '/api/agent/remote/report':
        api_json_response(api_agent_remote_report());

    /* Interactive terminal. The agent holds one cmd.exe open for the duration of
       a session; these four calls carry keystrokes in and console output back. */
    case $method === 'POST' && $uri === '/api/agent/shell/poll':
        api_json_response(api_agent_shell_poll());

    case $method === 'POST' && $uri === '/api/agent/shell/out':
        api_json_response(api_agent_shell_out());

    /* What build the agent should be running; drives the self-update. */
    case $method === 'GET' && $uri === '/api/agent/update':
        api_json_response(api_agent_update());

    case $method === 'POST' && $uri === '/api/manager/shell/open':
        api_json_response(api_manager_shell_open());

    case $method === 'POST' && $uri === '/api/manager/shell/read':
        api_json_response(api_manager_shell_read());

    case $method === 'POST' && $uri === '/api/manager/shell/write':
        api_json_response(api_manager_shell_write());

    case $method === 'POST' && $uri === '/api/manager/shell/close':
        api_json_response(api_manager_shell_close());

    /* Remote file manager. Same shape as the terminal, but the ops move files
       and folders instead of keystrokes. */
    case $method === 'POST' && $uri === '/api/agent/fs/poll':
        api_json_response(api_agent_fs_poll());

    case $method === 'POST' && $uri === '/api/agent/fs/out':
        api_json_response(api_agent_fs_out());

    case $method === 'POST' && $uri === '/api/manager/fs/open':
        api_json_response(api_manager_fs_open());

    case $method === 'POST' && $uri === '/api/manager/fs/write':
        api_json_response(api_manager_fs_write());

    case $method === 'POST' && $uri === '/api/manager/fs/read':
        api_json_response(api_manager_fs_read());

    case $method === 'POST' && $uri === '/api/manager/fs/close':
        api_json_response(api_manager_fs_close());

    case $method === 'POST' && $uri === '/api/manager/login':
        api_json_response(api_manager_login());

    case $method === 'POST' && $uri === '/api/manager/systems':
        api_json_response(api_manager_systems());

    case $method === 'POST' && $uri === '/api/manager/command':
        api_json_response(api_manager_command());

    case $method === 'POST' && $uri === '/api/manager/history':
        api_json_response(api_manager_history());

    /* ---------------------------------------------------- employee form ---- */

    /* Hiring intake. The form and the list of everything submitted through it are
       the same screen, so there is nothing to navigate to after submitting. */
    case $method === 'GET' && $uri === '/employee-form':
        page_employee_form();

    case $method === 'POST' && $uri === '/employee-form/save':
        page_employee_form_save();

    case $method === 'GET' && preg_match('#^/employee-form/cv/(\d+)$#', $uri, $m) === 1:
        ef_serve_cv((int) $m[1], require_role(['SUPER_ADMIN', 'ADMIN', 'HR']));

    case $method === 'POST' && preg_match('#^/employee-form/(\d+)/status$#', $uri, $m) === 1:
        page_employee_form_status((int) $m[1]);

    case $method === 'GET' && preg_match('#^/employee-form/(\d+)$#', $uri, $m) === 1:
        page_employee_form_view((int) $m[1]);

    /* ------------------------------------------------------------ HRM form ---- */

    /* HR's side. The employee's public token links are handled before the switch
       (see the hrm_public_route() hook above) so that no case here can shadow
       them: an unrecognised token has to answer 404, never fall through to a
       page that then asks for a login. */
    case $method === 'GET' && $uri === '/hrm':
        page_hrm_overview();

    case $method === 'POST' && preg_match('#^/hrm/(\d+)/hr-record$#', $uri, $m) === 1:
        page_hrm_save_hr((int) $m[1]);

    case $method === 'POST' && preg_match('#^/hrm/(\d+)/rotate-link$#', $uri, $m) === 1:
        page_hrm_rotate_link((int) $m[1]);

    case $method === 'GET' && preg_match('#^/hrm/(\d+)/doc/([a-z0-9_]+)/([A-Za-z0-9._-]+)$#', $uri, $m) === 1:
        page_hrm_admin_doc((int) $m[1], $m[2], $m[3]);

    case $method === 'GET' && preg_match('#^/hrm/(\d+)$#', $uri, $m) === 1:
        page_hrm_review((int) $m[1]);

    /* --------------------------------------------------------- agent api ---- */

    case $method === 'POST' && $uri === '/api/agent/login':
        api_json_response(api_agent_login());

    case $method === 'POST' && $uri === '/api/agent/logout':
        api_json_response(api_agent_logout());

    case $method === 'GET' && $uri === '/api/agent/config':
        api_json_response(api_agent_config());

    case $method === 'POST' && $uri === '/api/agent/check-in':
        api_json_response(api_agent_check_in());

    case $method === 'POST' && $uri === '/api/agent/check-out':
        api_json_response(api_agent_check_out());

    case $method === 'POST' && $uri === '/api/agent/break/start':
        api_json_response(api_agent_break_start());

    case $method === 'POST' && $uri === '/api/agent/break/end':
        api_json_response(api_agent_break_end());

    case $method === 'POST' && $uri === '/api/agent/heartbeat':
        api_json_response(api_agent_heartbeat());

    case $method === 'POST' && $uri === '/api/agent/telemetry':
        api_json_response(api_agent_telemetry());

    case $method === 'POST' && $uri === '/api/agent/usage':
        api_json_response(api_agent_usage());

    case $method === 'POST' && $uri === '/api/agent/screenshot':
        api_json_response(api_agent_screenshot());

    case $method === 'GET' && $uri === '/api/agent/today':
        api_json_response(api_agent_today());

    case $method === 'GET' && $uri === '/api/agent/day':
        api_json_response(api_agent_day());

    /* ------------------------------------------------------- monitor api ---- */

    case $method === 'GET' && $uri === '/api/monitor/live':
        api_monitor_live();

    case $method === 'GET' && $uri === '/api/monitor/live-frame':
        api_monitor_live_frame();

    case $method === 'POST' && $uri === '/api/agent/live-frame':
        api_json_response(api_agent_live_frame());

    /* ------------------------------------------------------ supervisor talk ---- */

    case $method === 'POST' && $uri === '/api/monitor/talk/start':
        api_monitor_talk_start();

    case $method === 'POST' && $uri === '/api/monitor/talk/chunk':
        api_monitor_talk_chunk();

    case $method === 'POST' && $uri === '/api/monitor/talk/stop':
        api_monitor_talk_stop();

    case $method === 'GET' && $uri === '/api/monitor/talk/state':
        api_monitor_talk_state();

    case $method === 'POST' && $uri === '/api/agent/talk/poll':
        api_agent_talk_poll();

    case $method === 'POST' && $uri === '/api/agent/talk/ack':
        api_agent_talk_ack();

    /* Browser listener: the receiver for employees who have not installed anything.
       Authenticated by the employee's own web session, never by an email parameter. */
    case $method === 'POST' && $uri === '/api/talk/listener/ready':
        api_talk_listener_ready();

    case $method === 'GET' && $uri === '/api/talk/listener/poll':
        api_talk_listener_poll();

    case $method === 'POST' && $uri === '/api/talk/listener/ack':
        api_talk_listener_ack();

    case $method === 'GET' && preg_match('#^/api/monitor/shot/(\d+)$#', $uri, $m) === 1:
        api_monitor_shot((int) $m[1]);

    case $method === 'GET' && $uri === '/api/monitor/usage':
        api_monitor_usage();

    /* ---------------------------------------------------- static fallback ---- */

    case preg_match('#^/(manifest\.webmanifest|sw\.js)$#', $uri, $m) === 1:
        $file = __DIR__ . '/' . $m[1];
        if (is_file($file)) {
            header('Content-Type: ' . ($m[1] === 'sw.js' ? 'text/javascript' : 'application/manifest+json'));
            header('Cache-Control: no-cache');
            readfile($file);
            exit;
        }
        http_response_code(404);
        exit;

    default:
        // Local / PHP built-in server: serve real static files (icons, css, js)
        // natively. On Apache those never reach us (mod_rewrite !-f / !-d).
        // Never hand out anything under /data/ (db.sqlite, screenshots).
        if (!empty($_SERVER['DOCUMENT_ROOT'])
            && strpos($uri, '..') === false
            && $uri !== '/data'
            && strpos($uri, '/data/') !== 0) {
            $real = realpath(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/' . ltrim($uri, '/'));
            if ($real !== false && is_file($real)) return false;
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found.';
        exit;
}