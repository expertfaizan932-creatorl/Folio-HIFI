<?php
/**
 * WorkFolio PHP - Remote Control.
 *
 * A management console on the owner's machine issues a cmd line; the agent
 * installed on an employee's PC picks it up on its next poll, runs it through
 * cmd.exe and reports exit code + captured output back. Every row names the
 * admin who asked for it, so the whole feature is an audit trail rather than a
 * silent backdoor: the agent that executes the line also shows it on screen.
 *
 * Who may do what:
 *   - only SUPER_ADMIN / ADMIN may queue a command;
 *   - a command can only target an HR or EMPLOYEE-role account (never the
 *     owner, never an admin);
 *   - the agent may poll/report only rows addressed to its own signed-in email;
 *   - nothing executes unless the caller holds a live session (console) or a
 *     live agent token (agent), and every step is logged.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_agent.php';

/** Longest cmd line an admin may run (the table column is TEXT). */
const REMOTE_COMMAND_MAX = 4000;
/** Longest captured output kept per run. */
const REMOTE_OUTPUT_MAX = 256 * 1024;
/** Selector an admin sends to mean "every HR + employee system at once". */
const REMOTE_TARGET_ALL = '__ALL__';
const REMOTE_TARGET_SERVER = '__SERVER__';
/** Fleet selectors narrowed to one device family: every PC, or every Android. */
const REMOTE_TARGET_ALL_PC = '__ALL_PC__';
const REMOTE_TARGET_ALL_ANDROID = '__ALL_ANDROID__';
const REMOTE_STATUS_QUEUED  = 'QUEUED';
const REMOTE_STATUS_RUNNING = 'RUNNING';
const REMOTE_STATUS_DONE    = 'DONE';
const REMOTE_STATUS_FAILED  = 'FAILED';

/** May this viewer aim a command at this target? Owner powers, HR + employee targets only. */
function remote_may_target(array $viewer, array $target): bool {
    $role = (string) ($viewer['role'] ?? '');
    if ($role !== 'SUPER_ADMIN' && $role !== 'ADMIN') return false;
    if (strcasecmp((string) ($viewer['email'] ?? ''), (string) ($target['email'] ?? '')) === 0) return false;
    return remote_role_may_execute((string) ($target['role'] ?? ''));
}

/** The roles whose installed agent is allowed to pick a queued line up. */
function remote_role_may_execute(string $role): bool {
    return $role === 'EMPLOYEE' || $role === 'HR';
}

/** Queue one command for one agent. Returns ['ok'=>true,'id'=>..] or an error. */
function remote_queue_command(array $admin, string $targetEmail, string $command, ?int $deviceId = null): array {
    $target = employee_find_by_email(strtolower(trim($targetEmail)));
    if ($target === null) return ['ok' => false, 'error' => 'No such employee'];
    if (!remote_may_target($admin, $target)) return ['ok' => false, 'error' => 'You may only run commands on HR and employee systems'];
    $command = trim($command);
    if ($command === '') return ['ok' => false, 'error' => 'Empty command'];
    if (strlen($command) > REMOTE_COMMAND_MAX) {
        return ['ok' => false, 'error' => 'Command too long (max ' . REMOTE_COMMAND_MAX . ' chars)'];
    }
    $id = last_id();
    q('INSERT INTO remote_commands (target_email, command, status, requested_by, created_at, target_device_id) VALUES (?,?,?,?,?,?)', [
        $target['email'], $command, REMOTE_STATUS_QUEUED, $admin['email'], now_sql(), $deviceId,
    ]);
    $id = last_id();
    activity_log($admin, 'REMOTE_CMD_QUEUED',
        'Command for ' . $target['email'] . ' (#'. $id . '): ' . $command, 'web');
    return ['ok' => true, 'id' => (int) $id];
}


function remote_execute_server($command): array {
    $command = trim((string) $command);
    if ($command === '') return ['exitcode' => 1, 'output' => 'Empty command'];
    $output = [];
    $exitcode = 0;
    if (function_exists('exec')) {
        @exec($command . ' 2>&1', $output, $exitcode);
        return ['exitcode' => $exitcode, 'output' => implode(PHP_EOL, $output)];
    } elseif (function_exists('shell_exec')) {
        $res = @shell_exec($command . ' 2>&1');
        return ['exitcode' => 0, 'output' => $res === null ? '' : (string) $res];
    } elseif (function_exists('system')) {
        ob_start();
        @system($command . ' 2>&1', $exitcode);
        $res = ob_get_clean();
        return ['exitcode' => $exitcode, 'output' => $res === null ? '' : (string) $res];
    } elseif (function_exists('passthru')) {
        ob_start();
        @passthru($command . ' 2>&1', $exitcode);
        $res = ob_get_clean();
        return ['exitcode' => $exitcode, 'output' => $res === null ? '' : (string) $res];
    }
    return ['exitcode' => 1, 'output' => 'No shell execution method available'];
}

