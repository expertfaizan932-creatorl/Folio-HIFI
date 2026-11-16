<?php
/**
 * WorkFolio PHP - HR module data layer, Phase 2: Onboarding.
 *
 * Additive like hr.php: it owns only the hr_onboarding_* tables and reads the
 * roster (employees) and org lookups (hr_branches / hr_departments) by id. A
 * plan is a copy of a template's checklist for one new hire; the template is
 * never mutated when a plan is created, so editing a template only affects the
 * plans started afterwards.
 *
 * Plans and tasks are keyed by email, not by employee id, so onboarding can
 * begin the moment an offer is accepted - before the person has a login.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */

/* ---------------------------------------------------------------- lookups ---- */

/**
 * A checklist item's bucket. The list is the only source of a legal category;
 * anything else is folded back to GENERAL so a bad POST cannot invent one.
 */
function hr_onboarding_categories(): array {
    return [
        'GENERAL'    => 'General',
        'DOCUMENTS'  => 'Documents',
        'IT'         => 'IT & Access',
        'HR'         => 'HR Formalities',
        'COMPLIANCE' => 'Compliance',
        'TRAINING'   => 'Training',
        'TEAM'       => 'Team & Intro',
        'FACILITIES' => 'Facilities',
    ];
}

function hr_onboarding_category_label(string $c): string {
    $c = strtoupper(trim($c));
    return hr_onboarding_categories()[$c] ?? 'General';
}

/** Who is expected to close this item. Drives the self-service edit rights. */
function hr_onboarding_owner_roles(): array {
    return ['HR' => 'HR', 'MANAGER' => 'Manager', 'EMPLOYEE' => 'New Hire',
            'IT' => 'IT', 'ADMIN' => 'Admin'];
}

function hr_onboarding_plan_statuses(): array {
    return ['IN_PROGRESS' => 'In Progress', 'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled'];
}

function hr_onboarding_plan_status_badge(string $s): string {
    $map = ['IN_PROGRESS' => 'bg-primary', 'COMPLETED' => 'bg-success', 'CANCELLED' => 'bg-secondary'];
    $s = strtoupper(trim($s)) ?: 'IN_PROGRESS';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">'
        . esc(hr_onboarding_plan_statuses()[$s] ?? $s) . '</span>';
}

function hr_onboarding_task_statuses(): array {
    return ['PENDING' => 'Pending', 'IN_PROGRESS' => 'In Progress', 'DONE' => 'Done', 'SKIPPED' => 'Skipped'];
}

function hr_onboarding_task_status_badge(string $s): string {
    $map = ['PENDING' => 'bg-secondary', 'IN_PROGRESS' => 'bg-primary',
            'DONE' => 'bg-success', 'SKIPPED' => 'bg-dark'];
    $s = strtoupper(trim($s)) ?: 'PENDING';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">'
        . esc(hr_onboarding_task_statuses()[$s] ?? $s) . '</span>';
}

/* ---------------------------------------------------------------- cleaning ---- */

