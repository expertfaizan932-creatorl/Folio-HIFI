<?php
/**
 * WorkFolio PHP - HR module data layer: Offboarding.
 *
 * Additive like the other HR layers: it owns only the hr_offboarding_* tables
 * and reads the roster (employees) by email. A plan is one leaver's clearance
 * checklist, keyed by email. Creating a plan stamps a standard checklist so HR
 * has something to work through immediately; items can then be added or removed.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */

/* ---------------------------------------------------------------- lookups ---- */

function hr_offboarding_types(): array {
    return ['RESIGNATION' => 'Resignation', 'TERMINATION' => 'Termination',
            'RETIREMENT' => 'Retirement', 'CONTRACT_END' => 'Contract End'];
}

function hr_offboarding_type_label(string $t): string {
    return hr_offboarding_types()[strtoupper(trim($t))] ?? 'Resignation';
}

function hr_offboarding_statuses(): array {
    return ['REQUESTED' => 'Requested', 'IN_PROGRESS' => 'In Progress',
            'COMPLETED' => 'Completed', 'REJECTED' => 'Rejected', 'CANCELLED' => 'Cancelled'];
}

function hr_offboarding_status_badge(string $s): string {
    $map = ['REQUESTED' => 'bg-info text-dark', 'IN_PROGRESS' => 'bg-primary',
            'COMPLETED' => 'bg-success', 'REJECTED' => 'bg-danger', 'CANCELLED' => 'bg-secondary'];
    $labels = hr_offboarding_statuses();
    $s = strtoupper(trim($s)) ?: 'IN_PROGRESS';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_offboarding_task_statuses(): array {
    return ['PENDING' => 'Pending', 'DONE' => 'Done', 'SKIPPED' => 'Skipped'];
}

function hr_offboarding_task_status_badge(string $s): string {
    $map = ['PENDING' => 'bg-secondary', 'DONE' => 'bg-success', 'SKIPPED' => 'bg-dark'];
    $labels = hr_offboarding_task_statuses();
    $s = strtoupper(trim($s)) ?: 'PENDING';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_offboarding_categories(): array {
    return ['GENERAL' => 'General', 'IT' => 'IT & Access', 'HR' => 'HR Formalities',
            'FINANCE' => 'Finance', 'FACILITIES' => 'Facilities', 'COMPLIANCE' => 'Compliance'];
}

function hr_offboarding_category_label(string $c): string {
    return hr_offboarding_categories()[strtoupper(trim($c))] ?? 'General';
}

function hr_offboarding_owner_roles(): array {
    return ['HR' => 'HR', 'MANAGER' => 'Manager', 'EMPLOYEE' => 'Leaver', 'IT' => 'IT', 'ADMIN' => 'Admin'];
}

/* ---------------------------------------------------------------- cleaning ---- */

function hof_text(string $v, int $max): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hof_block(string $v, int $max = 8000): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace("/\r\n?/", "\n", trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hof_date(?string $v): string {
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

/* ------------------------------------------------------------------- plans ---- */

function hr_offboarding_plans(array $filters = []): array {
    $sql = 'SELECT p.*,
                   (SELECT COUNT(*) FROM hr_offboarding_tasks x WHERE x.plan_id = p.id) AS task_count,
                   (SELECT COUNT(*) FROM hr_offboarding_tasks x WHERE x.plan_id = p.id AND x.status IN (\'DONE\',\'SKIPPED\')) AS done_count
            FROM hr_offboarding_plans p';
    $where = []; $p = [];
    if (!empty($filters['status'])) { $where[] = 'p.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['email']))  { $where[] = 'p.email = ?'; $p[] = strtolower((string) $filters['email']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY p.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_offboarding_plan(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_offboarding_plans WHERE id=?', [$id]) : null;
}

function hr_offboarding_plan_by_email(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    return one('SELECT * FROM hr_offboarding_plans WHERE email=? ORDER BY id DESC LIMIT 1', [$email]);
}

/** The plan that blocks a fresh request: one that is still open (requested or
    in progress). Finished/rejected plans do not stop a new resignation. */
function hr_offboarding_open_plan(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    return one("SELECT * FROM hr_offboarding_plans WHERE email=? AND status IN ('REQUESTED','IN_PROGRESS')
                ORDER BY id DESC LIMIT 1", [$email]);
}

/** Add N calendar days to a YYYY-MM-DD date, returning YYYY-MM-DD (or ''). */
function hof_add_days(string $date, int $days): string {
    $date = trim($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    $ts = strtotime($date . ' 00:00:00');
    if ($ts === false) return '';
    return date('Y-m-d', $ts + max(0, $days) * 86400);
}

/** The standard clearance list stamped onto a new plan. */
function hr_offboarding_default_tasks(): array {
    return [
        ['Knowledge transfer & handover', 'GENERAL', 'EMPLOYEE', 1],
        ['Return company assets (laptop, phone, accessories)', 'IT', 'IT', 2],
        ['Revoke system access / disable login', 'IT', 'IT', 3],
        ['Return access card, keys & parking pass', 'FACILITIES', 'ADMIN', 4],
        ['Clear pending leave / adjust balances', 'HR', 'HR', 5],
        ['Final settlement & dues clearance', 'FINANCE', 'HR', 6],
        ['Conduct exit interview', 'HR', 'HR', 7],
        ['Issue experience / relieving letter', 'HR', 'HR', 8],
    ];
}

function hr_offboarding_plan_create(array $d, string $by): int {
    $email = strtolower(hof_text((string) ($d['email'] ?? ''), 190));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    if (hr_offboarding_open_plan($email) !== null) return 0; /* one open plan per person */
    $type = strtoupper(trim((string) ($d['offboard_type'] ?? 'RESIGNATION')));
    if (!isset(hr_offboarding_types()[$type])) $type = 'RESIGNATION';
    $fullName = hof_text((string) ($d['full_name'] ?? ''), 200);
    if ($fullName === '') {
        $emp = employee_find_by_email($email);
        if ($emp !== null) $fullName = employee_full_name($emp);
    }
    $noticeDays = max(0, (int) ($d['notice_period_days'] ?? 0));
    $resignDate = hof_date($d['resignation_date'] ?? null);
    $lastWorking = hof_date($d['last_working_date'] ?? null);
    if ($lastWorking === '' && $resignDate !== '' && $noticeDays > 0) {
        $lastWorking = hof_add_days($resignDate, $noticeDays);
    }
    $source = strtoupper(trim((string) ($d['source'] ?? 'HR'))) === 'SELF' ? 'SELF' : 'HR';
    q('INSERT INTO hr_offboarding_plans (email,full_name,offboard_type,resignation_date,last_working_date,reason,
       status,exit_interview,eligible_rehire,clearance_notes,notice_period_days,notice_end_date,source,
       created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $email, $fullName, $type, $resignDate, $lastWorking,
        hof_block((string) ($d['reason'] ?? '')),
        'IN_PROGRESS', '', !empty($d['eligible_rehire']) ? 1 : 0, '',
        $noticeDays, $lastWorking, $source,
        strtolower(trim($by)), now_sql(), now_sql(),
    ]);
    $planId = last_id();

    hr_offboarding_stamp_default_tasks($planId);
    return $planId;
}

/** Stamp the standard clearance list onto a plan. */
function hr_offboarding_stamp_default_tasks(int $planId): void {
    $sort = 0;
    foreach (hr_offboarding_default_tasks() as $t) {
        $sort++;
        q('INSERT INTO hr_offboarding_tasks (plan_id,title,category,owner_role,status,sort_order,created_at,updated_at)
           VALUES (?,?,?,?,?,?,?,?)', [$planId, $t[0], $t[1], $t[2], 'PENDING', $sort, now_sql(), now_sql()]);
    }
}

/** An employee files their own resignation: a REQUESTED plan awaiting HR. */
function hr_offboarding_request_create(string $email, array $d): int {
    $email = strtolower(hof_text($email, 190));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    if (hr_offboarding_open_plan($email) !== null) return 0;
    $emp = employee_find_by_email($email);
    $fullName = $emp !== null ? employee_full_name($emp) : '';
    $noticeDays = max(0, (int) ($d['notice_period_days'] ?? 0));
    $resignDate = hof_date($d['resignation_date'] ?? null) ?: date('Y-m-d');
    $lastWorking = hof_date($d['last_working_date'] ?? null);
    if ($lastWorking === '' && $noticeDays > 0) $lastWorking = hof_add_days($resignDate, $noticeDays);
    q('INSERT INTO hr_offboarding_plans (email,full_name,offboard_type,resignation_date,last_working_date,reason,
       status,exit_interview,eligible_rehire,clearance_notes,notice_period_days,notice_end_date,source,
       created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $email, $fullName, 'RESIGNATION', $resignDate, $lastWorking,
        hof_block((string) ($d['reason'] ?? '')),
        'REQUESTED', '', 0, '',
        $noticeDays, $lastWorking, 'SELF',
        $email, now_sql(), now_sql(),
    ]);
    return last_id();
}

/** HR approves a REQUESTED plan: it goes live and the checklist is stamped. */
function hr_offboarding_request_approve(int $id, array $d, string $by): bool {
    $p = hr_offboarding_plan($id);
    if ($p === null || strtoupper((string) $p['status']) !== 'REQUESTED') return false;
    $noticeDays = max(0, (int) ($d['notice_period_days'] ?? $p['notice_period_days']));
    $resignDate = hof_date($p['resignation_date']) ?: date('Y-m-d');
    $lastWorking = hof_date($d['last_working_date'] ?? null);
    if ($lastWorking === '') $lastWorking = hof_add_days($resignDate, $noticeDays);
    q('UPDATE hr_offboarding_plans SET status=?, notice_period_days=?, last_working_date=?, notice_end_date=?,
       approved_by=?, approved_at=?, decision_note=?, updated_at=? WHERE id=?', [
        'IN_PROGRESS', $noticeDays, $lastWorking, $lastWorking,
        strtolower(trim($by)), now_sql(), hof_block((string) ($d['decision_note'] ?? '')), now_sql(), $id,
    ]);
    if (hr_offboarding_tasks($id) === []) hr_offboarding_stamp_default_tasks($id);
    activity_log(['email' => $by, 'role' => ''], 'HR_OFFBOARDING_APPROVED', 'Plan #' . $id);
    return true;
}

/** HR declines a REQUESTED plan. */
function hr_offboarding_request_reject(int $id, string $note, string $by): bool {
    $p = hr_offboarding_plan($id);
    if ($p === null || strtoupper((string) $p['status']) !== 'REQUESTED') return false;
    q('UPDATE hr_offboarding_plans SET status=?, approved_by=?, approved_at=?, decision_note=?, updated_at=? WHERE id=?',
        ['REJECTED', strtolower(trim($by)), now_sql(), hof_block($note), now_sql(), $id]);
    activity_log(['email' => $by, 'role' => ''], 'HR_OFFBOARDING_REJECTED', 'Plan #' . $id);
    return true;
}

function hr_offboarding_plan_save(int $id, array $d, string $by): bool {
    $p = hr_offboarding_plan($id);
    if ($p === null) return false;
    $type = strtoupper(trim((string) ($d['offboard_type'] ?? 'RESIGNATION')));
    if (!isset(hr_offboarding_types()[$type])) $type = 'RESIGNATION';
    $noticeDays = max(0, (int) ($d['notice_period_days'] ?? $p['notice_period_days']));
    $resignDate = hof_date($d['resignation_date'] ?? null);
    $lastWorking = hof_date($d['last_working_date'] ?? null);
    if ($lastWorking === '' && $resignDate !== '' && $noticeDays > 0) {
        $lastWorking = hof_add_days($resignDate, $noticeDays);
    }
    q('UPDATE hr_offboarding_plans SET offboard_type=?, resignation_date=?, last_working_date=?,
       notice_period_days=?, notice_end_date=?, reason=?,
       exit_interview=?, eligible_rehire=?, clearance_notes=?, updated_at=? WHERE id=?', [
        $type,
        $resignDate,
        $lastWorking,
        $noticeDays, $lastWorking,
        hof_block((string) ($d['reason'] ?? '')),
        hof_block((string) ($d['exit_interview'] ?? '')),
        !empty($d['eligible_rehire']) ? 1 : 0,
        hof_block((string) ($d['clearance_notes'] ?? '')),
        now_sql(), $id,
    ]);
    activity_log(['email' => $by, 'role' => ''], 'HR_OFFBOARDING_SAVED', 'Plan #' . $id);
    return true;
}

function hr_offboarding_set_status(int $id, string $status, string $by): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_offboarding_statuses()[$status])) return false;
    if (hr_offboarding_plan($id) === null) return false;
    $done = $status === 'COMPLETED' ? now_sql() : null;
    q('UPDATE hr_offboarding_plans SET status=?, completed_at=?, updated_at=? WHERE id=?',
        [$status, $done, now_sql(), $id]);
    activity_log(['email' => $by, 'role' => ''], 'HR_OFFBOARDING_STATUS', 'Plan #' . $id . ' -> ' . $status);
    return true;
}

function hr_offboarding_plan_delete(int $id): void {
    q('DELETE FROM hr_offboarding_tasks WHERE plan_id=?', [$id]);
    q('DELETE FROM hr_offboarding_plans WHERE id=?', [$id]);
}

/* ------------------------------------------------------------------- tasks ---- */

function hr_offboarding_tasks(int $planId): array {
    return rows('SELECT * FROM hr_offboarding_tasks WHERE plan_id=? ORDER BY sort_order ASC, id ASC', [$planId]);
}

function hr_offboarding_task(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_offboarding_tasks WHERE id=?', [$id]) : null;
}

function hr_offboarding_task_save(?int $id, int $planId, array $d, string $by): int {
    $title = hof_text((string) ($d['title'] ?? ''), 200);
    if ($title === '' || hr_offboarding_plan($planId) === null) return 0;
    $cat = strtoupper(trim((string) ($d['category'] ?? 'GENERAL')));
    if (!isset(hr_offboarding_categories()[$cat])) $cat = 'GENERAL';
    $role = strtoupper(trim((string) ($d['owner_role'] ?? 'HR')));
    if (!isset(hr_offboarding_owner_roles()[$role])) $role = 'HR';
    $status = strtoupper(trim((string) ($d['status'] ?? 'PENDING')));
    if (!isset(hr_offboarding_task_statuses()[$status])) $status = 'PENDING';
    $sort = (int) ($d['sort_order'] ?? 0);
    if ($id) {
        q('UPDATE hr_offboarding_tasks SET title=?, category=?, owner_role=?, status=?, sort_order=?, notes=?, updated_at=?
           WHERE id=?', [$title, $cat, $role, $status, $sort, hof_block((string) ($d['notes'] ?? '')), now_sql(), $id]);
        return $id;
    }
    q('INSERT INTO hr_offboarding_tasks (plan_id,title,category,owner_role,status,sort_order,notes,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?)',
        [$planId, $title, $cat, $role, $status, $sort, hof_block((string) ($d['notes'] ?? '')), now_sql(), now_sql()]);
    $newId = last_id();
    if ($status === 'DONE') {
        q('UPDATE hr_offboarding_tasks SET completed_by=?, completed_at=? WHERE id=?',
            [strtolower(trim($by)), now_sql(), $newId]);
    }
    return $newId;
}

function hr_offboarding_task_toggle(int $id, string $status, string $by): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_offboarding_task_statuses()[$status])) return false;
    $t = hr_offboarding_task($id);
    if ($t === null) return false;
    $by = strtolower(trim($by));
    if ($status === 'DONE') {
        q('UPDATE hr_offboarding_tasks SET status=?, completed_by=?, completed_at=?, updated_at=? WHERE id=?',
            [$status, $by, now_sql(), now_sql(), $id]);
    } else {
        q('UPDATE hr_offboarding_tasks SET status=?, completed_by=NULL, completed_at=NULL, updated_at=? WHERE id=?',
            [$status, now_sql(), $id]);
    }
    hr_offboarding_recheck_plan((int) $t['plan_id']);
    return true;
}

