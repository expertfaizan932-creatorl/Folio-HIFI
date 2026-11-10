<?php
/**
 * WorkFolio PHP - web login, roles and the single source of the visibility rule.
 *
 * Port of EmployeeService helpers + ReportAccessService from the Spring app.
 * The rule is defined once here:
 *   SUPER_ADMIN -> may read everyone, and is never a monitored target
 *   ADMIN       -> may read everyone except SUPER_ADMIN
 *   HR          -> may read EMPLOYEE-role people, plus themselves
 *   EMPLOYEE    -> may read only themselves
 */
require_once __DIR__ . '/core.php';

/* ------------------------------------------------------------- employees ----- */

function employee_find(int $id): ?array {
    return one('SELECT * FROM employees WHERE id=?', [$id]);
}

function employee_find_by_email(string $email): ?array {
    $all = employee_find_by_email_all($email);
    return $all[0] ?? null;
}

/**
 * Every account row holding this address, oldest first.
 *
 * The address is meant to be unique (both current DDLs declare it so), but a
 * table created before that guarantee - or by the older Java schema, which had
 * no unique key - can hold more than one. Login must therefore consider every
 * candidate: verifying against an arbitrary row is what made one person's
 * credentials refuse while somebody else's address answered for them.
 */
function employee_find_by_email_all(string $email): array {
    return rows('SELECT * FROM employees WHERE email=? ORDER BY id ASC', [$email]);
}

function employee_all(): array {
    return rows('SELECT * FROM employees ORDER BY id ASC');
}

function employee_count(): int {
    return (int) val('SELECT COUNT(*) FROM employees');
}

function employee_count_role(string $role): int {
    return (int) val('SELECT COUNT(*) FROM employees WHERE `role`=?', [$role]);
}

function employee_avg_salary(): float {
    return (float) (val('SELECT AVG(salary) FROM employees') ?? 0);
}

/** The owner role. Sits above ADMIN and is deliberately never tracked. */
function is_super_admin(?array $user): bool {
    return $user !== null && strcasecmp((string) ($user['role'] ?? ''), 'SUPER_ADMIN') === 0;
}

function employee_full_name(array $e): string {
    return trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
}

/* ------------------------------------------------------------ web session ---- */

function cur_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u = employee_find((int) $_SESSION['uid']);
    if ($u === null) return null;
    return $u;
}

/** Redirect to login when nobody is signed in. Returns the signed-in user. */
function require_login(): array {
    $u = cur_user();
    if ($u === null) redirect(base_url('/login'));
    return $u;
}

/** Gate pages/actions that need one of the listed roles. Redirect when denied. */
function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        redirect(base_url('/dashboard'));
    }
    return $u;
}

