<?php
/**
 * WorkFolio PHP - HR module data layer (Phase 1).
 *
 * This file is additive: it owns the hr_* tables and never reads or writes any
 * table the rest of the app already depends on except `employees` (read-only, by
 * email) and `activity_log` (through activity_log()). The roster stays exactly as
 * it is; everything HR adds hangs off it keyed by email, the same way the HRM
 * form already does.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/tracks.php';   /* track_worked_seconds() - the real worked figure */

/* ------------------------------------------------------------------- seed ---- */

/**
 * One-time defaults: the five leave types the document names. Safe to call on
 * every request (static guard); it only inserts when the type list is empty.
 */
function hr_seed(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ((int) val('SELECT COUNT(*) FROM hr_leave_types') === 0) {
            $types = [
                ['Annual Leave', 'ANNUAL', 20, 1],
                ['Sick Leave', 'SICK', 10, 1],
                ['Casual Leave', 'CASUAL', 10, 1],
                ['Emergency Leave', 'EMERGENCY', 5, 1],
                ['Unpaid Leave', 'UNPAID', 0, 0],
            ];
            foreach ($types as $t) {
                q('INSERT INTO hr_leave_types (name,code,annual_quota,paid,active,created_at) VALUES (?,?,?,?,1,?)',
                  [$t[0], $t[1], $t[2], $t[3], now_sql()]);
            }
        }
    } catch (Throwable $e) {
        error_log('folio: hr_seed failed: ' . $e->getMessage());
    }
}

function hr_year(): int { return (int) date('Y'); }

/* --------------------------------------------------------------- org (lookups) */

function hr_branches_all(): array {
    return rows('SELECT * FROM hr_branches ORDER BY name');
}
function hr_branch(int $id): ?array { return one('SELECT * FROM hr_branches WHERE id=?', [$id]); }
function hr_branch_save(?int $id, string $name, string $city, string $address, string $phone): int {
    $name = trim($name);
    if ($name === '') return 0;
    if ($id) {
        q('UPDATE hr_branches SET name=?, city=?, address=?, phone=? WHERE id=?', [$name, $city, $address, $phone, $id]);
        return $id;
    }
    q('INSERT INTO hr_branches (name,city,address,phone,created_at) VALUES (?,?,?,?,?)', [$name, $city, $address, $phone, now_sql()]);
    return last_id();
}
function hr_branch_delete(int $id): void {
    q('UPDATE hr_employee_profiles SET branch_id=NULL WHERE branch_id=?', [$id]);
    q('DELETE FROM hr_branches WHERE id=?', [$id]);
}

function hr_departments_all(): array {
    return rows('SELECT * FROM hr_departments ORDER BY name');
}
function hr_department(int $id): ?array { return one('SELECT * FROM hr_departments WHERE id=?', [$id]); }
function hr_department_save(?int $id, string $name, string $headEmail): int {
    $name = trim($name);
    if ($name === '') return 0;
    if ($id) {
        q('UPDATE hr_departments SET name=?, head_email=? WHERE id=?', [$name, $headEmail, $id]);
        return $id;
    }
    q('INSERT INTO hr_departments (name,head_email,created_at) VALUES (?,?,?)', [$name, $headEmail, now_sql()]);
    return last_id();
}
function hr_department_delete(int $id): void {
    q('UPDATE hr_employee_profiles SET department_id=NULL WHERE department_id=?', [$id]);
    q('DELETE FROM hr_teams WHERE department_id=?', [$id]);
    q('DELETE FROM hr_departments WHERE id=?', [$id]);
}

function hr_teams_all(?int $departmentId = null): array {
    if ($departmentId !== null) return rows('SELECT * FROM hr_teams WHERE department_id=? ORDER BY name', [$departmentId]);
    return rows('SELECT * FROM hr_teams ORDER BY name');
}
function hr_team(?int $id): ?array { return $id ? one('SELECT * FROM hr_teams WHERE id=?', [$id]) : null; }
function hr_team_save(?int $id, string $name, ?int $deptId, string $leadEmail): int {
    $name = trim($name);
    if ($name === '') return 0;
    if ($id) {
        q('UPDATE hr_teams SET name=?, department_id=?, lead_email=? WHERE id=?', [$name, $deptId, $leadEmail, $id]);
        return $id;
    }
    q('INSERT INTO hr_teams (name,department_id,lead_email,created_at) VALUES (?,?,?,?)', [$name, $deptId, $leadEmail, now_sql()]);
    return last_id();
}
function hr_team_delete(int $id): void {
    q('UPDATE hr_employee_profiles SET team_id=NULL WHERE team_id=?', [$id]);
    q('DELETE FROM hr_teams WHERE id=?', [$id]);
}

/** id => name maps, one query each, for rendering tables without N+1 lookups. */
function hr_org_maps(): array {
    $out = ['branches' => [], 'departments' => [], 'teams' => []];
    foreach (rows('SELECT id,name FROM hr_branches') as $r) $out['branches'][(int) $r['id']] = (string) $r['name'];
    foreach (rows('SELECT id,name FROM hr_departments') as $r) $out['departments'][(int) $r['id']] = (string) $r['name'];
    foreach (rows('SELECT id,name FROM hr_teams') as $r) $out['teams'][(int) $r['id']] = (string) $r['name'];
    return $out;
}

/* ------------------------------------------------------------ employee profile */

function hr_profile(string $email): array {
    $email = strtolower(trim($email));
    $p = one('SELECT * FROM hr_employee_profiles WHERE email=?', [$email]);
    if ($p !== null) return $p;
    q('INSERT INTO hr_employee_profiles (email,employment_status,created_at,updated_at) VALUES (?,?,?,?)',
      [$email, 'ACTIVE', now_sql(), now_sql()]);
    return one('SELECT * FROM hr_employee_profiles WHERE email=?', [$email]) ?? ['email' => $email, 'employment_status' => 'ACTIVE'];
}

