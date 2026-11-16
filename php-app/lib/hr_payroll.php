<?php
/**
 * WorkFolio PHP - HR module data layer: Payroll.
 *
 * Additive like the other HR layers. A salary *structure* is a reusable pay
 * template; *assignments* say which structure each employee draws on. A monthly
 * *run* snapshots one payslip per assigned employee so later edits to a
 * structure never rewrite history. Amounts are kept as strings, matching the
 * rest of the schema (SQLite has no DECIMAL); callers format them.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */

/* ---------------------------------------------------------------- lookups ---- */

function hr_payroll_run_statuses(): array {
    return ['DRAFT' => 'Draft', 'PROCESSED' => 'Processed', 'PAID' => 'Paid', 'CANCELLED' => 'Cancelled'];
}

function hr_payroll_run_status_label(string $s): string {
    return hr_payroll_run_statuses()[strtoupper(trim($s))] ?? 'Draft';
}

function hr_payroll_status_badge(string $s): string {
    $map = ['DRAFT' => 'bg-secondary', 'PROCESSED' => 'bg-primary', 'PAID' => 'bg-success', 'CANCELLED' => 'bg-dark'];
    $s = strtoupper(trim($s)) ?: 'DRAFT';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc(hr_payroll_run_status_label($s)) . '</span>';
}

/* ---------------------------------------------------------------- cleaning ---- */

function hp_text(string $v, int $max): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hp_money($v): string {
    if (is_string($v)) $v = preg_replace('/[^0-9.\-]/', '', $v);
    if (!is_numeric($v)) return '0';
    return number_format((float) $v, 2, '.', '');
}

function hp_percent($v): string {
    if (is_string($v)) $v = preg_replace('/[^0-9.\-]/', '', $v);
    if (!is_numeric($v)) return '0';
    $f = max(0.0, min(100.0, (float) $v));
    return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.') ?: '0';
}

function hp_period(?string $v): string {
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}$/', $v) ? $v : '';
}

/* ------------------------------------------------------------ structures ---- */

function hr_payroll_structures(bool $activeOnly = false): array {
    try {
        $sql = 'SELECT * FROM hr_payroll_structures' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY name ASC';
        return rows($sql);
    } catch (Throwable $e) {
        return [];
    }
}

function hr_payroll_structure(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_payroll_structures WHERE id=?', [$id]) : null;
}

function hr_payroll_structure_map(): array {
    $m = [];
    foreach (hr_payroll_structures() as $s) $m[(int) $s['id']] = (string) $s['name'];
    return $m;
}

/** Break a structure row into gross / allowances / deductions / tax for an amount. */
function hr_payroll_breakdown(array $s): array {
    $basic = (float) $s['basic'];
    $rent = (float) $s['house_rent'];
    $med = (float) $s['medical'];
    $trans = (float) $s['transport'];
    $other = (float) $s['other_allowance'];
    $allowances = $rent + $med + $trans + $other;
    $gross = $basic + $allowances;
    $tax = round($gross * (float) $s['tax_percent'] / 100, 2);
    $eobi = (float) $s['eobi'];
    $net = $gross - $tax - $eobi;
    return [
        'basic' => $basic, 'allowances' => $allowances, 'gross' => $gross,
        'tax' => $tax, 'deductions' => $eobi, 'net' => $net,
    ];
}

function hr_payroll_structure_save(?int $id, array $d, string $by): int {
    $name = hp_text((string) ($d['name'] ?? ''), 160);
    if ($name === '') return 0;
    $vals = [
        $name, hp_money($d['basic'] ?? 0), hp_money($d['house_rent'] ?? 0),
        hp_money($d['medical'] ?? 0), hp_money($d['transport'] ?? 0),
        hp_money($d['other_allowance'] ?? 0), hp_percent($d['tax_percent'] ?? 0),
        hp_money($d['eobi'] ?? 0), hp_money($d['lwp_day_rate'] ?? 0), !empty($d['active']) ? 1 : 0,
    ];
    if ($id) {
        if (hr_payroll_structure($id) === null) return 0;
        q('UPDATE hr_payroll_structures SET name=?,basic=?,house_rent=?,medical=?,transport=?,other_allowance=?,
           tax_percent=?,eobi=?,lwp_day_rate=?,active=?,updated_at=? WHERE id=?',
            array_merge($vals, [now_sql(), $id]));
        return $id;
    }
    q('INSERT INTO hr_payroll_structures (name,basic,house_rent,medical,transport,other_allowance,tax_percent,eobi,lwp_day_rate,active,
       created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array_merge($vals, [strtolower(trim($by)), now_sql(), now_sql()]));
    return last_id();
}