function hr_offboarding_task_delete(int $id): void {
    $t = hr_offboarding_task($id);
    if ($t === null) return;
    q('DELETE FROM hr_offboarding_tasks WHERE id=?', [$id]);
    hr_offboarding_recheck_plan((int) $t['plan_id']);
}

/** Auto-complete a plan whose items are all closed; reopen when one is added. */
function hr_offboarding_recheck_plan(int $planId): void {
    $plan = hr_offboarding_plan($planId);
    if ($plan === null) return;
    $prog = hr_offboarding_progress($planId);
    if ($prog['total'] > 0 && $prog['done'] >= $prog['total']) {
        if (strtoupper((string) $plan['status']) === 'IN_PROGRESS') {
            q('UPDATE hr_offboarding_plans SET status=?, completed_at=?, updated_at=? WHERE id=?',
                ['COMPLETED', now_sql(), now_sql(), $planId]);
        }
    } elseif (strtoupper((string) $plan['status']) === 'COMPLETED') {
        q('UPDATE hr_offboarding_plans SET status=?, completed_at=NULL, updated_at=? WHERE id=?',
            ['IN_PROGRESS', now_sql(), $planId]);
    }
}

function hr_offboarding_progress(int $planId): array {
    $tasks = hr_offboarding_tasks($planId);
    $total = count($tasks); $done = 0;
    foreach ($tasks as $t) {
        if (in_array(strtoupper((string) $t['status']), ['DONE', 'SKIPPED'], true)) $done++;
    }
    return ['total' => $total, 'done' => $done, 'open' => $total - $done,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0];
}

/* --------------------------------------------------------------- dashboard ---- */

function hr_offboarding_stats(): array {
    try {
        $active = (int) val("SELECT COUNT(*) FROM hr_offboarding_plans WHERE status='IN_PROGRESS'");
        $completedMonth = (int) val("SELECT COUNT(*) FROM hr_offboarding_plans WHERE status='COMPLETED' AND completed_at LIKE ?",
            [date('Y-m') . '%']);
        $pending = (int) val("SELECT COUNT(*) FROM hr_offboarding_tasks t JOIN hr_offboarding_plans p ON p.id=t.plan_id
            WHERE p.status='IN_PROGRESS' AND t.status='PENDING'");
        $requested = (int) val("SELECT COUNT(*) FROM hr_offboarding_plans WHERE status='REQUESTED'");
    } catch (Throwable $e) {
        return ['active_plans' => 0, 'completed_month' => 0, 'pending_tasks' => 0, 'requested' => 0];
    }
    return ['active_plans' => $active, 'completed_month' => $completedMonth, 'pending_tasks' => $pending, 'requested' => $requested];
}