function hr_profile_save(string $email, array $d, string $by = ''): void {
    $email = strtolower(trim($email));
    hr_profile($email);
    q('UPDATE hr_employee_profiles SET employee_code=?, branch_id=?, department_id=?, team_id=?, manager_email=?,
        employment_type=?, employment_status=?, date_of_birth=?, gender=?, marital_status=?, cnic=?, contract_end=?, updated_at=?
        WHERE email=?', [
        trim((string) ($d['employee_code'] ?? '')),
        hr_int_or_null($d['branch_id'] ?? null),
        hr_int_or_null($d['department_id'] ?? null),
        hr_int_or_null($d['team_id'] ?? null),
        trim((string) ($d['manager_email'] ?? '')),
        trim((string) ($d['employment_type'] ?? '')),
        trim((string) ($d['employment_status'] ?? 'ACTIVE')) ?: 'ACTIVE',
        trim((string) ($d['date_of_birth'] ?? '')),
        trim((string) ($d['gender'] ?? '')),
        trim((string) ($d['marital_status'] ?? '')),
        trim((string) ($d['cnic'] ?? '')),
        trim((string) ($d['contract_end'] ?? '')),
        now_sql(),
        $email,
    ]);
}

function hr_int_or_null($v): ?int {
    if ($v === null || $v === '') return null;
    return (int) $v;
}

function hr_profiles_by_email(): array {
    $out = [];
    foreach (rows('SELECT * FROM hr_employee_profiles') as $r) $out[strtolower((string) $r['email'])] = $r;
    return $out;
}

/** Employment statuses the document lists, plus the ones HR needs day to day. */
function hr_employment_statuses(): array {
    return ['ACTIVE' => 'Active', 'PROBATION' => 'Probation', 'ON_LEAVE' => 'On Leave',
            'NOTICE' => 'Notice Period', 'RESIGNED' => 'Resigned', 'TERMINATED' => 'Terminated'];
}
function hr_employment_types(): array {
    return ['Permanent', 'Contract', 'Probation', 'Intern', 'Part-Time', 'Consultant'];
}
function hr_status_badge(string $s): string {
    $map = ['ACTIVE' => 'bg-success', 'PROBATION' => 'bg-info text-dark', 'ON_LEAVE' => 'bg-warning text-dark',
            'NOTICE' => 'bg-warning text-dark', 'RESIGNED' => 'bg-secondary', 'TERMINATED' => 'bg-danger'];
    $s = strtoupper(trim($s)) ?: 'ACTIVE';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc(str_replace('_', ' ', $s)) . '</span>';
}

/* ------------------------------------------------------------------- leave ---- */

function hr_leave_types_all(bool $activeOnly = false): array {
    $sql = 'SELECT * FROM hr_leave_types' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY name';
    return rows($sql);
}
function hr_leave_type(?int $id): ?array { return $id ? one('SELECT * FROM hr_leave_types WHERE id=?', [$id]) : null; }
function hr_leave_type_save(?int $id, string $name, string $code, float $quota, bool $paid, bool $active): int {
    $name = trim($name);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', trim($code)));
    if ($name === '' || $code === '') return 0;
    if ($id) {
        q('UPDATE hr_leave_types SET name=?, code=?, annual_quota=?, paid=?, active=? WHERE id=?',
          [$name, $code, $quota, $paid ? 1 : 0, $active ? 1 : 0, $id]);
        return $id;
    }
    q('INSERT INTO hr_leave_types (name,code,annual_quota,paid,active,created_at) VALUES (?,?,?,?,?,?)',
      [$name, $code, $quota, $paid ? 1 : 0, $active ? 1 : 0, now_sql()]);
    return last_id();
}
function hr_leave_type_delete(int $id): void {
    q('DELETE FROM hr_leave_requests WHERE leave_type_id=?', [$id]);
    q('DELETE FROM hr_leave_balances WHERE leave_type_id=?', [$id]);
    q('DELETE FROM hr_leave_types WHERE id=?', [$id]);
}

function hr_holidays_all(?int $year = null): array {
    $year = $year ?? hr_year();
    return rows('SELECT * FROM hr_holidays WHERE holiday_date LIKE ? ORDER BY holiday_date', [$year . '-%']);
}
function hr_holiday_save(?int $id, string $date, string $name, ?int $branchId): int {
    $date = trim($date); $name = trim($name);
    if ($date === '' || $name === '') return 0;
    if ($id) {
        q('UPDATE hr_holidays SET holiday_date=?, name=?, branch_id=? WHERE id=?', [$date, $name, $branchId, $id]);
        return $id;
    }
    q('INSERT INTO hr_holidays (holiday_date,name,branch_id,created_at) VALUES (?,?,?,?)', [$date, $name, $branchId, now_sql()]);
    return last_id();
}
function hr_holiday_delete(int $id): void { q('DELETE FROM hr_holidays WHERE id=?', [$id]); }

/** Holiday date set for working-day maths (all-branch only). */
function hr_holiday_set(?int $year = null): array {
    $out = [];
    foreach (hr_holidays_all($year) as $h) {
        if ((int) ($h['branch_id'] ?? 0) === 0) $out[(string) $h['holiday_date']] = (string) $h['name'];
    }
    return $out;
}

/**
 * Working days between two dates inclusive: weekends (Sat/Sun) and holidays are
 * not counted. This is the number of days a leave request consumes.
 */
function hr_leave_working_days(string $start, string $end): float {
    $s = strtotime($start); $e = strtotime($end);
    if ($s === false || $e === false || $e < $s) return 0.0;
    $holidays = hr_holiday_set();
    $days = 0.0;
    for ($t = $s; $t <= $e; $t += 86400) {
        $dow = (int) date('N', $t);
        if ($dow >= 6) continue;
        $d = date('Y-m-d', $t);
        if (isset($holidays[$d])) continue;
        $days += 1.0;
    }
    return $days;
}

function hr_leave_apply(string $email, int $typeId, string $start, string $end, string $reason, string $managerEmail): int {
    $days = hr_leave_working_days($start, $end);
    q('INSERT INTO hr_leave_requests (email,leave_type_id,start_date,end_date,days,reason,status,applied_at,manager_email,created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
      [strtolower(trim($email)), $typeId, $start, $end, $days, $reason, 'PENDING', now_sql(), $managerEmail, now_sql()]);
    $id = last_id();

    /* Pending-approval alert to the request's manager (when known) and HR. */
    $emp = employee_find_by_email($email);
    $name = $emp !== null ? employee_full_name($emp) : $email;
    $msg = $name . ' requested ' . $days . ' day(s) of leave from ' . $start . ' to ' . $end . '.';
    $link = '/hr/leave' . ($id ? '?id=' . $id : '');
    $mgr = strtolower(trim($managerEmail));
    if ($mgr !== '' && $id && (int) val("SELECT COUNT(*) FROM hr_notifications WHERE email=? AND type=? AND link=?",
        [$mgr, 'PENDING_APPROVAL', $link]) === 0) {
        hr_notify($mgr, 'PENDING_APPROVAL', 'Leave Request Pending', $msg, $link);
    }
    hr_notify_roles(['SUPER_ADMIN', 'ADMIN', 'HR'], 'PENDING_APPROVAL', 'Leave Request Pending', $msg, $link, $email);
    return $id;
}