/** Every HR + employee address a command may ever land on. */
function remote_targetable_emails(): array {
    return array_column(
        rows("SELECT email FROM employees WHERE `role` IN ('EMPLOYEE','HR') ORDER BY email ASC"),
        'email'
    );
}

/**
 * Turn one console target string into something queueable.
 *
 * Sentinels answer with a 'kind' instead of an address:
 *   __SERVER__                    -> ['ok'=>true,'kind'=>'server']
 *   __ALL__ / __ALL_PC__ / __ALL_ANDROID__
 *                                 -> ['ok'=>true,'kind'=>'all','device_type'=>null|'PC'|'ANDROID']
 *   dev:<id>                      -> that registered device's email + device_id
 *   plain address                 -> the employee's PC device when they have one,
 *                                    their only device when there is exactly one,
 *                                    else the address alone with device_id null
 *                                    (a legacy email-scoped row).
 *
 * Role limits are deliberately NOT checked here: remote_may_target still guards
 * every queue call, so this stays a pure address/lookup helper.
 */
function remote_resolve_target(string $target): array {
    $t = trim($target);
    if ($t === REMOTE_TARGET_SERVER) return ['ok' => true, 'kind' => 'server'];
    if ($t === REMOTE_TARGET_ALL) return ['ok' => true, 'kind' => 'all', 'device_type' => null];
    if ($t === REMOTE_TARGET_ALL_PC) return ['ok' => true, 'kind' => 'all', 'device_type' => 'PC'];
    if ($t === REMOTE_TARGET_ALL_ANDROID) return ['ok' => true, 'kind' => 'all', 'device_type' => 'ANDROID'];

    if (stripos($t, 'dev:') === 0) {
        $devId = (int) substr($t, 4);
        $dev = $devId > 0 ? one('SELECT * FROM devices WHERE device_id=?', [$devId]) : null;
        if ($dev === null) return ['ok' => false, 'error' => 'No such device'];
        return ['ok' => true, 'email' => (string) $dev['email'], 'device_id' => (int) $dev['device_id']];
    }

    $email = strtolower(trim($t));
    $emp = employee_find_by_email($email);
    if ($emp === null) return ['ok' => false, 'error' => 'No such employee'];
    $devs = rows('SELECT * FROM devices WHERE email=? ORDER BY device_id ASC', [(string) $emp['email']]);
    $pc = null;
    foreach ($devs as $d) {
        if (strtoupper((string) ($d['device_key'] ?? '')) === 'PC'
            || strtoupper((string) ($d['device_type'] ?? '')) === 'PC') {
            $pc = $d;
            break;
        }
    }
    if ($pc !== null) {
        return ['ok' => true, 'email' => (string) $emp['email'], 'device_id' => (int) $pc['device_id']];
    }
    if (count($devs) === 1) {
        return ['ok' => true, 'email' => (string) $emp['email'], 'device_id' => (int) $devs[0]['device_id']];
    }
    /* No device, or an ambiguous multi-device owner: fall back to the address,
       so the row stays deliverable by email exactly as before. */
    return ['ok' => true, 'email' => (string) $emp['email'], 'device_id' => null];
}

/**
 * Every registered device whose employee may actually be targeted (HR +
 * EMPLOYEE roles only), optionally narrowed to one device family. The inner
 * JOIN drops devices whose owner account was deleted.
 */
function remote_targetable_devices(?string $deviceType = null): array {
    $sql = 'SELECT d.device_id, d.email, d.device_type, d.model, d.manufacturer, d.host_name'
        . ' FROM devices d'
        . ' JOIN employees e ON e.email = d.email'
        . " WHERE `role` IN ('EMPLOYEE','HR')";
    $params = [];
    if ($deviceType !== null && trim($deviceType) !== '') {
        $sql .= ' AND d.device_type = ?';
        $params[] = strtoupper(trim($deviceType));
    }
    $sql .= ' ORDER BY d.email ASC, d.device_id ASC';
    return rows($sql, $params);
}

/**
 * Queue one line for one target address, or for a fleet sentinel
 * (REMOTE_TARGET_ALL / _ALL_PC / _ALL_ANDROID) to fan the same line out to
 * every matching HR and employee system. Returns the single-queue shape, plus
 * 'queued' => how many rows were written. Every row written here carries a
 * resolved target_device_id when the target resolved to a device.
 */
