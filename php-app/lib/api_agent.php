<?php
/**
 * WorkFolio PHP - the 13 endpoints the Windows agent (WorkFolioAgent.exe) calls.
 *
 * The agent is not a browser session: it logs in once, holds an opaque token,
 * and carries that token in every later call. Tokens live in a database table
 * (not process memory) because shared hosting runs many PHP processes - an
 * in-memory token would break between requests. They expire after 30 days.
 *
 * Every response matches the Spring JSON contract, so the existing EXE works
 * unchanged.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tracks.php';
require_once __DIR__ . '/usage.php';

function api_json_response(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    // A list payload must stay a JSON array. The live-monitor grid iterates the
    // response (rows.forEach / rows.length); adding a string key below would
    // silently turn that array into an object and leave the whole monitor blank.
    // The server clock therefore rides only on associative payloads - which is
    // every agent call, so drift detection is unaffected.
    $keys = array_keys($data);
    $isList = $keys === [] || $keys === range(0, count($data) - 1);
    // Authoritative server time (UTC, ISO-8601). The agent compares this with its
    // own clock to detect drift; elapsed durations stay on a monotonic clock, so a
    // wrong local clock can never change how much time was recorded.
    if (!$isList && !array_key_exists('serverTime', $data)) {
        $data['serverTime'] = gmdate('c');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function agent_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function device_ensure(string $email, string $type, string $key, array $fields = []): array {
    $email = strtolower(trim($email));
    $type = strtoupper(trim($type === '' ? 'PC' : $type));
    $key = trim($key === '' ? 'PC' : $key);
    $row = one('SELECT * FROM devices WHERE email=? AND device_key=? LIMIT 1', [$email, $key]);
    if ($row !== null) {
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if ($v === null || $v === '') continue;
            $sets[] = $k . '=?';
            $params[] = $v;
        }
        if (!empty($sets)) {
            $params[] = (int) $row['device_id'];
            q('UPDATE devices SET ' . implode(',', $sets) . ' WHERE device_id=?', $params);
            $row = one('SELECT * FROM devices WHERE device_id=?', [(int) $row['device_id']]);
        }
        return $row ?: [];
    }
    $now = now_sql();
    $cols = ['email', 'device_type', 'device_key', 'created_at'];
    $vals = ['?', '?', '?', '?'];
    $params = [$email, $type, $key, $now];
    foreach ($fields as $k => $v) {
        if ($v === null || $v === '') continue;
        $cols[] = $k;
        $vals[] = '?';
        $params[] = $v;
    }
    q('INSERT INTO devices (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')', $params);
    $id = last_id();
    $row = one('SELECT * FROM devices WHERE device_id=?', [$id]);
    return $row ?: [];
}

function device_touch(?int $deviceId): void {
    if ($deviceId === null || $deviceId < 1) return;
    try {
        q('UPDATE devices SET last_seen=? WHERE device_id=?', [now_sql(), $deviceId]);
    } catch (Throwable $e) {
    }
}

function token_context(?string $token): ?array {
    if ($token === null || $token === '') return ['emp' => null, 'device' => null, 'tokenRow' => null];
    $row = one('SELECT * FROM agent_tokens WHERE token=?', [$token]);
    if ($row === null) return ['emp' => null, 'device' => null, 'tokenRow' => $row];
    $created = ts_of($row['created_at']);
    if ($created !== null && $created < time() - 30 * 86400) {
        q('DELETE FROM agent_tokens WHERE token=?', [$token]);
        return ['emp' => null, 'device' => null, 'tokenRow' => null];
    }
    $emp = employee_find_by_email($row['email']);
    $device = null;
    $did = isset($row['device_id']) ? (int) $row['device_id'] : 0;
    if ($did > 0) {
        $device = one('SELECT * FROM devices WHERE device_id=?', [$did]);
    }
    return ['emp' => $emp, 'device' => $device, 'tokenRow' => $row];
}

function api_employee_from_token(?string $token): ?array {
    $ctx = token_context($token);
    return $ctx['emp'];
}

function api_body_employee(array $body): ?array {
    $token = $body['token'] ?? null;
    if (!is_string($token)) return null;
    return api_employee_from_token($token);
}

/* --------------------------------------------------------------- handlers ---- */