function hro_text(string $v, int $max): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hro_block(string $v, int $max = 5000): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace("/\r\n?/", "\n", trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hro_date(?string $v): string {
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

/** joining_date + n days, or '' when the base date is missing / unparseable. */
function hro_add_days(string $date, int $days): string {
    if ($date === '') return '';
    $t = strtotime($date);
    if ($t === false) return '';
    return date('Y-m-d', $t + ($days * 86400));
}

/* --------------------------------------------------------------- templates ---- */

function hr_onboarding_templates_all(bool $activeOnly = false): array {
    $sql = 'SELECT t.*, d.name AS department_name, b.name AS branch_name,
                   (SELECT COUNT(*) FROM hr_onboarding_template_tasks x WHERE x.template_id = t.id) AS task_count
            FROM hr_onboarding_templates t
            LEFT JOIN hr_departments d ON d.id = t.department_id
            LEFT JOIN hr_branches b ON b.id = t.branch_id'
        . ($activeOnly ? ' WHERE t.active=1' : '')
        . ' ORDER BY t.active DESC, t.name';
    return rows($sql);
}

function hr_onboarding_template(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_onboarding_templates WHERE id=?', [$id]) : null;
}

function hr_onboarding_template_save(?int $id, array $d, string $by): int {
    $name = hro_text((string) ($d['name'] ?? ''), 160);
    if ($name === '') return 0;
    $vals = [
        $name,
        hro_block((string) ($d['description'] ?? '')),
        hr_int_or_null($d['department_id'] ?? null),
        hr_int_or_null($d['branch_id'] ?? null),
        hro_text((string) ($d['employment_type'] ?? ''), 60),
        !empty($d['active']) ? 1 : 0,
    ];
    if ($id) {
        $vals[] = now_sql();
        $vals[] = $id;
        q('UPDATE hr_onboarding_templates SET name=?, description=?, department_id=?, branch_id=?,
           employment_type=?, active=?, updated_at=? WHERE id=?', $vals);
        return $id;
    }
    $vals[] = strtolower(trim($by));
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_onboarding_templates (name,description,department_id,branch_id,employment_type,active,
       created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)', $vals);
    return last_id();
}

function hr_onboarding_template_delete(int $id): void {
    q('DELETE FROM hr_onboarding_template_tasks WHERE template_id=?', [$id]);
    q('UPDATE hr_onboarding_plans SET template_id=NULL WHERE template_id=?', [$id]);
    q('DELETE FROM hr_onboarding_templates WHERE id=?', [$id]);
}

function hr_onboarding_template_tasks(int $templateId): array {
    return rows('SELECT * FROM hr_onboarding_template_tasks WHERE template_id=? ORDER BY sort_order ASC, id ASC',
        [$templateId]);
}

function hr_onboarding_template_task(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_onboarding_template_tasks WHERE id=?', [$id]) : null;
}

function hr_onboarding_template_task_save(?int $id, int $templateId, array $d): int {
    $title = hro_text((string) ($d['title'] ?? ''), 200);
    if ($title === '' || hr_onboarding_template($templateId) === null) return 0;
    $cat = strtoupper(trim((string) ($d['category'] ?? 'GENERAL')));
    if (!isset(hr_onboarding_categories()[$cat])) $cat = 'GENERAL';
    $role = strtoupper(trim((string) ($d['owner_role'] ?? 'HR')));
    if (!isset(hr_onboarding_owner_roles()[$role])) $role = 'HR';
    $offset = max(0, min(365, (int) ($d['due_offset_days'] ?? 0)));
    $sort = (int) ($d['sort_order'] ?? 0);
    if ($id) {
        q('UPDATE hr_onboarding_template_tasks SET title=?, category=?, owner_role=?, due_offset_days=?, sort_order=?
           WHERE id=?', [$title, $cat, $role, $offset, $sort, $id]);
        return $id;
    }
    q('INSERT INTO hr_onboarding_template_tasks (template_id,title,category,owner_role,due_offset_days,sort_order,
       created_at) VALUES (?,?,?,?,?,?,?)', [$templateId, $title, $cat, $role, $offset, $sort, now_sql()]);
    return last_id();
}

function hr_onboarding_template_task_delete(int $id): void {
    q('DELETE FROM hr_onboarding_template_tasks WHERE id=?', [$id]);
}

/* ------------------------------------------------------------------- plans ---- */

function hr_onboarding_plans(array $filters = []): array {
    $sql = 'SELECT p.*, t.name AS template_name,
                   (SELECT COUNT(*) FROM hr_onboarding_tasks x WHERE x.plan_id = p.id) AS task_count,
                   (SELECT COUNT(*) FROM hr_onboarding_tasks x WHERE x.plan_id = p.id
                        AND x.status IN (\'DONE\',\'SKIPPED\')) AS done_count
            FROM hr_onboarding_plans p
            LEFT JOIN hr_onboarding_templates t ON t.id = p.template_id';
    $where = []; $p = [];
    if (!empty($filters['status'])) { $where[] = 'p.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['email']))  { $where[] = 'p.email = ?'; $p[] = strtolower((string) $filters['email']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY p.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_onboarding_plan(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_onboarding_plans WHERE id=?', [$id]) : null;
}

/** The most recent plan for one person, or null. Used by the self-service view. */
function hr_onboarding_plan_by_email(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    return one('SELECT * FROM hr_onboarding_plans WHERE email=? ORDER BY id DESC LIMIT 1', [$email]);
}

/**
 * Create a plan and stamp out one task per template item. When the template is
 * 0 the plan starts empty; HR adds items by hand. The joining date anchors each
 * task's due date at joining + the item's offset.
 */
function hr_onboarding_plan_create(array $d, string $by): int {
    global $WF;
    $email = strtolower(hro_text((string) ($d['email'] ?? ''), 190));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    $templateId = hr_int_or_null($d['template_id'] ?? null);
    $template = $templateId ? hr_onboarding_template($templateId) : null;

    $fullName = hro_text((string) ($d['full_name'] ?? ''), 200);
    $joining  = hro_date($d['joining_date'] ?? null);
    if ($fullName === '') {
        $emp = employee_find_by_email($email);
        if ($emp !== null) $fullName = employee_full_name($emp);
    }
    $vals = [
        $email, $fullName, $templateId,
        hr_int_or_null($d['department_id'] ?? null),
        hr_int_or_null($d['branch_id'] ?? null),
        hro_text((string) ($d['designation'] ?? ''), 160),
        hro_text((string) ($d['employment_type'] ?? ($template['employment_type'] ?? '')), 60),
        $joining,
        hro_date($d['target_date'] ?? null),
        'IN_PROGRESS',
        now_sql(),
        strtolower(trim($by)),
        now_sql(),
        now_sql(),
    ];
    q('INSERT INTO hr_onboarding_plans (email,full_name,template_id,department_id,branch_id,designation,
       employment_type,joining_date,target_date,status,started_at,created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
    $planId = last_id();

    /* Onboarding notice to the new joiner once the plan exists. */
    hr_notify($email, 'ONBOARDING', 'Your onboarding has started',
        'An onboarding plan has been created for you' . ($fullName !== '' ? ', ' . $fullName : '') . '. Please check the onboarding checklist.',
        '/hr/onboarding/me');
    hr_notify_email($email, 'ONBOARDING', 'Your onboarding has started',
        'An onboarding plan has been created for you' . ($fullName !== '' ? ', ' . $fullName : '') . '. Please check your onboarding checklist in Folio.',
        '/hr/onboarding/me');

    if ($template !== null) {
        $sort = 0;
        foreach (hr_onboarding_template_tasks($templateId) as $t) {
            $sort++;
            $due = hro_add_days($joining, (int) ($t['due_offset_days'] ?? 0));
            q('INSERT INTO hr_onboarding_tasks (plan_id,title,category,owner_role,assignee_email,due_date,status,
               sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                $planId, (string) $t['title'], (string) $t['category'], (string) $t['owner_role'],
                strtoupper((string) $t['owner_role']) === 'EMPLOYEE' ? $email : '',
                $due, 'PENDING', $sort, now_sql(), now_sql(),
            ]);
        }
    }
    return $planId;
}

function hr_onboarding_plan_set_status(int $id, string $status, string $by): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_onboarding_plan_statuses()[$status])) return false;
    if (hr_onboarding_plan($id) === null) return false;
    $done = $status === 'COMPLETED' ? now_sql() : null;
    q('UPDATE hr_onboarding_plans SET status=?, completed_at=?, updated_at=? WHERE id=?',
        [$status, $done, now_sql(), $id]);
    activity_log(['email' => $by, 'role' => ''], 'HR_ONBOARDING_STATUS', 'Plan #' . $id . ' -> ' . $status);
    return true;
}

function hr_onboarding_plan_delete(int $id): void {
    q('DELETE FROM hr_onboarding_tasks WHERE plan_id=?', [$id]);
    q('DELETE FROM hr_onboarding_plans WHERE id=?', [$id]);
}

/* ------------------------------------------------------------------- tasks ---- */

function hr_onboarding_plan_tasks(int $planId): array {
    return rows('SELECT * FROM hr_onboarding_tasks WHERE plan_id=? ORDER BY sort_order ASC, id ASC', [$planId]);
}

function hr_onboarding_plan_task(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_onboarding_tasks WHERE id=?', [$id]) : null;
}

function hr_onboarding_plan_task_save(?int $id, int $planId, array $d, string $by): int {
    $title = hro_text((string) ($d['title'] ?? ''), 200);
    if ($title === '' || hr_onboarding_plan($planId) === null) return 0;
    $cat = strtoupper(trim((string) ($d['category'] ?? 'GENERAL')));
    if (!isset(hr_onboarding_categories()[$cat])) $cat = 'GENERAL';
    $role = strtoupper(trim((string) ($d['owner_role'] ?? 'HR')));
    if (!isset(hr_onboarding_owner_roles()[$role])) $role = 'HR';
    $status = strtoupper(trim((string) ($d['status'] ?? 'PENDING')));
    if (!isset(hr_onboarding_task_statuses()[$status])) $status = 'PENDING';
    $assignee = strtolower(hro_text((string) ($d['assignee_email'] ?? ''), 190));
    $due = hro_date($d['due_date'] ?? null);
    $notes = hro_block((string) ($d['notes'] ?? ''));
    $sort = (int) ($d['sort_order'] ?? 0);
    if ($id) {
        q('UPDATE hr_onboarding_tasks SET title=?, category=?, owner_role=?, assignee_email=?, due_date=?,
           status=?, notes=?, sort_order=?, updated_at=? WHERE id=?',
            [$title, $cat, $role, $assignee, $due, $status, $notes, $sort, now_sql(), $id]);
        return $id;
    }
    q('INSERT INTO hr_onboarding_tasks (plan_id,title,category,owner_role,assignee_email,due_date,status,notes,
       sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$planId, $title, $cat, $role, $assignee, $due, $status, $notes, $sort, now_sql(), now_sql()]);
    $newId = last_id();
    if ($status === 'DONE') {
        q('UPDATE hr_onboarding_tasks SET completed_by=?, completed_at=? WHERE id=?',
            [strtolower(trim($by)), now_sql(), $newId]);
    }
    return $newId;
}

/**
 * Move one task. A DONE/SKIPPED stamp records who closed it. After the move the
 * owning plan is re-evaluated: when every item is closed the plan completes.
 */
function hr_onboarding_plan_task_toggle(int $id, string $status, string $by): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_onboarding_task_statuses()[$status])) return false;
    $t = hr_onboarding_plan_task($id);
    if ($t === null) return false;
    $by = strtolower(trim($by));
    if ($status === 'DONE' || $status === 'SKIPPED') {
        q('UPDATE hr_onboarding_tasks SET status=?, completed_by=?, completed_at=?, updated_at=? WHERE id=?',
            [$status, $by, now_sql(), now_sql(), $id]);
    } else {
        q('UPDATE hr_onboarding_tasks SET status=?, completed_by=NULL, completed_at=NULL, updated_at=? WHERE id=?',
            [$status, now_sql(), $id]);
    }
    hr_onboarding_recheck_plan((int) $t['plan_id'], $by);
    return true;
}

