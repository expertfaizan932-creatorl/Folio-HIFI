<?php
/**
 * WorkFolio PHP - monitor API port (/api/monitor/*).
 *
 * These back the live grid and the drill-down. Every endpoint re-checks the
 * viewer's visibility: /shot/{id} and /usage?email= are direct references, so
 * an HR must not be able to walk the id space or ask for the admin's screen.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tracks.php';
require_once __DIR__ . '/usage.php';
/* shots.php owns the live-frame store and api_agent.php owns the tracking rules
 * both endpoints here consult. Both are loaded by index.php first today, but the
 * grid must not depend on that order: a missing function here is a blank screen. */
require_once __DIR__ . '/shots.php';
require_once __DIR__ . '/api_agent.php';

function api_error_json(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}

function api_monitor_live(): void {
    $viewer = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $date = today();
    // Read everyone's policy once up front. A channel with no frame is either
    // "the agent is not sending them" or "the person is not clocked in", and the
    // grid has to be able to tell those two apart - otherwise every blank tile
    // looks identical and the page reads as broken rather than as unconfigured.
    $people = access_visible_employees($viewer);
    $streamingEnabled = [];
    foreach ($people as $p) {
        $cfg = config_for($p['email']);
        $streamingEnabled[$p['email']] = agent_tracking_enabled($p)
            && live_interval_ms($cfg) > 0;
    }
    $out = [];
    foreach ($people as $e) {
        $r = track_record_for($e['email'], $date);
        $onBreak = ($r['status'] ?? STATUS_OFF) === STATUS_BREAK;

        $shotLive = false;
        // On break the monitor must show nothing from this machine, so the shot
        // is not even looked up - a stale pre-break capture would otherwise sit
        // on screen looking like break-time activity.
        $shot = $onBreak ? null : shot_latest_live($e['email']);
        if ($shot !== null) {
            $shotLive = true;
        } elseif (!$onBreak) {
            $shot = shot_latest($e['email']);
        }
        // The video stream is the freshest "what are they doing right now" source.
        $frame = $onBreak ? null : live_frame_row($e['email']);
        $frameAge = $onBreak ? null : live_frame_age($frame);
        $streaming = $frame !== null && $frameAge !== null && $frameAge <= 8;
        $app = $onBreak ? null : ($r['current_app'] ?? null);
        $title = $onBreak ? null : ($r['current_title'] ?? null);
        $host = $onBreak ? null : ($r['current_host'] ?? null);
        if ($streaming) {
            if (!empty($frame['app_name'])) $app = $frame['app_name'];
            if (!empty($frame['window_title'])) $title = $frame['window_title'];
            if (!empty($frame['host'])) $host = $frame['host'];
        }
        // Usage totals and input counters are shift totals, not live data, so they
        // stay visible; only the live "right now" view is withheld on a break.
        $out[] = [
            'email'           => $e['email'],
            'name'            => employee_full_name($e),
            'role'            => $e['role'],
            'designation'     => $e['designation'],
            'department'      => $e['department'],
            'onDuty'          => track_on_duty($r),
            'idle'            => strcasecmp((string) ($r['last_status'] ?? ''), 'IDLE') === 0,
            'status'          => $r['status'],
            'checkIn'         => track_check_in_text($r),
            'checkOut'        => track_check_out_text($r),
            'worked'          => fmt_dur(track_worked_seconds($r)),
            'workedSec'       => track_worked_seconds($r),
            'active'          => fmt_dur((int) ($r['active_seconds'] ?? 0)),
            'idleTime'        => fmt_dur((int) ($r['idle_seconds'] ?? 0)),
            'breakTime'       => fmt_dur((int) ($r['break_seconds'] ?? 0)),
            'productivity'    => track_productivity_percent($r),
            'screenshots'     => (int) ($r['screenshot_count'] ?? 0),
            'lastShot'        => track_last_shot_text($r),
            'keystrokes'      => (int) ($r['keystrokes'] ?? 0),
            'mouseClicks'     => (int) ($r['mouse_clicks'] ?? 0),
            'currentApp'      => $app,
            'currentTitle'    => $title,
            'currentHost'     => $host,
            'currentCategory' => $onBreak ? null : ($r['current_category'] ?? null),
            'onBreak'         => $onBreak,
            'paused'          => fmt_dur((int) ($r['paused_seconds'] ?? 0)),
            'pausedSec'       => (int) ($r['paused_seconds'] ?? 0),
            'appCount'        => usage_app_count($e['email'], $date),
            'siteCount'       => usage_site_count($e['email'], $date),
            'hostName'        => $r['host_name'] ?? null,
            'agentVersion'    => $r['agent_version'] ?? null,
            'lastSeenAgo'     => seconds_ago_text($r['last_seen'] ?? null),
            'shotId'          => $shot === null ? null : (int) $shot['id'],
            'shotAt'          => $shot === null ? null : $shot['captured_at'],
            'shotAtFmt'       => $shot === null ? null : hhmm_a($shot['captured_at'] ?? null),
            'shotAgeSec'      => $shot === null ? null : secs_since($shot['captured_at'] ?? null),
            'shotLive'        => $shotLive,
            'streaming'       => $streaming,
            'streamingSet'    => $streamingEnabled[$e['email']] ?? false,
            'frameSeq'        => $frame === null ? null : (int) $frame['seq'],
            'frameAt'         => $frame === null ? null : hhmm_a($frame['captured_at'] ?? null),
            'frameAgeSec'     => $frameAge,
            'frameKb'         => $frame === null ? null : round((int) $frame['size_bytes'] / 1024, 1),
            'frameIdle'       => $frame !== null && strcasecmp((string) ($frame['status'] ?? ''), 'IDLE') === 0,
        ];
    }
    usort($out, fn($a, $b) => (int) ($b['onDuty'] ?? false) <=> (int) ($a['onDuty'] ?? false));
    api_json_response($out);
}

