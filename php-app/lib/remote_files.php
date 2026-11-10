<?php
/**
 * WorkFolio PHP - remote file manager.
 *
 * The owner's desktop console (WorkFolioManager) browses the drives of one
 * employee's PC the same way the interactive terminal works: the manager posts
 * a JSON op, the agent executes it locally and posts a JSON reply back. The
 * agent owns the filesystem calls; this library is only the mailbox between the
 * two, plus an audit trail.
 *
 * Transport is short polling, matching remote_shell.php. One OPEN session per
 * target; the request queue is remote_fs_in and the reply queue is remote_fs_out.
 * A request carries a caller-chosen "rid" and the reply echoes it, so the
 * console can pair a chunk of a large transfer with the op that asked for it.
 *
 * Ops are deliberately dumb: drives, list, stat, read, write, mkdir, rename,
 * delete, copy, move, ping. The agent validates paths; the server never touches
 * a file, it only relays bytes. Transfer rows are pruned aggressively and the
 * whole session is erased when it closes, so nothing large lingers in the DB.
 *
 * Who may do what mirrors remote_command.php: only SUPER_ADMIN / ADMIN drive
 * it, only HR / EMPLOYEE accounts can be a target, and the agent may only touch
 * rows addressed to its own signed-in email.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_agent.php';
require_once __DIR__ . '/remote_command.php';

/** Longest single op the console may queue (base64 of one 1 MiB chunk fits). */
const REMOTE_FS_CMD_MAX = 2 * 1024 * 1024;
/** Longest reply the agent may post for one op. */
const REMOTE_FS_OUT_MAX = 3 * 1024 * 1024;
/** Most reply bytes handed to the console in one read. */
const REMOTE_FS_READ_MAX = 4 * 1024 * 1024;
/** How long an untouched OPEN session is kept before it is abandoned. */
const REMOTE_FS_IDLE_SECONDS = 3600;
/** Reply rows older than this are pruned even while the session lives. */
const REMOTE_FS_OUT_SECONDS = 900;
const REMOTE_FS_OPEN = 'OPEN';
const REMOTE_FS_CLOSED = 'CLOSED';

/** Ops that change the filesystem and are worth an audit line each. */
const REMOTE_FS_MUTATING = ['mkdir', 'rename', 'delete', 'copy', 'move'];

/**
 * The OPEN session for an employee, if any. An abandoned session (no activity
 * for REMOTE_FS_IDLE_SECONDS) is dropped here rather than lingering half-dead.
 */
function remote_fs_current(string $email, ?int $deviceId = null): ?array {
    /* Device-bound sessions are claimed only by their device; NULL = pre-device
       row, claimable by any of the employee's agents (legacy behaviour). */
    $sql = 'SELECT * FROM remote_fs WHERE target_email=? AND status=?'
        . ($deviceId === null
            ? ' AND target_device_id IS NULL'
            : ' AND (target_device_id IS NULL OR target_device_id=?)')
        . ' ORDER BY id DESC LIMIT 1';
    $params = [strtolower(trim($email)), REMOTE_FS_OPEN];
    if ($deviceId !== null) $params[] = $deviceId;
    $row = one($sql, $params);
    if ($row === null) return null;
    $updated = ts_of($row['updated_at']);
    if ($updated !== null && $updated < time() - REMOTE_FS_IDLE_SECONDS) {
        remote_fs_destroy((int) $row['id']);
        return null;
    }
    return $row;
}

function remote_fs_get(int $id): ?array {
    return one('SELECT * FROM remote_fs WHERE id=?', [$id]);
}

/** Delete a session and everything it passed through the queues. */
function remote_fs_destroy(int $id): void {
    q('DELETE FROM remote_fs_in WHERE fs_id=?', [$id]);
    q('DELETE FROM remote_fs_out WHERE fs_id=?', [$id]);
    q('DELETE FROM remote_fs WHERE id=?', [$id]);
}