function hr_payroll_structure_delete(int $id): void {
    q('DELETE FROM hr_payroll_assignments WHERE structure_id=?', [$id]);
    q('DELETE FROM hr_payroll_structures WHERE id=?', [$id]);
}

/* ------------------------------------------------------------ assignments ---- */

function hr_payroll_assignment_for(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    return one('SELECT * FROM hr_payroll_assignments WHERE email=?', [$email]);
}

function hr_payroll_set_assignment(string $email, int $structureId, string $by): bool {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (hr_payroll_structure($structureId) === null) return false;
    $existing = hr_payroll_assignment_for($email);
    if ($existing !== null) {
        q('UPDATE hr_payroll_assignments SET structure_id=?, updated_by=?, updated_at=? WHERE email=?',
            [$structureId, strtolower(trim($by)), now_sql(), $email]);
    } else {
        q('INSERT INTO hr_payroll_assignments (email,structure_id,updated_by,updated_at) VALUES (?,?,?,?)',
            [$email, $structureId, strtolower(trim($by)), now_sql()]);
    }
    return true;
}

function hr_payroll_clear_assignment(string $email): void {
    q('DELETE FROM hr_payroll_assignments WHERE email=?', [strtolower(trim($email))]);
}

/** Everyone with an assignment, joined to their structure name. */
function hr_payroll_assigned_employees(): array {
    return rows('SELECT a.email, a.structure_id, s.name AS structure_name
                 FROM hr_payroll_assignments a JOIN hr_payroll_structures s ON s.id = a.structure_id
                 ORDER BY a.email ASC');
}

/* ----------------------------------------------------------------- advances ---- */

function hr_payroll_advances(array $filters = []): array {
    $sql = 'SELECT a.*, e.first_name, e.last_name FROM hr_payroll_advances a
            LEFT JOIN employees e ON LOWER(e.email) = LOWER(a.email)';
    $where = []; $p = [];
    if (!empty($filters['email'])) { $where[] = 'LOWER(a.email)=?'; $p[] = strtolower((string) $filters['email']); }
    if (!empty($filters['status'])) { $where[] = 'a.status=?'; $p[] = strtoupper((string) $filters['status']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_payroll_advance(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_payroll_advances WHERE id=?', [$id]) : null;
}

/** Outstanding advances for one employee (for the self-service view). */
function hr_payroll_advances_for_email(string $email): array {
    return hr_payroll_advances(['email' => $email, 'status' => 'OUTSTANDING']);
}

/** Grant a salary advance. Returns the new id, or 0 when the employee is unknown. */
function hr_payroll_advance_grant(string $email, float $amount, string $reason, string $by): int {
    $email = strtolower(trim($email));
    if ($email === '' || employee_find_by_email($email) === null) return 0;
    if ($amount <= 0) return 0;
    q('INSERT INTO hr_payroll_advances (email,amount,reason,status,created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?)',
        [$email, hp_money($amount), hp_text($reason, 255), 'OUTSTANDING', strtolower(trim($by)), now_sql(), now_sql()]);
    return last_id();
}

/** Cancel an outstanding advance. Returns an error string, or '' on success. */
function hr_payroll_advance_cancel(int $id, string $by): string {
    $a = hr_payroll_advance($id);
    if ($a === null) return 'Advance not found.';
    if (strtoupper((string) $a['status']) !== 'OUTSTANDING') return 'Only an outstanding advance can be cancelled.';
    q('UPDATE hr_payroll_advances SET status=?, updated_at=? WHERE id=?', ['CANCELLED', now_sql(), $id]);
    activity_log(['email' => strtolower(trim($by)), 'role' => ''], 'HR_PAYROLL_ADVANCE_CANCELLED', 'Advance #' . $id);
    return '';
}

/* -------------------------------------------------------------------- runs ---- */

function hr_payroll_runs(array $filters = []): array {
    $sql = 'SELECT * FROM hr_payroll_runs';
    $where = []; $p = [];
    if (!empty($filters['status'])) { $where[] = 'status=?'; $p[] = strtoupper((string) $filters['status']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY period DESC, id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_payroll_run(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_payroll_runs WHERE id=?', [$id]) : null;
}

function hr_payroll_run_by_period(string $period): ?array {
    $period = hp_period($period);
    return $period ? one('SELECT * FROM hr_payroll_runs WHERE period=?', [$period]) : null;
}

/**
 * Generate a DRAFT run for a period: one payslip per assigned employee,
 * computed from their current structure, then adjusted for the period:
 *  - attendance_deduction: leave-without-pay days x the structure's LWP day rate;
 *  - advance_deduction: outstanding salary advances, deducted in full and
 *    marked recovered (recovered_run_id set) so they are never double-counted.
 * Returns the new run id, or 0 when the period is invalid/already run or
 * nobody is assigned.
 */
function hr_payroll_generate(string $period, string $by): int {
    $period = hp_period($period);
    if ($period === '') return 0;
    if (hr_payroll_run_by_period($period) !== null) return 0;
    $assigned = hr_payroll_assigned_employees();
    if ($assigned === []) return 0;

    /* Attendance snapshot for the period: ABSENT days per employee. */
    $absent = [];
    foreach (rows("SELECT email, COUNT(*) AS n FROM hr_attendance_days
                   WHERE work_date LIKE ? AND status='ABSENT' GROUP BY email",
                  [$period . '%']) as $a) {
        $absent[strtolower((string) $a['email'])] = (int) $a['n'];
    }
    /* Outstanding advances per employee, ready to be recovered this run. */
    $adv = [];
    foreach (rows("SELECT email, SUM(CAST(amount AS DECIMAL(18,2))) AS total
                   FROM hr_payroll_advances WHERE status='OUTSTANDING' GROUP BY email") as $a) {
        $adv[strtolower((string) $a['email'])] = (float) $a['total'];
    }

    $rows = [];
    $totalGross = 0.0; $totalNet = 0.0; $totalDed = 0.0;
    foreach ($assigned as $a) {
        $s = hr_payroll_structure((int) $a['structure_id']);
        if ($s === null) continue;
        $b = hr_payroll_breakdown($s);
        $email = strtolower((string) $a['email']);
        $att = round($absent[$email] * (float) $s['lwp_day_rate'], 2) ?? 0.0;
        $advDed = $adv[$email] ?? 0.0;
        $net = $b['gross'] - $b['tax'] - $b['deductions'] - $att - $advDed;
        $totalGross += $b['gross'];
        $totalDed += $b['tax'] + $b['deductions'] + $att + $advDed;
        $totalNet += $net;
        $rows[] = [$email, $b, $att, $advDed];
    }
    if ($rows === []) return 0;

    q('INSERT INTO hr_payroll_runs (period,status,total_gross,total_net,headcount,notes,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?)',
        [$period, 'DRAFT', number_format($totalGross, 2, '.', ''), number_format($totalNet, 2, '.', ''),
         count($rows), '', now_sql(), now_sql()]);
    $runId = last_id();

    foreach ($rows as $r) {
        $email = $r[0]; $b = $r[1]; $att = $r[2]; $advDed = $r[3];
        q('INSERT INTO hr_payslips (run_id,email,basic,allowances,bonus,commission,deductions,tax,advance_deduction,attendance_deduction,net,notes,created_at,updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $runId, $email,
            number_format($b['basic'], 2, '.', ''), number_format($b['allowances'], 2, '.', ''),
            '0', '0',
            number_format($b['deductions'], 2, '.', ''), number_format($b['tax'], 2, '.', ''),
            number_format($advDed, 2, '.', ''), number_format($att, 2, '.', ''),
            number_format($b['gross'] - $b['tax'] - $b['deductions'] - $att - $advDed, 2, '.', ''), '', now_sql(), now_sql(),
        ]);
        /* Mark advances for this employee as recovered by this run. */
        if ($advDed > 0.0) {
            q('UPDATE hr_payroll_advances SET status=?, recovered_run_id=?, updated_at=? WHERE email=? AND status=?',
                ['RECOVERED', $runId, now_sql(), $email, 'OUTSTANDING']);
        }
    }
    activity_log(['email' => strtolower(trim($by)), 'role' => ''], 'HR_PAYROLL_GENERATED', 'Run #' . $runId . ' ' . $period);
    return $runId;
}

function hr_payroll_recompute_totals(int $runId): void {
    $gross = (string) val(
        'SELECT COALESCE(SUM(CAST(basic AS DECIMAL(18,2)) + CAST(allowances AS DECIMAL(18,2))'
        . ' + CAST(bonus AS DECIMAL(18,2)) + CAST(commission AS DECIMAL(18,2))),0) FROM hr_payslips WHERE run_id=?',
        [$runId]);
    $net = (string) val('SELECT COALESCE(SUM(CAST(net AS DECIMAL(18,2))),0) FROM hr_payslips WHERE run_id=?', [$runId]);
    $n = (int) val('SELECT COUNT(*) FROM hr_payslips WHERE run_id=?', [$runId]);
    q('UPDATE hr_payroll_runs SET total_gross=?, total_net=?, headcount=?, updated_at=? WHERE id=?',
        [hp_money($gross), hp_money($net), $n, now_sql(), $runId]);
}

function hr_payroll_update_payslip(int $payslipId, array $d): bool {
    $ps = one('SELECT * FROM hr_payslips WHERE id=?', [$payslipId]);
    if ($ps === null) return false;
    $run = hr_payroll_run((int) $ps['run_id']);
    if ($run === null) return false;
    if (strtoupper((string) $run['status']) !== 'DRAFT') return false; /* locked once processed */
    $basic = hp_money($d['basic'] ?? 0);
    $allow = hp_money($d['allowances'] ?? 0);
    $bonus = hp_money($d['bonus'] ?? 0);
    $comm = hp_money($d['commission'] ?? 0);
    $ded = hp_money($d['deductions'] ?? 0);
    $tax = hp_money($d['tax'] ?? 0);
    $advDed = hp_money($d['advance_deduction'] ?? 0);
    $attDed = hp_money($d['attendance_deduction'] ?? 0);
    $net = number_format((float) $basic + (float) $allow + (float) $bonus + (float) $comm
        - (float) $ded - (float) $tax - (float) $advDed - (float) $attDed, 2, '.', '');
    q('UPDATE hr_payslips SET basic=?, allowances=?, bonus=?, commission=?, deductions=?, tax=?,
       advance_deduction=?, attendance_deduction=?, net=?, notes=?, updated_at=? WHERE id=?',
        [$basic, $allow, $bonus, $comm, $ded, $tax, $advDed, $attDed, $net,
         substr(str_replace("\0", '', (string) ($d['notes'] ?? '')), 0, 8000), now_sql(), $payslipId]);
    hr_payroll_recompute_totals((int) $ps['run_id']);
    return true;
}

function hr_payroll_set_run_status(int $runId, string $status, string $by): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_payroll_run_statuses()[$status])) return false;
    $run = hr_payroll_run($runId);
    if ($run === null) return false;
    $processedBy = null; $processedAt = null;
    if (in_array($status, ['PROCESSED', 'PAID'], true)) {
        $processedBy = strtolower(trim($by));
        $processedAt = (string) ($run['processed_at'] ?: now_sql());
    } else {
        $processedBy = (string) ($run['processed_by'] ?? '');
        $processedAt = $run['processed_at'] ?? null;
    }
    q('UPDATE hr_payroll_runs SET status=?, processed_by=?, processed_at=?, updated_at=? WHERE id=?',
        [$status, $processedBy, $processedAt, now_sql(), $runId]);
    activity_log(['email' => strtolower(trim($by)), 'role' => ''], 'HR_PAYROLL_STATUS', 'Run #' . $runId . ' -> ' . $status);
    return true;
}

function hr_payroll_run_delete(int $runId): void {
    q('DELETE FROM hr_payslips WHERE run_id=?', [$runId]);
    q('DELETE FROM hr_payroll_runs WHERE id=?', [$runId]);
}

/* --------------------------------------------------------------- payslips ---- */

function hr_payslips_for_run(int $runId): array {
    return rows('SELECT * FROM hr_payslips WHERE run_id=? ORDER BY email ASC', [$runId]);
}

function hr_payslips_for_email(string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') return [];
    return rows('SELECT ps.*, r.period, r.status AS run_status FROM hr_payslips ps
                 JOIN hr_payroll_runs r ON r.id = ps.run_id
                 WHERE ps.email=? ORDER BY r.period DESC, ps.id DESC', [$email]);
}

/**
 * Rows for a Meezan Bank salary-transfer upload, one per payslip in the run.
 * Bank details are the confirmed ones captured by the Employee Form
 * (employee_hrm_records); missing details are reported so the file is not
 * silently short. Returns ['rows' => [...], 'missing' => [emails...]].
 */
function hr_meezan_salary_file(int $runId): array {
    $sql = "SELECT ps.email, ps.net,
                   r.full_name AS hr_name, e.first_name, e.last_name,
                   r.account_title, r.account_number, r.bank_name, r.bank_confirmed
            FROM hr_payslips ps
            LEFT JOIN employees e ON LOWER(e.email) = LOWER(ps.email)
            LEFT JOIN employee_hrm_records r ON LOWER(r.email) = LOWER(ps.email)
            WHERE ps.run_id = ? ORDER BY ps.email";
    try {
        $raw = rows($sql, [$runId]);
    } catch (Throwable $e) {
        $raw = rows('SELECT email, net, email AS hr_name, NULL AS first_name, NULL AS last_name,
                            NULL AS account_title, NULL AS account_number, NULL AS bank_name, 0 AS bank_confirmed
                     FROM hr_payslips WHERE run_id=? ORDER BY email', [$runId]);
    }
    $rows = [];
    $missing = [];
    foreach ($raw as $r) {
        $name = trim((string) ($r['hr_name'] ?? ''));
        if ($name === '') $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
        if ($name === '') $name = (string) $r['email'];
        $acct = trim((string) ($r['account_number'] ?? ''));
        $title = trim((string) ($r['account_title'] ?? ''));
        if ($title === '') $title = $name;
        if ($acct === '') $missing[] = (string) $r['email'];
        $rows[] = [
            'email' => (string) $r['email'],
            'name' => $name,
            'account_title' => $title,
            'account_number' => $acct,
            'bank_name' => trim((string) ($r['bank_name'] ?? '')),
            'amount' => round((float) $r['net'], 2),
            'confirmed' => (int) ($r['bank_confirmed'] ?? 0) === 1,
        ];
    }
    return ['rows' => $rows, 'missing' => $missing];
}

/* --------------------------------------------------------------- dashboard ---- */

function hr_payroll_stats(): array {
    try {
        $structures = (int) val('SELECT COUNT(*) FROM hr_payroll_structures');
        $assigned = (int) val('SELECT COUNT(*) FROM hr_payroll_assignments');
        $lastRun = one("SELECT period, total_net, status FROM hr_payroll_runs ORDER BY period DESC, id DESC LIMIT 1");
        $paid = (int) val("SELECT COUNT(*) FROM hr_payroll_runs WHERE status='PAID'");
        $advances = (int) val("SELECT COUNT(*) FROM hr_payroll_advances WHERE status='OUTSTANDING'");
        $advancesTotal = (float) val("SELECT COALESCE(SUM(CAST(amount AS DECIMAL(18,2))),0) FROM hr_payroll_advances WHERE status='OUTSTANDING'");
    } catch (Throwable $e) {
        return ['structures' => 0, 'assigned' => 0, 'last_period' => '', 'last_net' => '0', 'paid_runs' => 0,
                'advances' => 0, 'advances_total' => '0'];
    }
    return [
        'structures' => $structures, 'assigned' => $assigned,
        'last_period' => (string) ($lastRun['period'] ?? ''),
        'last_net' => (string) ($lastRun['total_net'] ?? '0'),
        'paid_runs' => $paid,
        'advances' => $advances,
        'advances_total' => number_format($advancesTotal, 2, '.', ''),
    ];
}