function api_monitor_shot(int $id): void {
    $viewer = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $meta = shot_entity($id);
    if ($meta === null) {
        http_response_code(404);
        exit;
    }
    if (!access_can_view_email($viewer, $meta['email'] ?? null)) {
        http_response_code(403);
        exit;
    }
    $data = shots_read_bytes($meta);
    if ($data === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: max-age=10');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}

/**
 * Serves the newest live frame for one employee - the video the monitor plays.
 * ?seq=N turns it into a conditional request: 304 when nothing new has landed,
 * so a viewer polling faster than the agent streams costs almost nothing.
 */
function api_monitor_live_frame(): void {
    $viewer = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $email = trim((string) ($_GET['email'] ?? ''));
    if ($email === '' || !access_can_view_email($viewer, $email)) {
        api_error_json(403, 'Forbidden');
    }
    // Defence in depth: even if a viewer hits this URL directly, an employee on
    // break gets no frame bytes. The monitor already skips the lookup.
    $rec = track_record_for($email, today());
    if (($rec['status'] ?? STATUS_OFF) === STATUS_BREAK) {
        api_error_json(404, 'on break');
    }
    $want = (int) ($_GET['seq'] ?? 0);
    $row = live_frame_row($email);

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    if ($row === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'no stream']);
        exit;
    }
    $seq = (int) $row['seq'];
    header('X-Frame-Seq: ' . $seq);
    header('X-Frame-Age: ' . (int) live_frame_age($row));
    if ($seq > 0 && $seq <= $want) {
        http_response_code(304);
        exit;
    }
    $path = live_frame_path($email);
    $bytes = is_file($path) ? @file_get_contents($path) : false;
    if ($bytes === false || $bytes === '') {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'frame missing']);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

function api_monitor_usage(): void {
    $viewer = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $email = (string) ($_GET['email'] ?? '');
    if ($email === '' || !access_can_view_email($viewer, $email)) {
        api_error_json(403, 'Forbidden');
    }
    $day = !empty($_GET['date']) ? (string) $_GET['date'] : today();
    $apps = [];
    foreach (array_slice(usage_for($email, $day, USAGE_TYPE_APP), 0, 12) as $u) $apps[] = usage_api_row($u);
    $sites = [];
    foreach (array_slice(usage_for($email, $day, USAGE_TYPE_WEB), 0, 12) as $u) $sites[] = usage_api_row($u);
    $r = track_record_for($email, $day);
    $res = [
        'email'          => $email,
        'date'           => $day,
        'apps'           => $apps,
        'sites'          => $sites,
        'appSeconds'     => usage_app_seconds($email, $day),
        'siteSeconds'    => usage_site_seconds($email, $day),
        'appCount'       => usage_app_count($email, $day),
        'siteCount'      => usage_site_count($email, $day),
        'workedSec'      => track_worked_seconds($r),
        'activeSec'      => (int) ($r['active_seconds'] ?? 0),
        'idleSec'        => (int) ($r['idle_seconds'] ?? 0),
        'pausedSec'      => (int) ($r['paused_seconds'] ?? 0),
        'breakSec'       => (int) ($r['break_seconds'] ?? 0),
        // Per-break notes, so HR sees each break's own length rather than only
        // a day total. Carries times and durations only, never any activity.
        'breaks'         => break_records_for($email, $day),
        'screenshotCount'=> (int) ($r['screenshot_count'] ?? 0),
    ];
    api_json_response($res);
}

function seconds_ago_text(?string $dt): string {
    $d = secs_since($dt);
    if ($d === null) return '-';
    if ($d < 60) return $d . 's ago';
    if ($d < 3600) return intdiv($d, 60) . 'm ago';
    return intdiv($d, 3600) . 'h ago';
}