function remote_queue_command_many(array $admin, string $target, string $command): array {
    $targetTrim = trim($target);
    if ($targetTrim === REMOTE_TARGET_SERVER) {
        /* Running a shell line on the web host itself is an owner-only power:
           an ADMIN manages people, only the SUPER_ADMIN owns the server. */
        if (($admin['role'] ?? '') !== 'SUPER_ADMIN') {
            return ['ok' => false, 'error' => 'Only a super admin can run on the server'];
        }
        $command = trim($command);
        if ($command === '') return ['ok' => false, 'error' => 'Empty command'];
        if (strlen($command) > REMOTE_COMMAND_MAX) {
            return ['ok' => false, 'error' => 'Command too long (max ' . REMOTE_COMMAND_MAX . ' chars)'];
        }
        $res = remote_execute_server($command);
        q('INSERT INTO remote_commands (target_email, command, status, requested_by, created_at, started_at, finished_at, exitcode, output) VALUES (?,?,?,?,?,?,?,?,?)', [REMOTE_TARGET_SERVER, $command, REMOTE_STATUS_DONE, $admin['email'], now_sql(), now_sql(), now_sql(), $res['exitcode'], substr($res['output'], 0, REMOTE_OUTPUT_MAX)]);
        $id = last_id();
        activity_log($admin, 'REMOTE_CMD_DONE', 'Command for SERVER (#' . $id . '): ' . $command, 'web');
        return ['ok' => true, 'id' => (int) $id, 'queued' => 0, 'direct' => true, 'exitcode' => $res['exitcode'], 'output' => $res['output']];
    }
    $res = remote_resolve_target($targetTrim);
    if (($res['ok'] ?? false) === false) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Bad target'), 'queued' => 0];
    }
    if (($res['kind'] ?? '') === 'all') {
        $devices = remote_targetable_devices($res['device_type'] ?? null);
        if (!$devices) {
            $what = ($res['device_type'] ?? null) === 'PC' ? 'PC systems'
                : (($res['device_type'] ?? null) === 'ANDROID' ? 'Android systems' : 'HR or employee systems');
            return ['ok' => false, 'error' => 'No ' . $what . ' to target', 'queued' => 0];
        }
        $queued = 0;
        $firstId = 0;
        foreach ($devices as $dev) {
            $one = remote_queue_command($admin, (string) $dev['email'], $command, (int) $dev['device_id']);
            if (!$one['ok']) {
                return ['ok' => false, 'error' => (string) $one['error'], 'queued' => $queued, 'id' => $firstId];
            }
            $queued++;
            if ($firstId === 0) $firstId = (int) $one['id'];
        }
        return ['ok' => true, 'id' => $firstId, 'queued' => $queued];
    }
    $one = remote_queue_command($admin, (string) $res['email'], $command, isset($res['device_id']) ? $res['device_id'] : null);
    $one['queued'] = $one['ok'] ? 1 : 0;
    return $one;
}

/**
 * The next QUEUED row for this agent, claimed atomically and marked RUNNING.
 *
 * Rows nobody picks up within a day are dead (the agent was uninstalled, or the
 * admin fat-fingered a target) - expire them first so the queue cannot grow
 * without bound. A row aimed at NULL is a legacy email-scoped row and stays
 * deliverable by address alone; with a device id, that device's own rows plus
 * the legacy NULL rows both match.
 */
function remote_claim_next(string $email, ?int $deviceId = null): ?array {
    $cutoff = date('Y-m-d H:i:s', time() - 86400);
    q('UPDATE remote_commands SET status=?, finished_at=?, output=? WHERE status=? AND created_at<?',
        [REMOTE_STATUS_FAILED, now_sql(), 'expired: not picked up within 24h', REMOTE_STATUS_QUEUED, $cutoff]);
    if ($deviceId === null) {
        $candidate = one('SELECT * FROM remote_commands WHERE target_email=? AND status=? AND target_device_id IS NULL ORDER BY id ASC LIMIT 1',
            [$email, REMOTE_STATUS_QUEUED]);
    } else {
        $candidate = one('SELECT * FROM remote_commands WHERE target_email=? AND status=? AND (target_device_id IS NULL OR target_device_id=?) ORDER BY id ASC LIMIT 1',
            [$email, REMOTE_STATUS_QUEUED, $deviceId]);
    }
    if ($candidate === null) return null;
    /* one writer wins: shared hosting is single-process, but the rowCount guard
       keeps a double-poll from ever running a line twice. */
    $st = q('UPDATE remote_commands SET status=?, started_at=? WHERE id=? AND status=?',
        [REMOTE_STATUS_RUNNING, now_sql(), (int) $candidate['id'], REMOTE_STATUS_QUEUED]);
    return $st->rowCount() === 1 ? one('SELECT * FROM remote_commands WHERE id=?', [(int) $candidate['id']]) : null;
}