/** Open (or re-attach to) the file browser for one target (email or dev:<id>). */
function remote_fs_open(array $admin, string $target): array {
    $resolved = remote_resolve_target($target);
    if (empty($resolved['ok']) || !isset($resolved['email'])) {
        return ['ok' => false, 'error' => (string) ($resolved['error'] ?? 'Choose a single system')];
    }
    $targetEmail = (string) $resolved['email'];
    $deviceId = ($resolved['device_id'] ?? null) === null ? null : (int) $resolved['device_id'];
    $target = employee_find_by_email(strtolower(trim($targetEmail)));
    if ($target === null) return ['ok' => false, 'error' => 'No such employee'];
    if (!remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'You may only browse HR and employee systems'];
    }
    $existing = remote_fs_current((string) $target['email'], $deviceId);
    if ($existing !== null) {
        q('UPDATE remote_fs SET updated_at=?, opened_by=? WHERE id=?',
            [now_sql(), $admin['email'], (int) $existing['id']]);
        return ['ok' => true, 'fs' => (int) $existing['id'], 'resumed' => true,
                'target' => (string) $target['email'], 'cwd' => (string) ($existing['cwd'] ?? '')];
    }
    q('INSERT INTO remote_fs (target_email, target_device_id, opened_by, status, cwd, opened_at, updated_at) VALUES (?,?,?,?,?,?,?)',
        [(string) $target['email'], $deviceId, $admin['email'], REMOTE_FS_OPEN, '', now_sql(), now_sql()]);
    $id = (int) last_id();
    activity_log($admin, 'REMOTE_FS_OPEN', 'File manager opened for ' . $target['email']
        . ($deviceId !== null ? ' (device #' . $deviceId . ')' : ''), 'web');
    return ['ok' => true, 'fs' => $id, 'resumed' => false, 'target' => (string) $target['email'], 'cwd' => ''];
}

/**
 * Queue one JSON op for the agent. Kept deliberately permissive on shape: the
 * agent is the only thing that understands the ops, so the server validates
 * length and that it is an object, then relays it verbatim.
 */
function remote_fs_push(array $admin, int $fsId, string $command): array {
    $fs = remote_fs_get($fsId);
    if ($fs === null) return ['ok' => false, 'error' => 'No such session'];
    if ((string) $fs['status'] !== REMOTE_FS_OPEN) return ['ok' => false, 'error' => 'Session has ended'];
    $target = employee_find_by_email((string) $fs['target_email']);
    if ($target === null || !remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'Not allowed'];
    }
    $command = trim($command);
    if ($command === '') return ['ok' => false, 'error' => 'Empty op'];
    if (strlen($command) > REMOTE_FS_CMD_MAX) return ['ok' => false, 'error' => 'Op too large'];
    $decoded = json_decode($command, true);
    if (!is_array($decoded) || !is_string($decoded['op'] ?? null)) {
        return ['ok' => false, 'error' => 'Malformed op'];
    }
    q('INSERT INTO remote_fs_in (fs_id, command, delivered, created_at) VALUES (?,?,0,?)',
        [$fsId, $command, now_sql()]);
    q('UPDATE remote_fs SET updated_at=? WHERE id=?', [now_sql(), $fsId]);
    remote_fs_audit_op($admin, $fsId, $decoded);
    return ['ok' => true];
}

/** One audit line for the ops that actually change the target's disk. */
function remote_fs_audit_op(array $admin, int $fsId, array $op): void {
    $name = (string) $op['op'];
    if (!in_array($name, REMOTE_FS_MUTATING, true)) return;
    $path = is_string($op['path'] ?? null) ? $op['path'] : '';
    $to   = is_string($op['to'] ?? null) ? $op['to'] : '';
    $msg  = 'FS ' . $name . ' ' . $path . ($to !== '' ? ' -> ' . $to : '') . ' (session #' . $fsId . ')';
    activity_log($admin, 'REMOTE_FS_' . strtoupper($name), $msg, 'web');
}

/** End the session and erase its queues. */
function remote_fs_close(array $admin, int $fsId): array {
    $fs = remote_fs_get($fsId);
    if ($fs === null) return ['ok' => true];
    $target = employee_find_by_email((string) $fs['target_email']);
    if ($target !== null && !remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'Not allowed'];
    }
    remote_fs_destroy($fsId);
    activity_log($admin, 'REMOTE_FS_CLOSE', 'File manager closed for ' . $fs['target_email'], 'web');
    return ['ok' => true];
}

/* -------------------------------------------------------------- agent side ---- */

