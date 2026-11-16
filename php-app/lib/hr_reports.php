<?php
/**
 * WorkFolio PHP - HR module data layer: Reports & Analytics.
 *
 * Read-only aggregates over the other HR layers. Every function is defensive:
 * it returns an empty/zeroed shape when a table is missing so the reports page
 * still renders on a partially migrated database. No writes happen here.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hr.php';

/* ------------------------------------------------------------------ window ---- */

/** Normalise a from/to pair to [Y-m-d, Y-m-d]; defaults to the current month. */
function hr_report_window(?string $from, ?string $to): array {
    $ok = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    $from = $ok($from) ? $from : date('Y-m-01');
    $to = $ok($to) ? $to : date('Y-m-t');
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }
    return [$from, $to];
}

/* ------------------------------------------------------------- headcount ---- */

function hr_report_headcount(): array {
    try {
        $total = (int) val('SELECT COUNT(*) FROM employees');
        $active = (int) val("SELECT COUNT(*) FROM hr_employee_profiles WHERE employment_status IN ('ACTIVE','PROBATION')");
        $newJoiners = (int) val('SELECT COUNT(*) FROM employees WHERE join_date >= ?', [date('Y-m-d', strtotime('-30 days'))]);
        $exits = 0;
        if (function_exists('hr_offboarding_stats')) {
            $exits = (int) (hr_offboarding_stats()['completed_month'] ?? 0);
        }
        return ['total' => $total, 'active' => $active, 'new_joiners' => $newJoiners, 'exits' => $exits];
    } catch (Throwable $e) {
        return ['total' => 0, 'active' => 0, 'new_joiners' => 0, 'exits' => 0];
    }
}