function api_agent_login(): array {
    $body = agent_json_body();
    $email = isset($body['email']) && is_string($body['email']) ? strtolower(trim($body['email'])) : '';
    $candidates = $email === '' ? [] : employee_find_by_email_all($email);
    $password = (string) ($body['password'] ?? '');
    $emp = null;
    foreach ($candidates as $cand) {
        if (password_verify($password, (string) $cand['password'])) { $emp = $cand; break; }
    }
    if ($emp === null) {
        if (count($candidates) > 1) {
            error_log('folio: ' . count($candidates) . ' employee rows share ' . $email
                . ' and the password matched none; refusing the agent sign-in.');
        }
        return ['ok' => false, 'error' => 'Invalid email or password'];
    }
    if (count($candidates) > 1) {
        error_log('folio: duplicate rows for ' . $email . '; agent signed in as id ' . (int) $emp['id']);
    }

    $deviceType = isset($body['deviceType']) && is_string($body['deviceType']) ? strtoupper(trim($body['deviceType'])) : 'PC';
    $deviceKey  = isset($body['deviceKey']) && is_string($body['deviceKey']) ? trim($body['deviceKey']) : '';
    if ($deviceKey === '') $deviceKey = 'PC';
    if ($deviceType === '') $deviceType = 'PC';

    $fields = [];
    foreach ([
        'osName' => 'os_name',
        'osVersion' => 'os_version',
        'manufacturer' => 'manufacturer',
        'model' => 'model',
        'appVersion' => 'app_version',
        'hostName' => 'host_name',
    ] as $k => $col) {
        if (isset($body[$k]) && is_string($body[$k]) && trim($body[$k]) !== '') {
            $fields[$col] = trim($body[$k]);
        }
    }

    $dev = device_ensure($emp['email'], $deviceType, $deviceKey, $fields);
    $devId = isset($dev['device_id']) ? (int) $dev['device_id'] : null;

    $token = bin2hex(random_bytes(32));
    q('INSERT INTO agent_tokens (token, email, device_id, created_at) VALUES (?,?,?,?)',
        [$token, $emp['email'], $devId, now_sql()]);
    /* prune that user's stale tokens so the table cannot grow forever */
    $stale = rows('SELECT token FROM agent_tokens WHERE email=? AND created_at < ?',
        [$emp['email'], date('Y-m-d H:i:s', time() - 30 * 86400)]);
    foreach ($stale as $s) q('DELETE FROM agent_tokens WHERE token=?', [$s['token']]);
    device_touch($devId);
    activity_log($emp, 'AGENT_LOGIN', 'Agent signed in', 'agent');
    $res = [
        'ok'     => true,
        'token'  => $token,
        'name'   => employee_full_name($emp),
        'email'  => $emp['email'],
        'role'   => $emp['role'],
        'config' => agent_config_for($emp),
        'today'  => track_summary($emp, today()),
    ];
    if ($devId !== null) $res['deviceId'] = $devId;
    return $res;
}

function api_agent_logout(): array {
    $body = agent_json_body();
    $token = $body['token'] ?? null;
    if (is_string($token) && $token !== '') q('DELETE FROM agent_tokens WHERE token=?', [$token]);
    return ['ok' => true];
}

function api_agent_config(): array {
    $emp = api_employee_from_token($_GET['token'] ?? null);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    return ['ok' => true, 'config' => agent_config_for($emp)];
}

function api_agent_check_in(): array {
    $emp = api_body_employee(agent_json_body());
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    return ['ok' => true, 'today' => track_check_in($emp)];
}

function api_agent_check_out(): array {
    $emp = api_body_employee(agent_json_body());
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    return ['ok' => true, 'today' => track_check_out($emp)];
}

function api_agent_break_start(): array {
    $emp = api_body_employee(agent_json_body());
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    return ['ok' => true, 'today' => track_break_start($emp)];
}

function api_agent_break_end(): array {
    $emp = api_body_employee(agent_json_body());
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    return ['ok' => true, 'today' => track_break_end($emp)];
}