function do_login(string $email, string $password): ?array {
    $candidates = employee_find_by_email_all(strtolower(trim($email)));
    $emp = null;
    foreach ($candidates as $cand) {
        if (password_verify($password, (string) $cand['password'])) { $emp = $cand; break; }
    }
    if ($emp === null) {
        if (count($candidates) > 1) {
            error_log('folio: ' . count($candidates) . ' employee rows share ' . strtolower(trim($email))
                . ' - password matched none of them. Add a UNIQUE index on employees.email after de-duplicating.');
        }
        return null;
    }
    if (count($candidates) > 1) {
        error_log('folio: duplicate rows for ' . strtolower(trim($email)) . '; signed in as id ' . (int) $emp['id']);
    }
    /* Rotate the session id ourselves. On Windows ZTS PHP
       session_regenerate_id(true) can rotate in memory and delete the old
       file yet never send the new cookie, which logs the very next page/API
       call straight back out. Rebinding to a fresh id and writing the cookie
       by hand keeps the id the client stores pointing at a file that really
       holds the uid set below. */
    $prevSession = $_SESSION;
    session_write_close();
    $newSid = bin2hex(random_bytes(16));
    session_id($newSid);
    session_start();
    $_SESSION = $prevSession;
    header_remove('Set-Cookie');
    $cp = session_get_cookie_params();
    setcookie(session_name(), $newSid, [
        'expires'  => 0,
        'path'     => $cp['path'],
        'domain'   => $cp['domain'],
        'secure'   => $cp['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_SESSION['uid'] = (int) $emp['id'];
    activity_log($emp, 'LOGIN', 'Signed in (web)', 'web');
    return $emp;
}

function do_logout(): void {
    if (cur_user() !== null) activity_log(cur_user(), 'LOGOUT', 'Signed out (web)', 'web');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------------------------------------------------------- report access ---- */

/** Roles that supervise other people, i.e. anyone who may open the live floor. */
function is_supervisor(?array $user): bool {
    $role = (string) ($user['role'] ?? '');
    return $role === 'SUPER_ADMIN' || $role === 'ADMIN' || $role === 'HR';
}

/** May $viewer read the tracking data of $target? */
function access_can_view(?array $viewer, ?array $target): bool {
    if ($viewer === null || $target === null) return false;
    if (strcasecmp((string) ($viewer['email'] ?? ''), (string) ($target['email'] ?? '')) === 0) return true;
    // The owner is not a monitored target: they never appear as a tile in the
    // live grid and nobody reads their own tracking data.
    if (strcasecmp((string) ($target['role'] ?? ''), 'SUPER_ADMIN') === 0) return is_super_admin($viewer);
    if (($viewer['role'] ?? '') === 'SUPER_ADMIN') return true;
    if (($viewer['role'] ?? '') === 'ADMIN') return true;
    if (($viewer['role'] ?? '') === 'HR') return ($target['role'] ?? '') === 'EMPLOYEE';
    return false;
}

/** Overload for callers that only hold the target email. Fails closed. */
function access_can_view_email(?array $viewer, ?string $targetEmail): bool {
    if ($viewer === null || $targetEmail === null || trim($targetEmail) === '') return false;
    if (strcasecmp((string) $viewer['email'], trim($targetEmail)) === 0) return true;
    $target = employee_find_by_email(trim($targetEmail));
    return $target !== null && access_can_view($viewer, $target);
}

/** Everyone the viewer's reports may cover, in the same order as employee_all(). */
function access_visible_employees(array $viewer): array {
    $out = [];
    foreach (employee_all() as $e) {
        // The owner is never a tracked tile, not even for themselves.
        if (strcasecmp((string) ($e['role'] ?? ''), 'SUPER_ADMIN') === 0) continue;
        if (access_can_view($viewer, $e)) $out[] = $e;
    }
    return $out;
}

/**
 * Super admins may create, edit and demote every other role. Everyone else is
 * capped, so an ADMIN cannot mint a new SUPER_ADMIN and walk away with the
 * whole company. Only a SUPER_ADMIN can grant or revoke the role.
 */
function access_can_manage_role(?array $viewer, ?string $targetRole): bool {
    if (!is_super_admin($viewer)) return false;
    return true;
}

/** Roles $viewer is allowed to assign when creating or editing someone. */
function access_assignable_roles(?array $viewer): array {
    if (is_super_admin($viewer)) return ['EMPLOYEE', 'HR', 'ADMIN', 'SUPER_ADMIN'];
    if (($viewer['role'] ?? '') === 'ADMIN') return ['EMPLOYEE', 'HR', 'ADMIN'];
    if (($viewer['role'] ?? '') === 'HR') return ['EMPLOYEE'];
    return [];
}

/**
 * The owner account is the root of trust, so the three ways to take one over -
 * edit its row, reset its password, delete it - are all reserved for owners.
 *
 * These are the single source of truth for "may $actor touch $target's account",
 * and each one also folds in the pre-existing HR restriction so no new call site
 * can forget it: HR only ever reaches EMPLOYEE-role people.
 */
function access_can_edit_account(?array $actor, ?array $target): bool {
    if ($actor === null || $target === null) return false;
    if (($actor['role'] ?? '') === 'HR') return ($target['role'] ?? '') === 'EMPLOYEE';
    return !is_super_admin($target) || is_super_admin($actor);
}

function access_can_reset_password(?array $actor, ?array $target): bool {
    return access_can_edit_account($actor, $target);
}

/**
 * Deletion has one extra rule beyond the takeover rules: the last owner cannot
 * be removed, because SUPER_ADMIN is the only role that can create another one.
 * Without this an accidental delete locks everyone out of repairs forever.
 */
function access_can_delete_account(?array $actor, ?array $target): bool {
    if (!access_can_edit_account($actor, $target)) return false;
    if (is_super_admin($target) && employee_count_role('SUPER_ADMIN') <= 1) return false;
    return true;
}

/** Would changing $target's role to $newRole keep at least one owner in place? */
function access_role_change_allowed(?array $target, string $newRole): bool {
    if ($target === null) return false;
    if (!is_super_admin($target)) return true;
    if (strcasecmp($newRole, 'SUPER_ADMIN') === 0) return true;
    return employee_count_role('SUPER_ADMIN') > 1;
}

/**
 * Sanitises a ?email= selection. Returns the email when allowed, null otherwise
 * (null means "every visible employee"). Asking for someone you may not see
 * quietly yields the broadest permitted view.
 */
function access_resolve_selection(array $viewer, ?string $requestedEmail): ?string {
    if ($requestedEmail === null || trim($requestedEmail) === '') return null;
    $target = employee_find_by_email(trim($requestedEmail));
    if ($target === null) return null;
    return access_can_view($viewer, $target) ? $target['email'] : null;
}

/* ------------------------------------------------------------ CSRF (web) ---- */

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . esc(csrf_token()) . '">';
}

function csrf_valid(): bool {
    $given = $_POST['_csrf'] ?? '';
    return $given !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) $given);
}

function require_csrf(): void {
    if (!csrf_valid()) {
        http_response_code(419);
        echo 'Session expired - please go back, reload and try again.';
        exit;
    }
}