/** The agent asks for work. It gets the ops queued since its last poll. */
function api_agent_fs_poll(): array {
    $body = agent_json_body();
    $token = $body['token'] ?? null;
    $ctx = token_context(is_string($token) ? $token : null);
    $emp = $ctx['emp'];
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!remote_role_may_execute((string) ($emp['role'] ?? ''))) return ['ok' => true, 'fs' => null];

    $fs = remote_fs_current((string) $emp['email'], remote_token_device_id($ctx));
    if ($fs === null) return ['ok' => true, 'fs' => null];

    $fsId = (int) $fs['id'];
    $pending = rows('SELECT id, command FROM remote_fs_in WHERE fs_id=? AND delivered=0 ORDER BY id ASC LIMIT 8',
        [$fsId]);
    if ($pending) {
        q('UPDATE remote_fs_in SET delivered=1 WHERE fs_id=? AND delivered=0', [$fsId]);
    }
    q('UPDATE remote_fs SET updated_at=? WHERE id=?', [now_sql(), $fsId]);

    $inputs = [];
    foreach ($pending as $p) $inputs[] = (string) $p['command'];
    return [
        'ok' => true,
        'fs' => ['id' => $fsId, 'inputs' => $inputs],
    ];
}

/** The agent posts the reply to one op. */
function api_agent_fs_out(): array {
    $body = agent_json_body();
    $token = $body['token'] ?? null;
    $ctx = token_context(is_string($token) ? $token : null);
    $emp = $ctx['emp'];
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!remote_role_may_execute((string) ($emp['role'] ?? ''))) return ['ok' => true];

    $fsId = (int) ($body['fs'] ?? 0);
    $data = is_string($body['data'] ?? null) ? $body['data'] : '';
    if ($fsId <= 0) return ['ok' => false, 'error' => 'Missing session id'];
    $fs = remote_fs_get($fsId);
    if ($fs === null || !remote_session_owned_by($fs, $emp['email'], remote_token_device_id($ctx))) {
        return ['ok' => false, 'error' => 'Unknown session'];
    }
    if ($data !== '') {
        if (strlen($data) > REMOTE_FS_OUT_MAX) $data = substr($data, 0, REMOTE_FS_OUT_MAX);
        q('INSERT INTO remote_fs_out (fs_id, data, created_at) VALUES (?,?,?)',
            [$fsId, $data, now_sql()]);
        q('DELETE FROM remote_fs_out WHERE fs_id=? AND created_at < ?',
            [$fsId, date('Y-m-d H:i:s', time() - REMOTE_FS_OUT_SECONDS)]);
    }
    $cwd = is_string($body['cwd'] ?? null) ? $body['cwd'] : null;
    if ($cwd !== null) q('UPDATE remote_fs SET cwd=?, updated_at=? WHERE id=?', [substr($cwd, 0, 1000), now_sql(), $fsId]);
    else q('UPDATE remote_fs SET updated_at=? WHERE id=?', [now_sql(), $fsId]);
    return ['ok' => true];
}

/* ------------------------------------------------------------ console side ---- */

function api_manager_fs_open(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $target = is_string($body['target'] ?? null) ? $body['target'] : '';
    return remote_fs_open($admin, $target);
}

function api_manager_fs_write(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $fs = (int) ($body['fs'] ?? 0);
    $command = is_string($body['command'] ?? null) ? $body['command'] : '';
    return remote_fs_push($admin, $fs, $command);
}

/**
 * Hand the console the replies queued since its cursor. Bounded to
 * REMOTE_FS_READ_MAX bytes per call so a burst of chunks cannot blow the
 * response size; the cursor advances only over rows actually returned.
 */
function api_manager_fs_read(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $fsId = (int) ($body['fs'] ?? 0);
    $cursor = (int) ($body['cursor'] ?? 0);
    if ($fsId <= 0) return ['ok' => false, 'error' => 'Missing session id'];
    $fs = remote_fs_get($fsId);
    if ($fs === null) return ['ok' => true, 'results' => [], 'cursor' => $cursor, 'status' => 'GONE'];
    $target = employee_find_by_email((string) $fs['target_email']);
    if ($target === null || !remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'Not allowed'];
    }
    q('UPDATE remote_fs SET updated_at=? WHERE id=?', [now_sql(), (int) $fs['id']]);
    $chunks = rows('SELECT id, data FROM remote_fs_out WHERE fs_id=? AND id>? ORDER BY id ASC LIMIT 100',
        [(int) $fs['id'], $cursor]);
    $results = [];
    $total = 0;
    $newCursor = $cursor;
    foreach ($chunks as $c) {
        $data = (string) $c['data'];
        if ($total > 0 && $total + strlen($data) > REMOTE_FS_READ_MAX) break;
        $results[] = $data;
        $total += strlen($data);
        $newCursor = (int) $c['id'];
    }
    return [
        'ok'      => true,
        'results' => $results,
        'cursor'  => $newCursor,
        'status'  => (string) $fs['status'],
    ];
}

function api_manager_fs_close(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    return remote_fs_close($admin, (int) ($body['fs'] ?? 0));
}