function api_agent_heartbeat(): array {
    $body = agent_json_body();
    $emp = api_body_employee($body);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    $status = is_string($body['status'] ?? null) ? $body['status'] : 'ACTIVE';
    // Shift totals ride along with the beat so a folded laptop still settles its
    // time correctly: the agent counts on a monotonic clock, the server cannot.
    // agent_total() keeps an unreported total as null rather than inventing a 0.
    $totals = [
        'activeSec' => agent_total($body['activeSec'] ?? null),
        'idleSec'   => agent_total($body['idleSec'] ?? null),
        'breakSec'  => agent_total($body['breakSec'] ?? null),
        'pausedSec' => agent_total($body['pausedSec'] ?? null),
    ];
    return [
        'ok'     => true,
        'today'  => track_heartbeat($emp, $status, $totals, agent_str($body['agentVersion'] ?? null)),
        'config' => agent_config_for($emp),
    ];
}

function api_agent_telemetry(): array {
    $body = agent_json_body();
    $emp = api_body_employee($body);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    // The owner keeps their own attendance, so telemetry is still recorded - but
    // the "what are you doing" fields are dropped rather than stored, because
    // those are what monitoring is made of.
    $tracked = agent_tracking_enabled($emp);
    $t = [
        'status'          => (string) ($body['status'] ?? 'ACTIVE'),
        'keystrokes'      => $tracked ? agent_num($body['keystrokes'] ?? null) : 0,
        'mouseClicks'     => $tracked ? agent_num($body['mouseClicks'] ?? null) : 0,
        'mouseScrolls'    => $tracked ? agent_num($body['mouseScrolls'] ?? null) : 0,
        'mouseDistancePx' => $tracked ? agent_num($body['mouseDistancePx'] ?? null) : 0,
        'app'             => $tracked ? agent_str($body['app'] ?? null) : null,
        'title'           => $tracked ? agent_str($body['title'] ?? null) : null,
        'host'            => $tracked ? agent_str($body['host'] ?? null) : null,
        'category'        => $tracked ? agent_str($body['category'] ?? null) : null,
        'hostName'        => $tracked ? agent_str($body['hostName'] ?? null) : null,
        'agentVersion'    => agent_str($body['agentVersion'] ?? null),
        'ip'              => agent_str($body['ip'] ?? null),
        'activeSec'       => agent_total($body['activeSec'] ?? null),
        'idleSec'         => agent_total($body['idleSec'] ?? null),
        'breakSec'        => agent_total($body['breakSec'] ?? null),
        'pausedSec'       => agent_total($body['pausedSec'] ?? null),
    ];
    return [
        'ok'     => true,
        'today'  => track_record_telemetry($emp, $t),
        'config' => agent_config_for($emp),
    ];
}

/**
 * Is this employee's activity tracked at all?
 *
 * The owner is never a monitored target. Hiding them from the rosters and the
 * live grid is not enough on its own: if they ever sign into the desktop agent,
 * their screen, app list and keystroke counters would still be uploaded and
 * stored, because every one of those endpoints trusts the client. So the refusal
 * happens here, at ingestion, and the agent is additionally told to stop
 * capturing (see agent_config_for()).
 *
 * Timekeeping is NOT affected - the owner still clocks in and gets their own
 * attendance records. Only the "what are you doing right now" data is refused.
 */
function agent_tracking_enabled(array $emp): bool {
    return !is_super_admin($emp);
}

/**
 * The agent's policy, with monitoring forced off for an untracked employee.
 *
 * Belt and braces: agent_tracking_enabled() already refuses the uploads, but
 * telling the client not to capture spares the bandwidth and means a frame is
 * never even built on the owner's machine.
 */
function agent_config_for(array $emp): array {
    $cfg = config_payload(config_for($emp['email']));
    if (agent_tracking_enabled($emp)) return $cfg;
    $cfg['captureScreenshots']    = false;
    $cfg['trackMouseKeyboard']   = false;
    $cfg['trackAppUsage']        = false;
    $cfg['trackWebsites']        = false;
    $cfg['liveStreamIntervalSeconds'] = 0;
    $cfg['screenshotIntervalSeconds'] = 0;
    return $cfg;
}

/**
 * Is this employee on a break right now?
 *
 * The agent already stops capturing during a break, but that is a promise made
 * by one client. Anything that would store what the employee is doing has to be
 * refused here as well, so a stale or misbehaving agent cannot leak break-time
 * activity into HR's view. Callers answer "ok, skipped" rather than an error:
 * the agent should not retry or raise an alarm for a deliberate break.
 */
function agent_on_break(array $emp): bool {
    $r = track_record_for($emp['email'], today());
    return ($r['status'] ?? STATUS_OFF) === STATUS_BREAK && !empty($r['break_start']);
}