function hr_report_headcount_by_department(): array {
    try {
        return rows("SELECT COALESCE(NULLIF(TRIM(department), ''), '(Unassigned)') AS label, COUNT(*) AS n
                     FROM employees GROUP BY label ORDER BY n DESC");
    } catch (Throwable $e) { return []; }
}

function hr_report_headcount_by_branch(): array {
    try {
        return rows("SELECT COALESCE(NULLIF(TRIM(b.name), ''), '(Unassigned)') AS label, COUNT(*) AS n
                     FROM employees e
                     LEFT JOIN hr_employee_profiles p ON LOWER(p.email) = LOWER(e.email)
                     LEFT JOIN hr_branches b ON b.id = p.branch_id
                     GROUP BY label ORDER BY n DESC");
    } catch (Throwable $e) { return []; }
}

function hr_report_headcount_by_status(): array {
    try {
        return rows("SELECT COALESCE(NULLIF(TRIM(employment_status), ''), 'UNKNOWN') AS label, COUNT(*) AS n
                     FROM hr_employee_profiles GROUP BY label ORDER BY n DESC");
    } catch (Throwable $e) { return []; }
}

/* ------------------------------------------------------------ attendance ---- */

function hr_report_attendance(string $from, string $to): array {
    try {
        $byStatus = rows('SELECT status, COUNT(*) AS n FROM hr_attendance_days
                          WHERE work_date BETWEEN ? AND ? GROUP BY status ORDER BY n DESC', [$from, $to]);
        $late = (int) val('SELECT COUNT(*) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ? AND late_minutes > 0', [$from, $to]);
        $lateMinutes = (int) val('SELECT COALESCE(SUM(late_minutes),0) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ?', [$from, $to]);
        $earlyDays = (int) val('SELECT COUNT(*) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ? AND early_minutes > 0', [$from, $to]);
        $earlyMinutes = (int) val('SELECT COALESCE(SUM(early_minutes),0) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ?', [$from, $to]);
        $overtimeMinutes = (int) val('SELECT COALESCE(SUM(overtime_minutes),0) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ?', [$from, $to]);
        $absences = (int) val("SELECT COUNT(*) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ? AND status='ABSENT'", [$from, $to]);
        $worked = (int) val('SELECT COALESCE(SUM(worked_seconds),0) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ?', [$from, $to]);
        $days = (int) val('SELECT COUNT(*) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ?', [$from, $to]);
        $total = array_sum(array_map(static fn($r) => (int) $r['n'], $byStatus));
        return [
            'by_status' => $byStatus, 'records' => $days, 'late_days' => $late,
            'late_minutes' => $lateMinutes, 'worked_hours' => round($worked / 3600, 1),
            'early_days' => $earlyDays, 'early_minutes' => $earlyMinutes,
            'overtime_minutes' => $overtimeMinutes, 'absences' => $absences,
            'present' => (int) val("SELECT COUNT(*) FROM hr_attendance_days WHERE work_date BETWEEN ? AND ? AND status IN ('PRESENT','WFH','HALF_DAY')", [$from, $to]),
            'total' => $total,
        ];
    } catch (Throwable $e) {
        return ['by_status' => [], 'records' => 0, 'late_days' => 0, 'late_minutes' => 0, 'worked_hours' => 0, 'present' => 0, 'total' => 0,
                'early_days' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0, 'absences' => 0];
    }
}

/** Attendance rows day-by-day for the daily report (one row per work_date). */
function hr_report_attendance_daily(string $from, string $to): array {
    try {
        return rows("SELECT work_date,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status IN ('PRESENT','WFH','HALF_DAY') THEN 1 ELSE 0 END) AS present,
                        SUM(CASE WHEN status='ABSENT' THEN 1 ELSE 0 END) AS absent,
                        SUM(CASE WHEN late_minutes>0 THEN 1 ELSE 0 END) AS late,
                        SUM(CASE WHEN early_minutes>0 THEN 1 ELSE 0 END) AS early,
                        COALESCE(SUM(overtime_minutes),0) AS overtime
                     FROM hr_attendance_days
                     WHERE work_date BETWEEN ? AND ?
                     GROUP BY work_date ORDER BY work_date", [$from, $to]);
    } catch (Throwable $e) { return []; }
}

/* ----------------------------------------------------------------- leave ---- */

function hr_report_leave(string $from, string $to): array {
    try {
        $byStatus = rows('SELECT status, COUNT(*) AS n, COALESCE(SUM(days),0) AS days FROM hr_leave_requests
                          WHERE start_date <= ? AND end_date >= ? GROUP BY status ORDER BY n DESC', [$to, $from]);
        $byType = rows('SELECT COALESCE(t.name, \'(Unspecified)\') AS label, COUNT(*) AS n, COALESCE(SUM(r.days),0) AS days
                        FROM hr_leave_requests r LEFT JOIN hr_leave_types t ON t.id = r.leave_type_id
                        WHERE r.start_date <= ? AND r.end_date >= ? GROUP BY label ORDER BY n DESC', [$to, $from]);
        $approvedDays = (float) val("SELECT COALESCE(SUM(days),0) FROM hr_leave_requests
                                     WHERE status='APPROVED' AND start_date <= ? AND end_date >= ?", [$to, $from]);
        return ['by_status' => $byStatus, 'by_type' => $byType, 'approved_days' => $approvedDays,
                'pending' => (int) val("SELECT COUNT(*) FROM hr_leave_requests WHERE status IN ('PENDING','MANAGER_APPROVED')")];
    } catch (Throwable $e) {
        return ['by_status' => [], 'by_type' => [], 'approved_days' => 0, 'pending' => 0];
    }
}

function hr_report_leave_by_department(string $from, string $to): array {
    try {
        return rows("SELECT COALESCE(NULLIF(TRIM(e.department), ''), '(Unassigned)') AS label,
                            COUNT(*) AS n, COALESCE(SUM(r.days),0) AS days
                     FROM hr_leave_requests r
                     LEFT JOIN employees e ON LOWER(e.email) = LOWER(r.email)
                     WHERE r.status='APPROVED' AND r.start_date <= ? AND r.end_date >= ?
                     GROUP BY label ORDER BY days DESC", [$to, $from]);
    } catch (Throwable $e) { return []; }
}

function hr_report_leave_by_employee(string $from, string $to, int $limit = 25): array {
    try {
        return rows("SELECT r.email, COALESCE(SUM(r.days),0) AS days, COUNT(*) AS n
                     FROM hr_leave_requests r
                     WHERE r.status='APPROVED' AND r.start_date <= ? AND r.end_date >= ?
                     GROUP BY r.email ORDER BY days DESC LIMIT " . (int) $limit, [$to, $from]);
    } catch (Throwable $e) { return []; }
}

/* ----------------------------------------------------------- recruitment ---- */

function hr_report_recruitment(): array {
    try {
        $openings = rows('SELECT status, COUNT(*) AS n FROM hr_job_openings GROUP BY status ORDER BY n DESC');
        $openCount = (int) val("SELECT COUNT(*) FROM hr_job_openings WHERE status='OPEN'");
        $vacancies = (int) val("SELECT COALESCE(SUM(vacancies),0) FROM hr_job_openings WHERE status='OPEN'");
        $candidates = rows('SELECT status, COUNT(*) AS n FROM employee_form_submissions GROUP BY status ORDER BY n DESC');
        $candTotal = (int) val('SELECT COUNT(*) FROM employee_form_submissions');
        $interviewsUpcoming = (int) val("SELECT COUNT(*) FROM hr_interviews WHERE stage='SCHEDULED'");
        $interviewsDone = (int) val("SELECT COUNT(*) FROM hr_interviews WHERE stage='DONE'");
        $offers = rows('SELECT status, COUNT(*) AS n FROM hr_offers GROUP BY status ORDER BY n DESC');
        $selected = (int) val("SELECT COUNT(*) FROM employee_form_submissions WHERE status='HIRED'");
        $rejected = (int) val("SELECT COUNT(*) FROM employee_form_submissions WHERE status='REJECTED'");
        return ['openings' => $openings, 'open_count' => $openCount, 'vacancies' => $vacancies,
                'candidates' => $candidates, 'candidate_total' => $candTotal,
                'interviews_upcoming' => $interviewsUpcoming, 'interviews_done' => $interviewsDone,
                'offers' => $offers, 'selected' => $selected, 'rejected' => $rejected];
    } catch (Throwable $e) {
        return ['openings' => [], 'open_count' => 0, 'vacancies' => 0, 'candidates' => [],
                'candidate_total' => 0, 'interviews_upcoming' => 0, 'interviews_done' => 0, 'offers' => [],
                'selected' => 0, 'rejected' => 0];
    }
}

function hr_report_performance(): array {
    try {
        $byStatus = rows('SELECT status, COUNT(*) AS n FROM hr_performance_reviews GROUP BY status ORDER BY n DESC');
        $avgRating = (float) val('SELECT COALESCE(AVG(overall_rating),0) FROM hr_performance_reviews WHERE overall_rating > 0');
        $goalsTotal = (int) val('SELECT COUNT(*) FROM hr_performance_goals');
        $goalsDone = (int) val("SELECT COUNT(*) FROM hr_performance_goals WHERE status='ACHIEVED'");
        return ['by_status' => $byStatus, 'avg_rating' => round($avgRating, 2),
                'goals_total' => $goalsTotal, 'goals_done' => $goalsDone];
    } catch (Throwable $e) {
        return ['by_status' => [], 'avg_rating' => 0, 'goals_total' => 0, 'goals_done' => 0];
    }
}

/* --------------------------------------------------------------- payroll ---- */

function hr_report_payroll(): array {
    try {
        $runs = rows('SELECT period, status, headcount, total_gross, total_net FROM hr_payroll_runs ORDER BY period DESC LIMIT 6');
        $last = $runs[0] ?? null;
        $ytdNet = (float) val("SELECT COALESCE(SUM(CAST(total_net AS DECIMAL(18,2))),0) FROM hr_payroll_runs WHERE status IN ('PROCESSED','PAID')");
        $deductions = (float) val("SELECT COALESCE(SUM(CAST(deductions AS DECIMAL(18,2))),0) FROM hr_payslips");
        $tax = (float) val("SELECT COALESCE(SUM(CAST(tax AS DECIMAL(18,2))),0) FROM hr_payslips");
        return ['runs' => $runs, 'last' => $last, 'ytd_net' => $ytdNet,
                'deductions' => $deductions, 'tax' => $tax];
    } catch (Throwable $e) {
        return ['runs' => [], 'last' => null, 'ytd_net' => 0, 'deductions' => 0, 'tax' => 0];
    }
}

/* ----------------------------------------------------------------- assets ---- */

function hr_report_assets(): array {
    try {
        $byStatus = rows('SELECT status, COUNT(*) AS n FROM hr_assets GROUP BY status ORDER BY n DESC');
        $byCategory = rows('SELECT COALESCE(c.name, \'(Uncategorised)\') AS label, COUNT(*) AS n
                            FROM hr_assets a LEFT JOIN hr_asset_categories c ON c.id = a.category_id
                            GROUP BY label ORDER BY n DESC');
        $value = (string) val('SELECT COALESCE(SUM(CAST(purchase_cost AS DECIMAL(18,2))),0) FROM hr_assets');
        $assigned = (int) val("SELECT COUNT(*) FROM hr_assets WHERE status='ASSIGNED'");
        $available = (int) val("SELECT COUNT(*) FROM hr_assets WHERE status='AVAILABLE'");
        $returned = (int) val('SELECT COUNT(*) FROM hr_asset_assignments WHERE returned_at IS NOT NULL');
        return ['by_status' => $byStatus, 'by_category' => $byCategory, 'value' => $value,
                'assigned' => $assigned, 'available' => $available, 'returned' => $returned];
    } catch (Throwable $e) {
        return ['by_status' => [], 'by_category' => [], 'value' => '0', 'assigned' => 0, 'available' => 0, 'returned' => 0];
    }
}

/* ----------------------------------------------------------- lifecycle ---- */

function hr_report_lifecycle(): array {
    $out = ['onboarding_active' => 0, 'onboarding_completed' => 0, 'offboarding_active' => 0,
            'offboarding_completed' => 0, 'open_tasks' => 0, 'overdue_tasks' => 0,
            'pending_reviews' => 0, 'goals' => 0];
    try { if (function_exists('hr_onboarding_stats')) { $s = hr_onboarding_stats(); $out['onboarding_active'] = (int) ($s['active_plans'] ?? 0); } } catch (Throwable $e) {}
    try { if (function_exists('hr_offboarding_stats')) { $s = hr_offboarding_stats(); $out['offboarding_active'] = (int) ($s['active_plans'] ?? 0); $out['offboarding_completed'] = (int) ($s['completed_month'] ?? 0); } } catch (Throwable $e) {}
    try { $out['onboarding_completed'] = (int) val("SELECT COUNT(*) FROM hr_onboarding_plans WHERE status='COMPLETED'"); } catch (Throwable $e) {}
    try {
        if (function_exists('hr_tasks_stats')) { $s = hr_tasks_stats(); $out['open_tasks'] = (int) ($s['open'] ?? 0); $out['overdue_tasks'] = (int) ($s['overdue'] ?? 0); }
    } catch (Throwable $e) {}
    try { if (function_exists('hr_performance_stats')) { $s = hr_performance_stats(); $out['pending_reviews'] = (int) ($s['pending_reviews'] ?? 0); } } catch (Throwable $e) {}
    try { $out['goals'] = (int) val('SELECT COUNT(*) FROM hr_performance_goals'); } catch (Throwable $e) {}
    return $out;
}

/* ------------------------------------------------------------- analytics ---- */

/** The last $months calendar months as ['YYYY-MM', ...], oldest first. */
function hr_month_spine(int $months = 12): array {
    $months = max(1, min(36, $months));
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) $out[] = date('Y-m', strtotime("-$i months", strtotime(date('Y-m-01'))));
    return $out;
}

function hr_month_label(string $ym): string {
    $t = strtotime($ym . '-01');
    return $t ? date('M y', $t) : $ym;
}

/**
 * Cumulative headcount at the end of each of the last $months months, plus new
 * hires per month (join_date) and exits per month (offboarding last_working_date).
 * Returns [['ym','label','headcount','hires','exits'], ...].
 */
function hr_report_headcount_trend(int $months = 12): array {
    $spine = hr_month_spine($months);
    $hires = []; $exits = [];
    try {
        foreach (rows("SELECT SUBSTR(join_date,1,7) AS ym, COUNT(*) AS n FROM employees
                       WHERE join_date<>'' AND join_date IS NOT NULL GROUP BY ym") as $r) {
            $hires[(string) $r['ym']] = (int) $r['n'];
        }
    } catch (Throwable $e) {}
    try {
        foreach (rows("SELECT SUBSTR(last_working_date,1,7) AS ym, COUNT(*) AS n FROM hr_offboarding_plans
                       WHERE status='COMPLETED' AND last_working_date<>'' AND last_working_date IS NOT NULL GROUP BY ym") as $r) {
            $exits[(string) $r['ym']] = (int) $r['n'];
        }
    } catch (Throwable $e) {}
    $out = [];
    foreach ($spine as $ym) {
        $end = date('Y-m-t', strtotime($ym . '-01'));
        $hc = 0;
        try { $hc = (int) val("SELECT COUNT(*) FROM employees WHERE join_date<>'' AND join_date<=?", [$end]); } catch (Throwable $e) {}
        $out[] = ['ym' => $ym, 'label' => hr_month_label($ym), 'headcount' => $hc,
                  'hires' => (int) ($hires[$ym] ?? 0), 'exits' => (int) ($exits[$ym] ?? 0)];
    }
    return $out;
}

/** Net payroll cost per run, oldest first (last $limit runs). */
function hr_report_payroll_trend(int $limit = 6): array {
    try {
        $runs = rows('SELECT period, total_net, total_gross FROM hr_payroll_runs ORDER BY period DESC LIMIT ' . (int) max(1, $limit));
        $runs = array_reverse($runs);
        return array_map(static fn($r) => [
            'label' => (string) $r['period'], 'net' => (float) $r['total_net'], 'gross' => (float) $r['total_gross'],
        ], $runs);
    } catch (Throwable $e) { return []; }
}

/** Present rate per day as [['label'=>'d M','value'=>pct], ...] (window capped). */
function hr_report_attendance_rate(string $from, string $to): array {
    $daily = hr_report_attendance_daily($from, $to);
    $out = [];
    foreach ($daily as $d) {
        $total = (int) $d['total'];
        $pct = $total > 0 ? round(((int) $d['present']) / $total * 100, 1) : 0.0;
        $out[] = ['label' => date('d M', strtotime((string) $d['work_date'])), 'value' => $pct];
    }
    return $out;
}

/**
 * Period-over-period comparison for the same window length immediately before
 * $from. Returns deltas for attendance present days, absences and leave days.
 */
function hr_report_comparison(string $from, string $to): array {
    $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
    $prevTo = date('Y-m-d', strtotime($from) - 86400);
    $prevFrom = date('Y-m-d', strtotime($prevTo) - ($days - 1) * 86400);
    $cur = hr_report_attendance($from, $to);
    $prev = hr_report_attendance($prevFrom, $prevTo);
    $curL = hr_report_leave($from, $to);
    $prevL = hr_report_leave($prevFrom, $prevTo);
    $d = static fn($a, $b) => $b == 0 ? ($a > 0 ? 100.0 : 0.0) : round(($a - $b) / $b * 100, 1);
    return [
        'from' => $prevFrom, 'to' => $prevTo,
        'present' => ['current' => (int) $cur['present'], 'previous' => (int) $prev['present'],
                      'delta' => $d((int) $cur['present'], (int) $prev['present'])],
        'absences' => ['current' => (int) $cur['absences'], 'previous' => (int) $prev['absences'],
                       'delta' => $d((int) $cur['absences'], (int) $prev['absences'])],
        'leave_days' => ['current' => (float) $curL['approved_days'], 'previous' => (float) $prevL['approved_days'],
                         'delta' => $d((float) $curL['approved_days'], (float) $prevL['approved_days'])],
    ];
}

/** Attrition rate over [$from,$to]: exits / average headcount, as a percentage. */
function hr_report_attrition(string $from, string $to): array {
    $exits = 0;
    try {
        $exits = (int) val("SELECT COUNT(*) FROM hr_offboarding_plans
                            WHERE status='COMPLETED' AND last_working_date BETWEEN ? AND ?", [$from, $to]);
    } catch (Throwable $e) {}
    $total = 0;
    try { $total = (int) val('SELECT COUNT(*) FROM employees'); } catch (Throwable $e) {}
    $hires = 0;
    try { $hires = (int) val('SELECT COUNT(*) FROM employees WHERE join_date BETWEEN ? AND ?', [$from, $to]); } catch (Throwable $e) {}
    $avg = max(1, (int) round(($total + ($total - $hires + $exits)) / 2));
    return ['exits' => $exits, 'hires' => $hires, 'headcount' => $total, 'rate' => round($exits / $avg * 100, 1)];
}