/** Agent reports the outcome of one of its own commands. */
function remote_report(string $email, int $id, ?int $exitcode, string $output, ?int $deviceId = null): array {
    if ($deviceId === null) {
        $row = one('SELECT * FROM remote_commands WHERE id=? AND target_email=? AND target_device_id IS NULL', [$id, $email]);
    } else {
        $row = one('SELECT * FROM remote_commands WHERE id=? AND target_email=? AND (target_device_id IS NULL OR target_device_id=?)',
            [$id, $email, $deviceId]);
    }
    if ($row === null) return ['ok' => false, 'error' => 'Unknown command'];
    if (strlen($output) > REMOTE_OUTPUT_MAX) $output = substr($output, 0, REMOTE_OUTPUT_MAX);
    q('UPDATE remote_commands SET status=?, exitcode=?, output=?, finished_at=? WHERE id=?',
        [$exitcode === 0 ? REMOTE_STATUS_DONE : REMOTE_STATUS_FAILED,
         $exitcode, $output, now_sql(), $id]);
    activity_log(null, 'REMOTE_CMD_DONE',
        '#' . $id . ' for ' . $email . ' exit ' . var_export($exitcode, true), 'agent');
    return ['ok' => true];
}

/**
 * The fleet: one row per registered device, newest shift state and token age
 * alongside. The inner JOIN drops devices whose employee account was deleted;
 * host_name / last_seen still come from today's track row so existing console
 * consumers keep reading the same keys. Additive per-device fields ride along:
 * device_id, device_type, os_*, manufacturer, model, app_version, device_host,
 * device_last_seen and `online` (1 while the device heartbeat is inside the
 * 180-second window, NULL last_seen counts as offline).
 */
function remote_systems_array(): array {
    $onlineCutoff = date('Y-m-d H:i:s', time() - 180);
    return rows(
        'SELECT d.device_id, d.email, e.first_name, e.last_name, e.role,'
        . ' d.device_type, d.os_name, d.os_version, d.manufacturer, d.model, d.app_version,'
        . ' d.host_name AS device_host, d.last_seen AS device_last_seen,'
        . ' CASE WHEN d.last_seen IS NOT NULL AND d.last_seen >= ? THEN 1 ELSE 0 END AS online,'
        . ' MAX(t.created_at) AS token_since,'
        . ' tr.status AS shift_status, tr.current_app, tr.host_name, tr.last_seen, tr.last_shot_at'
        . ' FROM devices d'
        . ' JOIN employees e ON e.email = d.email'
        . ' LEFT JOIN agent_tokens t ON t.device_id = d.device_id'
        . ' LEFT JOIN track_records tr ON tr.email = e.email AND tr.work_date = ?'
        . ' GROUP BY d.device_id'
        . ' ORDER BY e.first_name ASC, e.last_name ASC, d.device_id ASC',
        [$onlineCutoff, today()]);
}

/**
 * Command history, optionally for one target, newest first.
 *
 * The target may be an address (legacy email-scoped rows), dev:<id> (one
 * device), a fleet sentinel (one device family) or null/'' for everything -
 * the same strings the command queue accepts.
 */
function remote_history_array($target, int $limit = 50): array {
    $lim = (int) max(1, $limit);
    if ($target !== null && trim((string) $target) !== '') {
        $t = trim((string) $target);
        if ($t === REMOTE_TARGET_ALL_PC || $t === REMOTE_TARGET_ALL_ANDROID) {
            return rows('SELECT * FROM remote_commands WHERE target_device_id IN (SELECT device_id FROM devices WHERE device_type=?)'
                . ' ORDER BY id DESC LIMIT ' . $lim,
                [$t === REMOTE_TARGET_ALL_PC ? 'PC' : 'ANDROID']);
        }
        if (stripos($t, 'dev:') === 0) {
            return rows('SELECT * FROM remote_commands WHERE target_device_id=? ORDER BY id DESC LIMIT ' . $lim,
                [(int) substr($t, 4)]);
        }
        if ($t !== REMOTE_TARGET_ALL) {
            return rows('SELECT * FROM remote_commands WHERE target_email=? ORDER BY id DESC LIMIT ' . $lim,
                [strtolower($t)]);
        }
    }
    return rows('SELECT * FROM remote_commands ORDER BY id DESC LIMIT ' . $lim);
}