function api_agent_usage(): array {
    $body = agent_json_body();
    $emp = api_body_employee($body);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!agent_tracking_enabled($emp)) return ['ok' => true, 'skipped' => 'untracked'];

    if (agent_on_break($emp)) {
        return ['ok' => true, 'skipped' => 'on-break'];
    }

    $cfg = config_for($emp['email']);
    $idle = filter_var($body['idle'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $process = agent_str($body['process'] ?? null);
    $host = agent_str($body['host'] ?? null);

    if (usage_is_ignorable($cfg, $process, $host)) {
        return ['ok' => true, 'skipped' => 'excluded'];
    }

    $seconds = (int) agent_num($body['seconds'] ?? null);
    if ($seconds <= 0) {
        return ['ok' => true, 'skipped' => 'no-time'];
    }

    $targetKey = agent_str($body['targetKey'] ?? null);
    $targetType = agent_str($body['targetType'] ?? null);
    if ($targetType === null) $targetType = USAGE_TYPE_APP;
    if ($targetKey === null) {
        $targetKey = $targetType === USAGE_TYPE_WEB && $host !== null
            ? 'web:' . strtolower($host)
            : 'app:' . strtolower($process === null ? 'unknown' : $process);
    }
    if ($targetType === USAGE_TYPE_WEB && !(bool) ($cfg['track_websites'] ?? 1)) {
        return ['ok' => true, 'skipped' => 'websites-disabled'];
    }
    if ($targetType !== USAGE_TYPE_WEB && !(bool) ($cfg['track_app_usage'] ?? 1)) {
        return ['ok' => true, 'skipped' => 'apps-disabled'];
    }

    $displayName = agent_str($body['displayName'] ?? null);
    if ($displayName === null) {
        $displayName = $targetType === USAGE_TYPE_WEB && $host !== null ? $host : $process;
    }
    $category = agent_str($body['category'] ?? null);
    if ($category === null) {
        $category = usage_category_for($idle ? USAGE_TYPE_IDLE : $targetType, $host, $process);
    }

    $saved = usage_record_sample($emp['email'], $targetKey, $targetType, $displayName,
        $process, agent_str($body['title'] ?? null), $host, $category, (int) $seconds, $idle);
    return ['ok' => true, 'target' => $saved === null ? null : $saved['target_key']];
}

function api_agent_screenshot(): array {
    $body = agent_json_body();
    $emp = api_body_employee($body);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!agent_tracking_enabled($emp)) return ['ok' => true, 'saved' => false, 'skipped' => 'untracked'];

    if (agent_on_break($emp)) {
        return ['ok' => true, 'saved' => false, 'skipped' => 'on-break'];
    }

    // Same contract as Spring: whatever the agent sends is stored, and the
    // monitoring policy gates what the agent CHOOSES to send on its side.
    $trigger = (string) ($body['trigger'] ?? 'AUTO');
    $live = strcasecmp($trigger, 'LIVE') === 0;
    $saved = track_save_screenshot($emp,
        isset($body['image']) && is_string($body['image']) ? $body['image'] : null,
        strtoupper($trigger), agent_str($body['app'] ?? null),
        agent_str($body['title'] ?? null), agent_str($body['host'] ?? null), $live);
    $res = ['ok' => true, 'saved' => $saved !== null];
    if ($saved !== null) {
        $res['id'] = (int) $saved['id'];
        $res['capturedAt'] = $saved['captured_at'];
    }
    return $res;
}

/**
 * One frame of the live screen broadcast. The frame overwrites a single slot
 * per employee, so the response stays tiny and a 1 fps stream costs one file.
 */
function api_agent_live_frame(): array {
    $body = agent_json_body();
    $emp = api_body_employee($body);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!agent_tracking_enabled($emp)) return ['ok' => true, 'skipped' => 'untracked'];

    if (agent_on_break($emp)) {
        return ['ok' => true, 'skipped' => 'on-break'];
    }

    $frame = isset($body['frame']) && is_string($body['frame']) ? $body['frame'] : null;
    if ($frame === null && isset($body['image']) && is_string($body['image'])) $frame = $body['image'];
    $saved = live_frame_store($emp['email'], $frame, (int) agent_num($body['seq'] ?? 0), [
        'status'      => agent_str($body['status'] ?? 'ACTIVE'),
        'app'         => agent_str($body['app'] ?? null),
        'title'       => agent_str($body['title'] ?? null),
        'host'        => agent_str($body['host'] ?? null),
        'category'    => agent_str($body['category'] ?? null),
        'hostName'    => agent_str($body['hostName'] ?? null),
        'agentVersion'=> agent_str($body['agentVersion'] ?? null),
        'ip'          => agent_str($body['ip'] ?? null),
    ]);
    if ($saved === null) return ['ok' => false, 'error' => 'frame rejected'];
    return ['ok' => true, 'seq' => (int) $saved['seq'], 'at' => date('h:i:s A')];
}

