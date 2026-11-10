<?php
/**
 * WorkFolio PHP - interactive terminal.
 *
 * Unlike remote_command.php (one line in, one exit code + output out, kept as an
 * audit trail), this opens a single long-lived cmd.exe on one employee's PC and
 * lets a manager type into it like a real console. The console session stays up
 * so `cd`, environment changes and running programs persist between keystrokes.
 *
 * Transport is short polling, not a socket: the agent asks for new input and
 * posts whatever the console printed, and the browser polls for that output.
 * There is no sockets API on the shared host, and a terminal is small enough
 * that a fast poll is indistinguishable from a live connection.
 *
 * Nothing typed here is retained. Input and output rows exist only while the
 * shell is OPEN and are deleted the moment it is closed, so - as the owner
 * asked - a terminal session leaves nothing to browse back through.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_agent.php';
require_once __DIR__ . '/remote_command.php';

/** Longest single line a manager may type into the terminal. */
const REMOTE_SHELL_LINE_MAX = 4000;
/** How long an untouched OPEN shell is kept before it is abandoned and dropped. */
const REMOTE_SHELL_IDLE_SECONDS = 3600;
/** Output rows older than this are pruned even while the shell is open. */
const REMOTE_SHELL_OUT_SECONDS = 900;
const REMOTE_SHELL_OPEN = 'OPEN';
const REMOTE_SHELL_CLOSED = 'CLOSED';

/**
 * The OPEN shell for an employee, if any. An abandoned shell (no activity for
 * REMOTE_SHELL_IDLE_SECONDS) is dropped here rather than lingering as a
 * half-dead session that a later manager would attach to.
 */
function remote_shell_current(string $email, ?int $deviceId = null): ?array {
    /* A device-bound session is claimed only by that device; a legacy row with
       a NULL device is claimable by any of the employee's agents, exactly as
       before the device dimension existed. */
    $sql = 'SELECT * FROM remote_shells WHERE target_email=? AND status=?'
        . ($deviceId === null
            ? ' AND target_device_id IS NULL'
            : ' AND (target_device_id IS NULL OR target_device_id=?)')
        . ' ORDER BY id DESC LIMIT 1';
    $params = [strtolower(trim($email)), REMOTE_SHELL_OPEN];
    if ($deviceId !== null) $params[] = $deviceId;
    $row = one($sql, $params);
    if ($row === null) return null;
    $updated = ts_of($row['updated_at']);
    if ($updated !== null && $updated < time() - REMOTE_SHELL_IDLE_SECONDS) {
        remote_shell_destroy((int) $row['id']);
        return null;
    }
    return $row;
}

function remote_shell_get(int $id): ?array {
    return one('SELECT * FROM remote_shells WHERE id=?', [$id]);
}

/** Delete a shell and everything it passed through the console. */
function remote_shell_destroy(int $id): void {
    q('DELETE FROM remote_shell_in WHERE shell_id=?', [$id]);
    q('DELETE FROM remote_shell_out WHERE shell_id=?', [$id]);
    q('DELETE FROM remote_shells WHERE id=?', [$id]);
}

/** Open (or re-attach to) the terminal for one target (email or dev:<id>). */
function remote_shell_open(array $admin, string $target): array {
    $resolved = remote_resolve_target($target);
    if (empty($resolved['ok']) || !isset($resolved['email'])) {
        return ['ok' => false, 'error' => (string) ($resolved['error'] ?? 'Choose a single system')];
    }
    $targetEmail = (string) $resolved['email'];
    $deviceId = ($resolved['device_id'] ?? null) === null ? null : (int) $resolved['device_id'];
    $target = employee_find_by_email(strtolower(trim($targetEmail)));
    if ($target === null) return ['ok' => false, 'error' => 'No such employee'];
    if (!remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'You may only open a terminal on HR and employee systems'];
    }
    $existing = remote_shell_current((string) $target['email'], $deviceId);
    if ($existing !== null) {
        q('UPDATE remote_shells SET updated_at=?, opened_by=? WHERE id=?',
            [now_sql(), $admin['email'], (int) $existing['id']]);
        return ['ok' => true, 'shell' => (int) $existing['id'], 'resumed' => true, 'target' => (string) $target['email']];
    }
    q('INSERT INTO remote_shells (target_email, target_device_id, opened_by, status, opened_at, updated_at) VALUES (?,?,?,?,?,?)',
        [(string) $target['email'], $deviceId, $admin['email'], REMOTE_SHELL_OPEN, now_sql(), now_sql()]);
    $id = (int) last_id();
    activity_log($admin, 'REMOTE_SHELL_OPEN', 'Terminal opened for ' . $target['email']
        . ($deviceId !== null ? ' (device #' . $deviceId . ')' : ''), 'web');
    return ['ok' => true, 'shell' => $id, 'resumed' => false, 'target' => (string) $target['email']];
}

/** A manager typed a line and pressed Enter. */
function remote_shell_write(array $admin, int $shellId, string $line): array {
    $shell = remote_shell_get($shellId);
    if ($shell === null) return ['ok' => false, 'error' => 'No such session'];
    if ((string) $shell['status'] !== REMOTE_SHELL_OPEN) return ['ok' => false, 'error' => 'Session has ended'];
    $target = employee_find_by_email((string) $shell['target_email']);
    if ($target === null || !remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'Not allowed'];
    }
    if (strlen($line) > REMOTE_SHELL_LINE_MAX) return ['ok' => false, 'error' => 'Line too long'];
    q('INSERT INTO remote_shell_in (shell_id, command, delivered, created_at) VALUES (?,?,0,?)',
        [$shellId, $line, now_sql()]);
    q('UPDATE remote_shells SET updated_at=? WHERE id=?', [now_sql(), $shellId]);
    return ['ok' => true];
}