function hr_leave_request(?int $id): ?array { return $id ? one('SELECT * FROM hr_leave_requests WHERE id=?', [$id]) : null; }

/**
 * List requests with the employee name/type joined in. $filters may carry
 * email, status and limit. Newest first.
 */
function hr_leave_requests(array $filters = []): array {
    $sql = 'SELECT r.*, t.name AS type_name, t.code AS type_code
            FROM hr_leave_requests r LEFT JOIN hr_leave_types t ON t.id = r.leave_type_id';
    $where = []; $p = [];
    if (!empty($filters['email'])) { $where[] = 'r.email = ?'; $p[] = strtolower((string) $filters['email']); }
    if (!empty($filters['status'])) { $where[] = 'r.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['branch_id']) || !empty($filters['department_id'])) {
        $where[] = 'LOWER(r.email) IN (SELECT LOWER(p.email) FROM hr_employee_profiles p WHERE 1=1'
            . (!empty($filters['branch_id']) ? ' AND p.branch_id = ?' : '')
            . (!empty($filters['department_id']) ? ' AND p.department_id = ?' : '') . ')';
        if (!empty($filters['branch_id'])) $p[] = (int) $filters['branch_id'];
        if (!empty($filters['department_id'])) $p[] = (int) $filters['department_id'];
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY r.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_leave_status_badge(string $s): string {
    $map = ['PENDING' => 'bg-warning text-dark', 'MANAGER_APPROVED' => 'bg-info text-dark',
            'APPROVED' => 'bg-success', 'REJECTED' => 'bg-danger', 'CANCELLED' => 'bg-secondary'];
    $s = strtoupper(trim($s));
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc(str_replace('_', ' ', $s)) . '</span>';
}

/**
 * Move a request through the two-step workflow. Returns an error string, or '' on
 * success. Balance is consumed only on the final APPROVED step.
 */
function hr_leave_decide(int $id, string $action, string $by, string $note): string {
    $r = hr_leave_request($id);
    if ($r === null) return 'Request not found.';
    $action = strtolower($action);
    $status = strtoupper((string) $r['status']);
    if (in_array($status, ['APPROVED', 'REJECTED', 'CANCELLED'], true)) return 'This request is already ' . strtolower($status) . '.';

    if ($action === 'reject') {
        q('UPDATE hr_leave_requests SET status=?, hr_email=?, hr_action_at=?, hr_note=? WHERE id=?',
          ['REJECTED', strtolower($by), now_sql(), $note, $id]);
        return '';
    }
    if ($action === 'approve') {
        q('UPDATE hr_leave_requests SET status=?, hr_email=?, hr_action_at=?, hr_note=? WHERE id=?',
          ['APPROVED', strtolower($by), now_sql(), $note, $id]);
        hr_leave_balance_apply((string) $r['email'], (int) $r['leave_type_id'], (float) $r['days'], (int) date('Y', strtotime((string) $r['start_date']) ?: time()));
        return '';
    }
    if ($action === 'manager') {
        q('UPDATE hr_leave_requests SET status=?, manager_email=?, manager_action_at=?, manager_note=? WHERE id=?',
          ['MANAGER_APPROVED', strtolower($by), now_sql(), $note, $id]);
        return '';
    }
    return 'Unknown action.';
}

function hr_leave_cancel(int $id, string $email): string {
    $r = hr_leave_request($id);
    if ($r === null) return 'Request not found.';
    if (strtolower((string) $r['email']) !== strtolower($email)) return 'That is not your request.';
    if (strtoupper((string) $r['status']) === 'APPROVED') return 'An approved request cannot be cancelled here; ask HR.';
    q('UPDATE hr_leave_requests SET status=?, cancelled_at=? WHERE id=?', ['CANCELLED', now_sql(), $id]);
    return '';
}

/* -------------------------------------------------- manager step (workflow) ---- */

/** True when at least one profile lists $email as its reporting manager. */
function hr_is_profiled_manager(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '') return false;
    return (int) val('SELECT COUNT(*) FROM hr_employee_profiles WHERE manager_email=?', [$email]) > 0;
}

/**
 * Leave requests awaiting THIS manager's first-step approval: rows whose
 * manager_email matches and that are still PENDING (stage 1). Optionally also
 * include MANAGER_APPROVED rows still waiting on HR (stage 2), so the manager
 * can follow their own team to the end.
 */
function hr_leave_manager_queue(string $email, bool $includeStage2 = false): array {
    $email = strtolower(trim($email));
    if ($email === '') return [];
    $statuses = $includeStage2
        ? "r.status IN ('PENDING','MANAGER_APPROVED')"
        : "r.status='PENDING'";
    return rows('SELECT r.*, t.name AS type_name, t.code AS type_code
                 FROM hr_leave_requests r LEFT JOIN hr_leave_types t ON t.id = r.leave_type_id
                 WHERE ' . $statuses . ' AND r.manager_email = ?
                 ORDER BY r.id DESC', [$email]);
}

/**
 * One-step decision by the reporting manager on a request that is theirs to
 * approve (stage 1: employee -> manager). HR/admins keep using hr_leave_decide()
 * for the final approval. Returns an error string, or '' on success.
 */
function hr_leave_manager_decide(int $id, string $action, string $by, string $note): string {
    $r = hr_leave_request($id);
    if ($r === null) return 'Request not found.';
    if (strtolower((string) $r['manager_email']) !== strtolower(trim($by))) return 'Only the reporting manager can take this step.';
    $status = strtoupper((string) $r['status']);
    if ($status !== 'PENDING') return 'This request is no longer pending manager approval.';
    $action = strtolower($action);
    if ($action === 'reject') {
        q('UPDATE hr_leave_requests SET status=?, manager_email=?, manager_action_at=?, manager_note=? WHERE id=?',
          ['REJECTED', strtolower($by), now_sql(), $note, $id]);
        return '';
    }
    if ($action === 'approve') {
        q('UPDATE hr_leave_requests SET status=?, manager_email=?, manager_action_at=?, manager_note=? WHERE id=?',
          ['MANAGER_APPROVED', strtolower($by), now_sql(), $note, $id]);
        return '';
    }
    return 'Unknown action.';
}

function hr_leave_balance(string $email, int $typeId, ?int $year = null): array {
    $year = $year ?? hr_year();
    $email = strtolower(trim($email));
    $b = one('SELECT * FROM hr_leave_balances WHERE email=? AND leave_type_id=? AND `year`=?', [$email, $typeId, $year]);
    if ($b !== null) return $b;
    $t = hr_leave_type($typeId);
    q('INSERT INTO hr_leave_balances (email,leave_type_id,`year`,entitled_days,used_days,updated_at) VALUES (?,?,?,?,0,?)',
      [$email, $typeId, $year, (float) ($t['annual_quota'] ?? 0), now_sql()]);
    return one('SELECT * FROM hr_leave_balances WHERE email=? AND leave_type_id=? AND `year`=?', [$email, $typeId, $year]);
}

function hr_leave_balances(string $email, ?int $year = null): array {
    $year = $year ?? hr_year();
    $email = strtolower(trim($email));
    $out = [];
    foreach (hr_leave_types_all() as $t) {
        $out[(int) $t['id']] = ['type' => $t, 'balance' => hr_leave_balance($email, (int) $t['id'], $year)];
    }
    return $out;
}

function hr_leave_balance_apply(string $email, int $typeId, float $days, int $year): void {
    $b = hr_leave_balance($email, $typeId, $year);
    if ($b !== null) q('UPDATE hr_leave_balances SET used_days=used_days+?, updated_at=? WHERE id=?', [$days, now_sql(), (int) $b['id']]);
}

/* ---------------------------------------------------------------- attendance ---- */

/** Default day: the office day starts 09:00 and ends 18:00 (configurable later). */
function hr_day_start(): string { return '09:00:00'; }
function hr_day_end(): string { return '18:00:00'; }

/**
 * Build (or refresh) one attendance day from the agent's track_records, unless HR
 * has set it manually (source=MANUAL is never overwritten).
 *
 * $force is for the places where track_records itself was just rewritten by hand
 * (Manual Update, History adjust): the owner's typed numbers are the truth, so
 * even a MANUAL row has to be brought in line with them - otherwise the HR
 * attendance grid, the reports and payroll keep showing the day as it was
 * before the edit.
 */
function hr_attendance_sync_day(string $email, string $date, bool $force = false): void {
    $email = strtolower(trim($email));
    $existing = one('SELECT * FROM hr_attendance_days WHERE email=? AND work_date=?', [$email, $date]);
    if (!$force && $existing !== null && (string) $existing['source'] === 'MANUAL') return;

    $tr = one('SELECT * FROM track_records WHERE email=? AND work_date=?', [$email, $date]);
    $status = 'ABSENT'; $in = null; $out = null; $worked = 0; $source = 'AGENT';
    if ($tr !== null && !empty($tr['check_in'])) {
        $in = (string) $tr['check_in'];
        $out = !empty($tr['check_out']) ? (string) $tr['check_out'] : null;
        /* Worked time is active + idle, the same figure every other screen shows.
           Reading active_seconds alone left the HR copy short by the idle time
           the employee spent on duty, and stale whenever the owner retyped the day. */
        $worked = track_worked_seconds($tr);
        $status = 'PRESENT';
        /* A day the SUPER_ADMIN typed by hand is a manual row from here on, so a
           later agent sync cannot quietly replace it again. */
        $source = track_manual_locked($tr) ? 'MANUAL' : 'AGENT';
    }
    if ($existing === null) {
        q('INSERT INTO hr_attendance_days (email,work_date,status,check_in,check_out,worked_seconds,source,updated_at)
           VALUES (?,?,?,?,?,?,?,?)',
          [$email, $date, $status, $in, $out, $worked, $source, now_sql()]);
    } else {
        q('UPDATE hr_attendance_days SET status=?, check_in=?, check_out=?, worked_seconds=?, source=?, updated_at=?
           WHERE email=? AND work_date=?',
          [$status, $in, $out, $worked, $source, now_sql(), $email, $date]);
    }
}

/** Sync every visible employee for a single day (used by the daily grid). */
function hr_attendance_sync_day_all(string $date, array $emails): void {
    foreach ($emails as $e) hr_attendance_sync_day((string) $e, $date);
}

function hr_attendance_day(string $email, string $date): array {
    hr_attendance_sync_day($email, $date);
    return one('SELECT * FROM hr_attendance_days WHERE email=? AND work_date=?', [strtolower(trim($email)), $date])
        ?? ['email' => $email, 'work_date' => $date, 'status' => 'ABSENT', 'worked_seconds' => 0];
}

/**
 * Recompute late/early/overtime from check-in/out against the standard day. Called
 * whenever a day is written, so a manual correction gets the same numbers.
 */
function hr_attendance_recompute(array $day): array {
    $in = (string) ($day['check_in'] ?? '');
    $out = (string) ($day['check_out'] ?? '');
    $late = 0; $early = 0; $ot = 0;
    $stdIn = strtotime(date('Y-m-d', strtotime($day['work_date'] ?? today()) ?: time()) . ' ' . hr_day_start());
    $stdOut = strtotime(date('Y-m-d', strtotime($day['work_date'] ?? today()) ?: time()) . ' ' . hr_day_end());
    $tin = $in !== '' ? strtotime($in) : false;
    $tout = $out !== '' ? strtotime($out) : false;
    if ($tin !== false && $tin > $stdIn) $late = (int) round(($tin - $stdIn) / 60);
    if ($tout !== false && $tout < $stdOut) $early = (int) round(($stdOut - $tout) / 60);
    if ($tout !== false && $tout > $stdOut) $ot = (int) round(($tout - $stdOut) / 60);
    return ['late' => max(0, $late), 'early' => max(0, $early), 'overtime' => max(0, $ot)];
}

/** HR manual entry/correction of one day. Everything gets recomputed here. */
function hr_attendance_save(string $email, string $date, array $d, string $by): void {
    $email = strtolower(trim($email));
    $status = strtoupper(trim((string) ($d['status'] ?? 'PRESENT'))) ?: 'PRESENT';
    $in = trim((string) ($d['check_in'] ?? ''));
    $out = trim((string) ($d['check_out'] ?? ''));
    $worked = (int) ($d['worked_seconds'] ?? 0);
    if ($worked <= 0 && $in !== '' && $out !== '') {
        $a = strtotime($in); $b = strtotime($out);
        if ($a !== false && $b !== false && $b > $a) $worked = $b - $a;
    }
    $calc = hr_attendance_recompute(['work_date' => $date, 'check_in' => $in, 'check_out' => $out]);
    $existing = one('SELECT id FROM hr_attendance_days WHERE email=? AND work_date=?', [$email, $date]);
    if ($existing === null) {
        q('INSERT INTO hr_attendance_days (email,work_date,status,check_in,check_out,worked_seconds,late_minutes,early_minutes,overtime_minutes,source,note,updated_by,updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$email, $date, $status, $in ?: null, $out ?: null, $worked, $calc['late'], $calc['early'], $calc['overtime'], 'MANUAL', (string) ($d['note'] ?? ''), strtolower($by), now_sql()]);
    } else {
        q('UPDATE hr_attendance_days SET status=?, check_in=?, check_out=?, worked_seconds=?, late_minutes=?, early_minutes=?, overtime_minutes=?, source=?, note=?, updated_by=?, updated_at=?
           WHERE email=? AND work_date=?',
          [$status, $in ?: null, $out ?: null, $worked, $calc['late'], $calc['early'], $calc['overtime'], 'MANUAL', (string) ($d['note'] ?? ''), strtolower($by), now_sql(), $email, $date]);
    }

    /* Late-arrival alert: once per date per employee, only when actually late. */
    if ($calc['late'] > 0) {
        $link = '/hr/attendance?late=' . $date;
        if ((int) val('SELECT COUNT(*) FROM hr_notifications WHERE email=? AND type=? AND link=?', [$email, 'LATE_ARRIVAL', $link]) === 0) {
            $emp = employee_find_by_email($email);
            $name = $emp !== null ? employee_full_name($emp) : $email;
            $msg = $name . ' was ' . $calc['late'] . ' minute(s) late on ' . $date . '.';
            hr_notify($email, 'LATE_ARRIVAL', 'Late Arrival', $msg, $link);
            hr_notify_email($email, 'LATE_ARRIVAL', 'Late Arrival', $msg, $link);
        }
    }
}

/** Monthly attendance rows for one employee. */
function hr_attendance_month(string $email, string $month): array {
    $email = strtolower(trim($email));
    return rows('SELECT * FROM hr_attendance_days WHERE email=? AND work_date LIKE ? ORDER BY work_date', [$email, $month . '-%']);
}

/** All employees' attendance rows for one date, joined to the roster + profile. */
function hr_attendance_by_date(string $date): array {
    return rows('SELECT a.*, e.first_name, e.last_name, p.department_id, p.branch_id
                 FROM hr_attendance_days a
                 JOIN employees e ON LOWER(e.email) = a.email
                 LEFT JOIN hr_employee_profiles p ON LOWER(p.email) = a.email
                 WHERE a.work_date=? ORDER BY e.first_name, e.last_name', [$date]);
}

function hr_attendance_status_badge(string $s): string {
    $map = ['PRESENT' => 'bg-success', 'ABSENT' => 'bg-danger', 'HALF_DAY' => 'bg-warning text-dark',
            'LEAVE' => 'bg-info text-dark', 'HOLIDAY' => 'bg-primary', 'WFH' => 'bg-secondary', 'WEEKEND' => 'bg-secondary'];
    $s = strtoupper(trim($s)) ?: 'ABSENT';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc(str_replace('_', ' ', $s)) . '</span>';
}

function hr_attendance_corrections(array $filters = []): array {
    $sql = 'SELECT c.*, e.first_name, e.last_name FROM hr_attendance_corrections c
            LEFT JOIN employees e ON LOWER(e.email) = c.email';
    $where = []; $p = [];
    if (!empty($filters['email'])) { $where[] = 'c.email=?'; $p[] = strtolower((string) $filters['email']); }
    if (!empty($filters['status'])) { $where[] = 'c.status=?'; $p[] = strtoupper((string) $filters['status']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY c.id DESC';
    return rows($sql, $p);
}

function hr_attendance_correction_request(string $email, string $date, string $in, string $out, string $reason): int {
    q('INSERT INTO hr_attendance_corrections (email,work_date,requested_check_in,requested_check_out,reason,status,created_at)
       VALUES (?,?,?,?,?,?,?)', [strtolower(trim($email)), $date, $in, $out, $reason, 'PENDING', now_sql()]);
    $id = last_id();
    $emp = employee_find_by_email($email);
    $name = $emp !== null ? employee_full_name($emp) : $email;
    $msg = $name . ' requested an attendance correction for ' . $date . '.';
    $link = '/hr/attendance?correction=' . $id;
    hr_notify_roles(['SUPER_ADMIN', 'ADMIN', 'HR'], 'PENDING_APPROVAL', 'Correction Request Pending', $msg, $link, $email);
    return $id;
}

function hr_attendance_correction_decide(int $id, bool $approve, string $by, string $note): string {
    $c = one('SELECT * FROM hr_attendance_corrections WHERE id=?', [$id]);
    if ($c === null) return 'Correction not found.';
    if (strtoupper((string) $c['status']) !== 'PENDING') return 'Already reviewed.';
    if ($approve) {
        hr_attendance_save((string) $c['email'], (string) $c['work_date'], [
            'status' => 'PRESENT',
            'check_in' => (string) $c['requested_check_in'],
            'check_out' => (string) $c['requested_check_out'],
            'note' => 'Corrected from request #' . $id,
        ], $by);
        q('UPDATE hr_attendance_corrections SET status=?, reviewed_by=?, reviewed_at=?, review_note=? WHERE id=?',
          ['APPROVED', strtolower($by), now_sql(), $note, $id]);
        return '';
    }
    q('UPDATE hr_attendance_corrections SET status=?, reviewed_by=?, reviewed_at=?, review_note=? WHERE id=?',
      ['REJECTED', strtolower($by), now_sql(), $note, $id]);
    return '';
}

/* --------------------------------------------------------------- notifications -- */

function hr_notify(string $email, string $type, string $title, string $body, string $link = '', string $actor = ''): void {
    try {
        q('INSERT INTO hr_notifications (email,type,title,body,link,actor_email,created_at) VALUES (?,?,?,?,?,?,?)',
          [strtolower(trim($email)), $type, $title, $body, $link, strtolower(trim($actor)), now_sql()]);
    } catch (Throwable $e) {
        error_log('folio: hr_notify failed: ' . $e->getMessage());
    }
}

/** Notify every employee whose role is in the list. Used for HR-wide alerts. */
function hr_notify_roles(array $roles, string $type, string $title, string $body, string $link = '', string $actor = ''): void {
    $in = implode(',', array_fill(0, count($roles), '?'));
    foreach (rows('SELECT email FROM employees WHERE `role` IN (' . $in . ')', $roles) as $e) {
        hr_notify((string) $e['email'], $type, $title, $body, $link, $actor);
    }
}

function hr_notifications(string $email, int $limit = 30, bool $unreadOnly = false): array {
    $sql = 'SELECT * FROM hr_notifications WHERE email=?';
    $p = [strtolower(trim($email))];
    if ($unreadOnly) $sql .= ' AND read_at IS NULL';
    $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
    return rows($sql, $p);
}

function hr_notifications_unread_count(string $email): int {
    return (int) val('SELECT COUNT(*) FROM hr_notifications WHERE email=? AND read_at IS NULL', [strtolower(trim($email))]);
}

function hr_notification_read(int $id, string $email): void {
    q('UPDATE hr_notifications SET read_at=? WHERE id=? AND email=? AND read_at IS NULL',
      [now_sql(), $id, strtolower(trim($email))]);
}

function hr_notifications_read_all(string $email): void {
    q('UPDATE hr_notifications SET read_at=? WHERE email=? AND read_at IS NULL', [now_sql(), strtolower(trim($email))]);
}

/* ------------------------------------------------------- notification email ---- */

/**
 * Best-effort email for a notification. No-ops silently when outgoing mail is
 * not configured, when the recipient is not an address, or when mailer.php's
 * helpers are unavailable. Never throws.
 */
function hr_notify_email(string $email, string $type, string $title, string $body, string $link = ''): void {
    if (!function_exists('mail_ready') || !function_exists('mail_send')) {
        $f = __DIR__ . '/mailer.php';
        if (is_file($f)) @require_once $f;
    }
    if (!function_exists('mail_ready') || !function_exists('mail_send')) return;
    try {
        if (!mail_ready()) return;
        $to = mail_addr_safe($email);
        if ($to === '') return;
        $subject = '[' . $type . '] ' . $title;
        $text = $body . ($link !== '' ? "\n\n" . base_url($link) : '');
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6">'
            . '<h3>' . esc($title) . '</h3>'
            . '<p>' . nl2br(esc($body)) . '</p>'
            . ($link !== '' ? '<p><a href="' . esc(base_url($link)) . '">Open in Folio</a></p>' : '')
            . '</div>';
        mail_send($to, $subject, $text, $html);
    } catch (Throwable $e) {
        error_log('folio: hr_notify_email failed: ' . $e->getMessage());
    }
}

/* ----------------------------------------------------------- daily digest ---- */

/**
 * Runs once per day (guarded by a DIGEST marker row). Fires the date-based
 * triggers: birthdays, work anniversaries, tasks due or overdue, contracts
 * expiring within 60 days, and onboarding tasks due today. Each recipient is
 * notified at most once per event (dedupe by type + link).
 */
function hr_notifications_daily(): void {
    try {
        $today = today();
        $md = substr($today, 5);
        if (val("SELECT COUNT(*) FROM hr_notifications WHERE email='__system__' AND type='DIGEST' AND title=?", [$today]) > 0) return;

        /* birthdays today (profiles.date_of_birth YYYY-MM-DD -> MM-DD) */
        foreach (rows("SELECT email, date_of_birth FROM hr_employee_profiles WHERE date_of_birth<>'' AND SUBSTR(date_of_birth,6)=?", [$md]) as $p) {
            $emp = employee_find_by_email((string) $p['email']);
            $name = $emp !== null ? employee_full_name($emp) : (string) $p['email'];
            $msg = 'Wishing you a great birthday today, ' . $name . '.';
            hr_notify((string) $p['email'], 'BIRTHDAY', 'Happy Birthday!', $msg, '/hr/me');
            hr_notify_email((string) $p['email'], 'BIRTHDAY', 'Happy Birthday!', $msg, '/hr/me');
        }

        /* work anniversaries today (employees.join_date YYYY-MM-DD -> MM-DD) */
        foreach (rows("SELECT email, join_date FROM employees WHERE join_date<>'' AND SUBSTR(join_date,6)=?", [$md]) as $e) {
            $emp = employee_find_by_email((string) $e['email']);
            $name = $emp !== null ? employee_full_name($emp) : (string) $e['email'];
            $years = max(1, (int) date('Y') - (int) substr((string) $e['join_date'], 0, 4));
            $msg = 'Congratulations on ' . $years . ' year' . ($years === 1 ? '' : 's') . ' with us, ' . $name . '.';
            hr_notify((string) $e['email'], 'ANNIVERSARY', 'Work Anniversary', $msg, '/hr/me');
            hr_notify_email((string) $e['email'], 'ANNIVERSARY', 'Work Anniversary', $msg, '/hr/me');
        }

        /* tasks due today + overdue (open statuses only) */
        foreach (rows("SELECT id,title,due_date,assignee_email FROM hr_tasks
                       WHERE status IN ('TODO','IN_PROGRESS','REVIEW') AND due_date<>'' AND due_date<=?
                       ORDER BY due_date", [$today]) as $t) {
            $assignee = strtolower(trim((string) $t['assignee_email']));
            if ($assignee === '') continue;
            $due = (string) $t['due_date'] === $today;
            $type = $due ? 'TASK_DUE' : 'TASK_OVERDUE';
            $link = '/hr/tasks/' . (int) $t['id'];
            if ((int) val('SELECT COUNT(*) FROM hr_notifications WHERE email=? AND type=? AND link=?', [$assignee, $type, $link]) > 0) continue;
            $title = $due ? 'Task Due Today' : 'Task Overdue';
            $msg = $due
                ? 'Your task "' . (string) $t['title'] . '" is due today.'
                : 'Your task "' . (string) $t['title'] . '" was due on ' . (string) $t['due_date'] . ' and is overdue.';
            hr_notify($assignee, $type, $title, $msg, $link);
            hr_notify_email($assignee, $type, $title, $msg, $link);
        }

        /* contracts ending within the next 60 days */
        $cut = date('Y-m-d', strtotime('+60 days'));
        foreach (rows("SELECT email, contract_end FROM hr_employee_profiles
                       WHERE contract_end<>'' AND contract_end>=? AND contract_end<=?", [$today, $cut]) as $p) {
            $link = '/hr/me';
            if ((int) val('SELECT COUNT(*) FROM hr_notifications WHERE email=? AND type=? AND link=?', [(string) $p['email'], 'CONTRACT_EXPIRY', $link]) > 0) continue;
            $msg = 'Your employment contract ends on ' . (string) $p['contract_end'] . '. Please discuss renewal with HR.';
            hr_notify((string) $p['email'], 'CONTRACT_EXPIRY', 'Contract Expiring Soon', $msg, $link);
            hr_notify_email((string) $p['email'], 'CONTRACT_EXPIRY', 'Contract Expiring Soon', $msg, $link);
        }

        /* onboarding tasks due today -> notify the plan owner */
        foreach (rows("SELECT p.email AS owner, t.title FROM hr_onboarding_tasks t
                       JOIN hr_onboarding_plans p ON p.id=t.plan_id
                       WHERE t.status IN ('PENDING','IN_PROGRESS') AND t.due_date<>'' AND t.due_date=?",
                        [$today]) as $o) {
            hr_notify((string) $o['owner'], 'ONBOARDING', 'Onboarding Task Due',
                'Your onboarding task "' . (string) $o['title'] . '" is due today.', '/hr/onboarding/me');
        }

        /* mark the run done */
        q("INSERT INTO hr_notifications (email,type,title,body,link,actor_email,created_at)
           VALUES ('__system__','DIGEST',?,?,?,?,?)", [$today, 'Daily notification digest', '', '', now_sql()]);
    } catch (Throwable $e) {
        error_log('folio: hr_notifications_daily failed: ' . $e->getMessage());
    }
}

function hr_announcements(int $limit = 20): array {
    return rows('SELECT * FROM hr_announcements ORDER BY id DESC LIMIT ' . (int) $limit);
}

function hr_announcement_save(string $title, string $body, string $audience, ?int $branchId, ?int $deptId, string $by): int {
    q('INSERT INTO hr_announcements (title,body,audience,branch_id,department_id,created_by,created_at) VALUES (?,?,?,?,?,?,?)',
      [$title, $body, $audience, $branchId, $deptId, strtolower(trim($by)), now_sql()]);
    return last_id();
}

/* ----------------------------------------------------------------- dashboard ---- */

/**
 * Every number the HR dashboard shows, computed in one place. Attendance cards
 * are judged against today; "on leave" against approved leave covering today.
 */
/**
 * Dashboard headline numbers. Optional filters narrow the employee-scoped
 * figures (headcount, attendance, leave, birthdays, contracts) to a branch,
 * a department and/or a specific date; module counters that are not tied to a
 * single person are returned unfiltered.
 */
function hr_dashboard_stats(array $filters = []): array {
    $today = (string) ($filters['date'] ?? today());
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) $today = today();
    $branch = (int) ($filters['branch_id'] ?? 0);
    $dept = (int) ($filters['department_id'] ?? 0);

    /* Employee-scope WHERE fragments for the profile junction. */
    $scope = '';
    $args = [];
    if ($branch > 0) { $scope .= ' AND p.branch_id = ?'; $args[] = $branch; }
    if ($dept > 0) { $scope .= ' AND p.department_id = ?'; $args[] = $dept; }
    $profEmails = function ($innerSql) use ($scope, $args): array {
        $sql = 'SELECT DISTINCT LOWER(p.email) AS email FROM hr_employee_profiles p WHERE 1=1' . $scope;
        $sql .= $scope !== '' ? '' : ' AND p.email IS NOT NULL';
        return array_column(rows($sql, $args), 'email');
    };
    $inScoped = function (array $list): string {
        $ok = [];
        foreach ($list as $e) { $ok[] = strtolower(trim((string) $e)); }
        if (!$ok) return 'IN (NULL)';
        $ph = rtrim(str_repeat('?,', count($ok)), ',');
        return 'IN (' . $ph . ')';
    };

    $total = (int) val('SELECT COUNT(*) FROM employees');
    $active = (int) val("SELECT COUNT(*) FROM hr_employee_profiles WHERE employment_status IN ('ACTIVE','PROBATION')");
    $present = (int) val("SELECT COUNT(*) FROM hr_attendance_days WHERE work_date=? AND status IN ('PRESENT','WFH','HALF_DAY')", [$today]);
    $absent = (int) val("SELECT COUNT(*) FROM hr_attendance_days WHERE work_date=? AND status='ABSENT'", [$today]);
    $late = (int) val('SELECT COUNT(*) FROM hr_attendance_days WHERE work_date=? AND late_minutes>0', [$today]);
    $onLeave = (int) val("SELECT COUNT(*) FROM hr_leave_requests WHERE status='APPROVED' AND start_date<=? AND end_date>=?", [$today, $today]);
    $newJoiners = (int) val('SELECT COUNT(*) FROM employees WHERE join_date >= ?', [date('Y-m-d', strtotime('-30 days'))]);
    $pendingLeave = (int) val("SELECT COUNT(*) FROM hr_leave_requests WHERE status IN ('PENDING','MANAGER_APPROVED')");
    $pendingCorrections = (int) val("SELECT COUNT(*) FROM hr_attendance_corrections WHERE status='PENDING'");

    /* Apply branch/department scope to the person-scoped totals. */
    if ($branch > 0 || $dept > 0) {
        $scoped = $profEmails('');
        $inS = $inScoped($scoped);
        $total   = (int) val('SELECT COUNT(*) FROM employees e INNER JOIN hr_employee_profiles p ON LOWER(p.email)=LOWER(e.email) WHERE 1=1' . $scope, $args);
        $active  = (int) val('SELECT COUNT(*) FROM hr_employee_profiles p WHERE p.employment_status IN (?,?)' . $scope, array_merge(['ACTIVE', 'PROBATION'], $args));
        $present = (int) val('SELECT COUNT(*) FROM hr_attendance_days a WHERE a.work_date=? AND a.status IN (?,?,?) AND LOWER(a.email) ' . $inS, array_merge([$today, 'PRESENT', 'WFH', 'HALF_DAY'], $scoped));
        $absent  = (int) val('SELECT COUNT(*) FROM hr_attendance_days a WHERE a.work_date=? AND a.status=? AND LOWER(a.email) ' . $inS, array_merge([$today, 'ABSENT'], $scoped));
        $late    = (int) val('SELECT COUNT(*) FROM hr_attendance_days a WHERE a.work_date=? AND a.late_minutes>0 AND LOWER(a.email) ' . $inS, array_merge([$today], $scoped));
        $onLeave = (int) val("SELECT COUNT(*) FROM hr_leave_requests l WHERE l.status='APPROVED' AND l.start_date<=? AND l.end_date>=? AND LOWER(l.email) " . $inS, array_merge([$today, $today], $scoped));
        $newJoiners = (int) val('SELECT COUNT(*) FROM employees e INNER JOIN hr_employee_profiles p ON LOWER(p.email)=LOWER(e.email) WHERE e.join_date >= ? AND 1=1' . $scope, array_merge([date('Y-m-d', strtotime('-30 days'))], $args));
        $pendingLeave = (int) val("SELECT COUNT(*) FROM hr_leave_requests l WHERE l.status IN (?,?) AND LOWER(l.email) " . $inS, array_merge(['PENDING', 'MANAGER_APPROVED'], $scoped));
        $pendingCorrections = (int) val("SELECT COUNT(*) FROM hr_attendance_corrections c WHERE c.status=? AND LOWER(c.email) " . $inS, array_merge(['PENDING'], $scoped));
    }

    /* Vacancies now come from the ATS (Phase 2); guard in case the recruitment
       layer is not loaded, and still tolerate the table being absent. */
    $openVacancies = function_exists('hr_openings_count_open') ? hr_openings_count_open() : 0;
    if ($openVacancies === 0) {
        try { $openVacancies = (int) val("SELECT COUNT(*) FROM hr_job_openings WHERE status='OPEN'"); } catch (Throwable $e) {}
    }
    /* Onboarding plans in flight, from the Phase 2 onboarding layer. */
    $onboardingActive = 0;
    if (function_exists('hr_onboarding_stats')) {
        try { $onboardingActive = (int) (hr_onboarding_stats()['active_plans'] ?? 0); } catch (Throwable $e) {}
    }

    $birthdayMonth = (int) date('n');
    $birthdays = 0; $anniversaries = 0;
    $scopedEmails = ($branch > 0 || $dept > 0) ? array_map('strtolower', $profEmails('')) : null;
    $birthRowSql = 'SELECT date_of_birth, email FROM hr_employee_profiles WHERE date_of_birth<>?';
    foreach (rows($birthRowSql, ['']) as $p) {
        $dob = (string) ($p['date_of_birth'] ?? '');
        if ($scopedEmails !== null && !in_array(strtolower((string) ($p['email'] ?? '')), $scopedEmails, true)) continue;
        if ($dob !== '' && (int) date('n', strtotime($dob) ?: 0) === $birthdayMonth) $birthdays++;
    }
    foreach (rows('SELECT join_date, email FROM employees') as $e) {
        $jd = (string) ($e['join_date'] ?? '');
        if ($scopedEmails !== null && !in_array(strtolower((string) ($e['email'] ?? '')), $scopedEmails, true)) continue;
        if ($jd !== '' && (int) date('n', strtotime($jd) ?: 0) === $birthdayMonth) $anniversaries++;
    }
    $expiringDocs = 0;
    if (function_exists('hr_documents_expiry_count')) {
        try { $expiringDocs = hr_documents_expiry_count(60); } catch (Throwable $e) {}
    }
    $pendingHrActions = $pendingLeave + $pendingCorrections;
    /* Performance reviews awaiting action, from the Phase 2 performance layer. */
    $pendingReviews = 0;
    if (function_exists('hr_performance_stats')) {
        try { $pendingReviews = (int) (hr_performance_stats()['pending_reviews'] ?? 0); } catch (Throwable $e) {}
    }
    /* Tasks assigned across the roster, from the Phase 2 tasks layer. */
    $openTasks = 0;
    if (function_exists('hr_tasks_stats')) {
        try { $openTasks = (int) (hr_tasks_stats()['open'] ?? 0); } catch (Throwable $e) {}
    }
    /* Offboarding plans in flight, from the Phase 3 offboarding layer. */
    $offboardingActive = 0;
    if (function_exists('hr_offboarding_stats')) {
        try { $offboardingActive = (int) (hr_offboarding_stats()['active_plans'] ?? 0); } catch (Throwable $e) {}
    }
    /* Assets assigned across the roster, from the Phase 3 assets layer. */
    $assetsAssigned = 0;
    if (function_exists('hr_assets_stats')) {
        try { $assetsAssigned = (int) (hr_assets_stats()['assigned'] ?? 0); } catch (Throwable $e) {}
    }
    /* Employees on payroll, from the Phase 3 payroll layer. */
    $payrollAssigned = 0;
    if (function_exists('hr_payroll_stats')) {
        try { $payrollAssigned = (int) (hr_payroll_stats()['assigned'] ?? 0); } catch (Throwable $e) {}
    }
    $pendingHrActions = $pendingLeave + $pendingCorrections + $pendingReviews;
    /* Contracts ending within the next 60 days. The date lives on the profile
       (contract_end); guarded because the column is added by migration. */
    $expiringContracts = 0;
    try {
        $sql = "SELECT COUNT(*) FROM hr_employee_profiles p
                  WHERE p.contract_end IS NOT NULL AND p.contract_end <> ''
                    AND p.contract_end >= ? AND p.contract_end <= ?" . $scope;
        $expiringContracts = (int) val($sql, array_merge([$today, date('Y-m-d', strtotime('+60 days'))], $args));
    } catch (Throwable $e) {}

    return [
        'total' => $total, 'active' => $active, 'present' => $present, 'absent' => $absent,
        'late' => $late, 'on_leave' => $onLeave, 'new_joiners' => $newJoiners,
        'pending_leave' => $pendingLeave, 'pending_corrections' => $pendingCorrections,
        'open_vacancies' => $openVacancies, 'onboarding_active' => $onboardingActive,
        'birthdays' => $birthdays,
        'anniversaries' => $anniversaries, 'expiring_docs' => $expiringDocs,
        'pending_reviews' => $pendingReviews, 'open_tasks' => $openTasks,
        'offboarding_active' => $offboardingActive, 'assets_assigned' => $assetsAssigned,
        'payroll_assigned' => $payrollAssigned,
        'pending_hr_actions' => $pendingHrActions,
        'expiring_contracts' => $expiringContracts,
    ];
}