function api_agent_today(): array {
    $emp = api_employee_from_token($_GET['token'] ?? null);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    $res = ['ok' => true, 'today' => track_summary($emp, today()), 'apps' => [], 'sites' => []];
    $apps = array_slice(usage_for($emp['email'], today(), USAGE_TYPE_APP), 0, 8);
    $sites = array_slice(usage_for($emp['email'], today(), USAGE_TYPE_WEB), 0, 8);
    foreach ($apps as $a) $res['apps'][] = usage_api_row($a);
    foreach ($sites as $s) $res['sites'][] = usage_api_row($s);
    return $res;
}

function api_agent_day(): array {
    $emp = api_employee_from_token($_GET['token'] ?? null);
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];

    $segments = [];
    $totalWorked = 0;
    $totalBreak = 0;
    $manualLocked = false;
    foreach (track_all_on_date(today()) as $r) {
        if (strcasecmp($emp['email'], (string) $r['email']) !== 0) continue;
        $ws = track_worked_seconds($r);
        $bs = (int) ($r['break_seconds'] ?? 0) + track_running_break($r);
        $segments[] = [
            'checkIn'   => track_check_in_text($r),
            'checkOut'  => track_check_out_text($r),
            'workedSec' => $ws,
            'breakSec'  => $bs,
            'status'    => $r['status'],
            'onDuty'    => track_on_duty($r),
            'manualLocked' => track_manual_locked($r),
            'activeSec' => (int) ($r['active_seconds'] ?? 0),
            'idleSec'   => (int) ($r['idle_seconds'] ?? 0),
            'pausedSec' => (int) ($r['paused_seconds'] ?? 0),
        ];
        $totalWorked += $ws;
        $totalBreak += $bs;
        if (track_manual_locked($r)) $manualLocked = true;
    }
    $cfg = config_for($emp['email']);
    $dayRow = track_record_for($emp['email'], today());
    return [
        'ok'              => true,
        'date'            => today(),
        'segments'        => $segments,
        'totalWorkedSec'  => $totalWorked,
        'totalBreakSec'   => $totalBreak,
        'breakAllowanceSec' => (int) ($cfg['break_allowance_minutes'] ?? 30) * 60,
        'manualLocked'    => $manualLocked || track_manual_locked($dayRow),
        'activeSec'       => (int) ($dayRow['active_seconds'] ?? 0),
        'idleSec'         => (int) ($dayRow['idle_seconds'] ?? 0),
        'breakSec'        => $totalBreak,
        'pausedSec'       => (int) ($dayRow['paused_seconds'] ?? 0),
        'workedSec'       => $totalWorked,
        'status'          => $dayRow['status'] ?? STATUS_OFF,
        'checkIn'         => track_check_in_text($dayRow),
        'checkOut'        => track_check_out_text($dayRow),
    ];
}

/* ---------------------------------------------------------------- helpers ---- */

function agent_str($value): ?string {
    if ($value === null) return null;
    $s = trim((string) $value);
    return $s === '' ? null : $s;
}

/**
 * A counter the agent reports. Absent, blank or malformed means zero events,
 * which is a real and harmless value for keystrokes, clicks and the like.
 */
function agent_num($value): int {
    if ($value === null) return 0;
    if (is_numeric($value)) return (int) $value;
    return 0;
}

/**
 * A shift-total the agent reports, as null when it did not report one.
 *
 * Same coercion as agent_num() except that "not sent" stays null instead of
 * collapsing to 0. That distinction is the whole point: an agent from before
 * shift totals existed sends no activeSec at all, and reading that as
 * "activeSec = 0" told the server the employee had worked no seconds, so the
 * day's real total could be overwritten by zero. null means "this client does
 * not report this", and track_apply_totals() then leaves the bucket untouched.
 */
function agent_total($value): ?int {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return (int) $value;
}