/** End the session and erase its transcript. */
function remote_shell_close(array $admin, int $shellId): array {
    $shell = remote_shell_get($shellId);
    if ($shell === null) return ['ok' => true];
    $target = employee_find_by_email((string) $shell['target_email']);
    if ($target !== null && !remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'Not allowed'];
    }
    remote_shell_destroy($shellId);
    activity_log($admin, 'REMOTE_SHELL_CLOSE', 'Terminal closed for ' . $shell['target_email'], 'web');
    return ['ok' => true];
}

/* -------------------------------------------------------------- agent side ---- */

/**
 * The agent asks for work. When a shell is open it gets the lines typed since
 * its last poll; otherwise it is told there is nothing to do, cheaply.
 */
function api_agent_shell_poll(): array {
    $body = agent_json_body();
    $token = $body['token'] ?? null;
    $ctx = token_context(is_string($token) ? $token : null);
    $emp = $ctx['emp'];
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!remote_role_may_execute((string) ($emp['role'] ?? ''))) return ['ok' => true, 'shell' => null];

    $shell = remote_shell_current((string) $emp['email'], remote_token_device_id($ctx));
    if ($shell === null) return ['ok' => true, 'shell' => null];

    $shellId = (int) $shell['id'];
    $pending = rows('SELECT id, command FROM remote_shell_in WHERE shell_id=? AND delivered=0 ORDER BY id ASC LIMIT 200',
        [$shellId]);
    if ($pending) {
        q('UPDATE remote_shell_in SET delivered=1 WHERE shell_id=? AND delivered=0', [$shellId]);
    }
    q('UPDATE remote_shells SET updated_at=? WHERE id=?', [now_sql(), $shellId]);

    $inputs = [];
    foreach ($pending as $p) $inputs[] = ['id' => (int) $p['id'], 'command' => (string) $p['command']];
    return [
        'ok'    => true,
        'shell' => ['id' => $shellId, 'cwd' => (string) ($shell['cwd'] ?? ''), 'inputs' => $inputs],
    ];
}

/** The agent pastes what cmd.exe printed. */
function api_agent_shell_out(): array {
    $body = agent_json_body();
    $token = $body['token'] ?? null;
    $ctx = token_context(is_string($token) ? $token : null);
    $emp = $ctx['emp'];
    if ($emp === null) return ['ok' => false, 'error' => 'Unauthorized - please login again'];
    if (!remote_role_may_execute((string) ($emp['role'] ?? ''))) return ['ok' => true];

    $shellId = (int) ($body['shell'] ?? 0);
    $data = is_string($body['data'] ?? null) ? $body['data'] : '';
    if ($shellId <= 0) return ['ok' => false, 'error' => 'Missing shell id'];
    $shell = remote_shell_get($shellId);
    if ($shell === null || !remote_session_owned_by($shell, $emp['email'], remote_token_device_id($ctx))) {
        return ['ok' => false, 'error' => 'Unknown session'];
    }
    if ($data !== '') {
        q('INSERT INTO remote_shell_out (shell_id, data, created_at) VALUES (?,?,?)',
            [$shellId, $data, now_sql()]);
        q('DELETE FROM remote_shell_out WHERE shell_id=? AND created_at < ?',
            [$shellId, date('Y-m-d H:i:s', time() - REMOTE_SHELL_OUT_SECONDS)]);
    }
    q('UPDATE remote_shells SET updated_at=? WHERE id=?', [now_sql(), $shellId]);
    return ['ok' => true];
}

/* ------------------------------------------------------------ console side ---- */

function api_manager_shell_open(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $target = is_string($body['target'] ?? null) ? $body['target'] : '';
    return remote_shell_open($admin, $target);
}

function api_manager_shell_read(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $shellId = (int) ($body['shell'] ?? 0);
    $cursor = (int) ($body['cursor'] ?? 0);
    if ($shellId <= 0) return ['ok' => false, 'error' => 'Missing shell id'];
    $shell = remote_shell_get($shellId);
    if ($shell === null) return ['ok' => true, 'data' => '', 'cursor' => $cursor, 'status' => 'GONE'];
    $target = employee_find_by_email((string) $shell['target_email']);
    if ($target === null || !remote_may_target($admin, $target)) {
        return ['ok' => false, 'error' => 'Not allowed'];
    }
    q('UPDATE remote_shells SET updated_at=? WHERE id=?', [now_sql(), (int) $shell['id']]);
    $chunks = rows('SELECT id, data FROM remote_shell_out WHERE shell_id=? AND id>? ORDER BY id ASC LIMIT 500',
        [(int) $shell['id'], $cursor]);
    $text = '';
    $newCursor = $cursor;
    foreach ($chunks as $c) {
        $text .= (string) $c['data'];
        $newCursor = (int) $c['id'];
    }
    return [
        'ok'     => true,
        'data'   => $text,
        'cursor' => $newCursor,
        'status' => (string) $shell['status'],
    ];
}

function api_manager_shell_write(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    $shell = (int) ($body['shell'] ?? 0);
    $line = is_string($body['line'] ?? null) ? $body['line'] : '';
    return remote_shell_write($admin, $shell, $line);
}

function api_manager_shell_close(): array {
    $admin = remote_require_admin_json();
    if (!remote_csrf_valid()) api_json_response(['ok' => false, 'error' => 'CSRF']);
    $body = agent_json_body();
    return remote_shell_close($admin, (int) ($body['shell'] ?? 0));
}