/** CSRF that also answers headers / JSON bodies, so the desktop console can use it. */
function remote_csrf_valid(): bool {
    $given = $_POST['_csrf'] ?? '';
    if ($given === '') $given = $_SERVER['HTTP_X_CSRF'] ?? '';
    if ($given === '') {
        $body = agent_json_body();
        $given = is_string($body['_csrf'] ?? null) ? $body['_csrf'] : '';
    }
    return $given !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) $given);
}

function remote_require_admin_json(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'SUPER_ADMIN' && ($u['role'] ?? '') !== 'ADMIN') {
        api_json_response(['ok' => false, 'error' => 'Not an owner account']);
    }
    return $u;
}

/* -------------------------------------------------------------- agent side ---- */

/**
 * Which device is this token signing for? Derived server-side from the token
 * row - the client never names its own device on a poll, so an agent cannot
 * pick up somebody else's rows just by claiming an id.
 */
function remote_token_device_id(array $ctx): ?int {
    if (is_array($ctx['device'] ?? null) && isset($ctx['device']['device_id'])) return (int) $ctx['device']['device_id'];
    if (is_array($ctx['tokenRow'] ?? null) && isset($ctx['tokenRow']['device_id'])) {
        $did = (int) $ctx['tokenRow']['device_id'];
        if ($did > 0) return $did;
    }
    return null;
}

/**
 * May this agent (email + its server-derived device) touch this shell/fs row?
 * A row with a NULL target_device_id predates the device dimension, so any
 * agent signed in for that employee owns it - exactly the pre-device rule.
 */
function remote_session_owned_by(?array $row, ?string $email, ?int $deviceId): bool {
    if ($row === null) return false;
    if (strcasecmp((string) ($row['target_email'] ?? ''), (string) $email) !== 0) return false;
    $rowDev = isset($row['target_device_id']) && $row['target_device_id'] !== null
        ? (int) $row['target_device_id'] : null;
    if ($rowDev === null) return true;
    return $deviceId !== null && $rowDev === $deviceId;
}

function api_agent_remote_poll(): array {
    $body = agent_json_body();
    $token = $body['token'] ?? null;
    $ctx = token_context(is_string($token) ? $token : null);
    $emp = $ctx['emp'];
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!remote_role_may_execute((string) ($emp['role'] ?? ''))) return ['ok' => true, 'command' => null];
    $row = remote_claim_next($emp['email'], remote_token_device_id($ctx));
    return [
        'ok' => true,
        'command' => $row === null ? null : [
            'id'      => (int) $row['id'],
            'command' => (string) $row['command'],
        ],
    ];
}

function api_agent_remote_report(): array {
    $body = agent_json_body();
    $token = $body['token'] ?? null;
    $ctx = token_context(is_string($token) ? $token : null);
    $emp = $ctx['emp'];
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!remote_role_may_execute((string) ($emp['role'] ?? ''))) return ['ok' => true]; /* never a target */
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) return ['ok' => false, 'error' => 'Missing command id'];
    $exit  = ($body['exitcode'] ?? null) === null ? null : (int) $body['exitcode'];
    $out   = is_string($body['output'] ?? null) ? $body['output'] : '';
    return remote_report($emp['email'], $id, $exit, $out, remote_token_device_id($ctx));
}

/* ------------------------------------------------------------ console side ---- */

function api_manager_login(): array {
    $body = agent_json_body();
    $email = is_string($body['username'] ?? null) ? trim($body['username']) : '';
    $pass  = is_string($body['password'] ?? null) ? $body['password'] : '';
    $emp = do_login($email, (string) $pass);
    if ($emp === null) return ['ok' => false, 'error' => 'Invalid email or password'];
    if (($emp['role'] ?? '') !== 'SUPER_ADMIN' && ($emp['role'] ?? '') !== 'ADMIN') {
        do_logout();
        return ['ok' => false, 'error' => 'Not an owner account'];
    }
    $csrf = csrf_token();
    return ['ok' => true, 'name' => employee_full_name($emp), 'role' => $emp['role'], 'csrf' => $csrf];
}

function api_manager_systems(): array {
    remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    return ['ok' => true, 'systems' => remote_systems_array()];
}

function api_manager_command(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $target = is_string($body['target'] ?? null) ? $body['target'] : '';
    $command = is_string($body['command'] ?? null) ? $body['command'] : '';
    return remote_queue_command_many($admin, $target, $command);
}

function api_manager_history(): array {
    remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $target = is_string($body['target'] ?? null) ? $body['target'] : null;
    return ['ok' => true, 'history' => remote_history_array($target)];
}