function hr_onboarding_plan_task_delete(int $id): void {
    $t = hr_onboarding_plan_task($id);
    if ($t === null) return;
    q('DELETE FROM hr_onboarding_tasks WHERE id=?', [$id]);
    hr_onboarding_recheck_plan((int) $t['plan_id'], '');
}

/** Auto-complete a plan whose items are all closed; reopen when one is added. */
function hr_onboarding_recheck_plan(int $planId, string $by): void {
    $plan = hr_onboarding_plan($planId);
    if ($plan === null) return;
    $prog = hr_onboarding_plan_progress($planId);
    if ($prog['total'] > 0 && $prog['done'] >= $prog['total']) {
        if (strtoupper((string) $plan['status']) !== 'COMPLETED') {
            hr_onboarding_plan_set_status($planId, 'COMPLETED', $by);
        }
    } elseif (strtoupper((string) $plan['status']) === 'COMPLETED') {
        q('UPDATE hr_onboarding_plans SET status=?, completed_at=NULL, updated_at=? WHERE id=?',
            ['IN_PROGRESS', now_sql(), $planId]);
    }
}

function hr_onboarding_plan_progress(int $planId): array {
    $tasks = hr_onboarding_plan_tasks($planId);
    $total = count($tasks); $done = 0; $overdue = 0; $today = today();
    foreach ($tasks as $t) {
        $st = strtoupper((string) $t['status']);
        if ($st === 'DONE' || $st === 'SKIPPED') { $done++; continue; }
        $due = (string) ($t['due_date'] ?? '');
        if ($due !== '' && $due < $today) $overdue++;
    }
    $open = $total - $done;
    return [
        'total' => $total, 'done' => $done, 'open' => $open, 'overdue' => $overdue,
        'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
    ];
}

/* --------------------------------------------------------------- dashboard ---- */

/** Small counters for the HR dashboard, all guarded against an empty install. */
function hr_onboarding_stats(): array {
    try {
        $active = (int) val("SELECT COUNT(*) FROM hr_onboarding_plans WHERE status='IN_PROGRESS'");
        $completedMonth = (int) val("SELECT COUNT(*) FROM hr_onboarding_plans WHERE status='COMPLETED' AND completed_at LIKE ?",
            [date('Y-m') . '%']);
        $pending = (int) val("SELECT COUNT(*) FROM hr_onboarding_tasks t JOIN hr_onboarding_plans p ON p.id=t.plan_id
            WHERE p.status='IN_PROGRESS' AND t.status IN ('PENDING','IN_PROGRESS')");
        $overdue = (int) val("SELECT COUNT(*) FROM hr_onboarding_tasks t JOIN hr_onboarding_plans p ON p.id=t.plan_id
            WHERE p.status='IN_PROGRESS' AND t.status NOT IN ('DONE','SKIPPED') AND t.due_date<>'' AND t.due_date < ?",
            [today()]);
    } catch (Throwable $e) {
        return ['active_plans' => 0, 'completed_month' => 0, 'pending_tasks' => 0, 'overdue_tasks' => 0];
    }
    return ['active_plans' => $active, 'completed_month' => $completedMonth,
            'pending_tasks' => $pending, 'overdue_tasks' => $overdue];
}
