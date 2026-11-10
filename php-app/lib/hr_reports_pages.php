<?php
/**
 * WorkFolio PHP - HR module screens: Reports & Analytics.
 *
 * Mounted through hr_reports_route(), which hr_route() (in hr_pages.php)
 * delegates to for any /hr/reports* path. One read-only page pulls every
 * aggregate from hr_reports.php; the only control is the date window.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/hr_pages.php';
require_once __DIR__ . '/hr_reports.php';

/* ------------------------------------------------------------------- router ---- */

function hr_reports_route(string $method, string $path): bool {
    if ($method === 'GET' && $path === '/reports') { hr_page_hr_reports(); return true; }
    if ($method === 'GET' && $path === '/reports/export') { hr_reports_export(); return true; }
    return false;
}

/* ---------------------------------------------------------------- helpers ---- */

function hr_report_bars(array $rows, ?callable $labelFn = null): string {
    $max = 0;
    foreach ($rows as $r) $max = max($max, (int) $r['n']);
    if ($max === 0) return '<div class="small text-muted">No data.</div>';
    $out = '<div class="d-flex flex-column gap-2">';
    foreach ($rows as $r) {
        $label = $labelFn ? $labelFn((string) $r['label']) : (string) $r['label'];
        $n = (int) $r['n'];
        $pct = (int) round($n / $max * 100);
        $out .= '<div><div class="d-flex justify-content-between small"><span>' . esc($label) . '</span><span class="fw-semibold">' . $n . '</span></div>'
             . '<div class="progress" style="height:6px"><div class="progress-bar" style="width:' . $pct . '%"></div></div></div>';
    }
    return $out . '</div>';
}

function hr_report_stat(string $label, $value, string $cls = ''): string {
    return '<div class="col-6 col-md-3"><div class="card p-3"><div class="text-muted small">' . esc($label) . '</div>'
        . '<div class="fs-4 fw-bold ' . esc($cls) . '">' . esc((string) $value) . '</div></div></div>';
}

function hr_titlecase(string $s): string {
    return ucwords(strtolower(str_replace('_', ' ', $s)));
}

/* ------------------------------------------------------------------ charts ---- */

/**
 * Dependency-free SVG line/area chart. $points is a list of
 * ['label' => string, 'value' => number]. Renders an axis-free sparkline with
 * a filled area, a highlighted last point, and first/middle/last x labels.
 */
function hr_chart_line(array $points, string $color = '#0d6efd', int $height = 140, bool $asPercent = false, callable $fmt = null): string {
    $n = count($points);
    if ($n === 0) return '<div class="small text-muted">No data.</div>';
    $w = 640; $pad = 8; $top = 10; $bottom = 26;
    $values = array_map(static fn($p) => (float) $p['value'], $points);
    $max = max($values); $min = min($values);
    if ($asPercent) { $max = 100; $min = 0; }
    if ($max === $min) { $max = $min + 1; }
    $plotH = $height - $top - $bottom;
    $stepX = $n > 1 ? ($w - 2 * $pad) / ($n - 1) : 0;
    $fmt = $fmt ?? static fn($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . ($asPercent ? '%' : '');
    $xy = [];
    foreach ($points as $i => $p) {
        $x = $pad + $i * $stepX;
        $y = $top + $plotH - (($p['value'] - $min) / ($max - $min)) * $plotH;
        $xy[] = [$x, $y];
    }
    $line = implode(' ', array_map(static fn($c) => round($c[0], 1) . ',' . round($c[1], 1), $xy));
    $area = round($xy[0][0], 1) . ',' . ($top + $plotH) . ' ' . $line . ' '
        . round($xy[$n - 1][0], 1) . ',' . ($top + $plotH);
    $last = $xy[$n - 1];
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $height . '" preserveAspectRatio="none" style="width:100%;height:' . $height . 'px" role="img">';
    $out .= '<polygon points="' . $area . '" fill="' . esc($color) . '" opacity="0.12"/>';
    $out .= '<polyline points="' . $line . '" fill="none" stroke="' . esc($color) . '" stroke-width="2.5" stroke-linejoin="round"/>';
    foreach ($xy as $i => $c) {
        if ($i === $n - 1 || $i === 0) {
            $out .= '<circle cx="' . round($c[0], 1) . '" cy="' . round($c[1], 1) . '" r="3" fill="' . esc($color) . '"/>';
        }
    }
    $mid = (int) floor(($n - 1) / 2);
    foreach ([0, $mid, $n - 1] as $idx) {
        if (!isset($points[$idx])) continue;
        $anchor = $idx === 0 ? 'start' : ($idx === $n - 1 ? 'end' : 'middle');
        $out .= '<text x="' . round($xy[$idx][0], 1) . '" y="' . ($height - 8) . '" font-size="11" fill="#6c757d" text-anchor="' . $anchor . '">'
            . esc((string) $points[$idx]['label']) . '</text>';
    }
    $out .= '</svg>';
    $out .= '<div class="d-flex justify-content-between small text-muted mt-1"><span>Min ' . esc($fmt($min)) . '</span>'
        . '<span class="fw-semibold text-body">Latest ' . esc($fmt((float) $points[$n - 1]['value'])) . '</span>'
        . '<span>Max ' . esc($fmt($max)) . '</span></div>';
    return $out;
}

/**
 * Grouped bar chart (two series) as an SVG. $cats is the list of category
 * labels; $a and $b are equal-length numeric arrays.
 */
function hr_chart_bars_dual(array $cats, array $a, array $b, string $nameA, string $nameB,
                            string $colorA = '#0d6efd', string $colorB = '#dc3545', int $height = 160): string {
    $n = count($cats);
    if ($n === 0) return '<div class="small text-muted">No data.</div>';
    $w = 640; $pad = 10; $top = 12; $bottom = 28;
    $max = max(1, (int) max(array_merge($a ?: [0], $b ?: [0])));
    $plotH = $height - $top - $bottom;
    $slot = ($w - 2 * $pad) / max(1, $n);
    $barW = min(16, $slot * 0.34);
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $height . '" preserveAspectRatio="none" style="width:100%;height:' . $height . 'px" role="img">';
    for ($i = 0; $i < $n; $i++) {
        $cx = $pad + $i * $slot + $slot / 2;
        foreach ([[$a[$i] ?? 0, -1, $colorA], [$b[$i] ?? 0, 1, $colorB]] as $bar) {
            $v = (float) $bar[0]; $dir = $bar[1];
            $h = $v <= 0 ? 0 : max(2, ($v / $max) * $plotH);
            $x = $cx + ($dir < 0 ? -$barW - 1 : 1);
            $y = $top + $plotH - $h;
            if ($h > 0) $out .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round($h, 1) . '" rx="2" fill="' . esc($bar[2]) . '"/>';
        }
        if ($i === 0 || $i === $n - 1 || $i === (int) floor(($n - 1) / 2)) {
            $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
            $out .= '<text x="' . round($cx, 1) . '" y="' . ($height - 8) . '" font-size="11" fill="#6c757d" text-anchor="' . $anchor . '">'
                . esc((string) $cats[$i]) . '</text>';
        }
    }
    $out .= '</svg>';
    $out .= '<div class="small text-muted mt-1"><span class="me-3"><span style="display:inline-block;width:10px;height:10px;background:' . esc($colorA) . ';border-radius:2px"></span> ' . esc($nameA) . '</span>'
        . '<span><span style="display:inline-block;width:10px;height:10px;background:' . esc($colorB) . ';border-radius:2px"></span> ' . esc($nameB) . '</span></div>';
    return $out;
}

/** A small trend chip: current value plus a coloured delta vs the previous period. */
function hr_delta_chip(string $label, array $cmp, bool $invert = false): string {
    $cur = $cmp['current']; $del = (float) $cmp['delta'];
    $good = $invert ? ($del <= 0) : ($del >= 0);
    $cls = abs($del) < 0.05 ? 'text-muted' : ($good ? 'text-success' : 'text-danger');
    $arrow = $del > 0 ? 'bi-arrow-up' : ($del < 0 ? 'bi-arrow-down' : 'bi-dash');
    return '<div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">' . esc($label) . '</div>'
        . '<div class="fw-bold">' . esc((string) $cur) . '</div>'
        . '<div class="small ' . $cls . '"><i class="bi ' . $arrow . '"></i> ' . esc(number_format(abs($del), 1)) . '% vs prev</div></div>';
}

/* ------------------------------------------------------------------ export ---- */

/** Stream a CSV as a download and stop. Columns are taken from $header. */
function hr_reports_csv_send(string $filename, array $header, array $rows): void {
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $header);
    foreach ($rows as $r) {
        $line = [];
        foreach ($header as $col) $line[] = (string) ($r[$col] ?? '');
        fputcsv($fh, $line);
    }
    fclose($fh);
    exit;
}

/** GET /hr/reports/export?report=...  - one file per report section. */
function hr_reports_export(): void {
    require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    [$from, $to] = hr_report_window($_GET['from'] ?? null, $_GET['to'] ?? null);
    $r = strtolower((string) ($_GET['report'] ?? ''));
    switch ($r) {
        case 'attendance_daily':
            $rows = array_map(static fn($d) => [
                'work_date' => (string) $d['work_date'], 'present' => (int) $d['present'],
                'absent' => (int) $d['absent'], 'late' => (int) $d['late'],
                'early' => (int) $d['early'], 'overtime_minutes' => (int) $d['overtime'],
            ], hr_report_attendance_daily($from, $to));
            hr_reports_csv_send('attendance-daily.csv', ['work_date', 'present', 'absent', 'late', 'early', 'overtime_minutes'], $rows);
        case 'leave_by_department':
            $rows = array_map(static fn($r) => ['department' => (string) $r['label'], 'requests' => (int) $r['n'], 'days' => (float) $r['days']], hr_report_leave_by_department($from, $to));
            hr_reports_csv_send('leave-by-department.csv', ['department', 'requests', 'days'], $rows);
        case 'leave_by_employee':
            $rows = array_map(static fn($r) => ['email' => (string) $r['email'], 'days' => (float) $r['days'], 'requests' => (int) $r['n']], hr_report_leave_by_employee($from, $to));
            hr_reports_csv_send('leave-by-employee.csv', ['email', 'days', 'requests'], $rows);
        case 'headcount_department':
            $rows = array_map(static fn($r) => ['label' => (string) $r['label'], 'employees' => (int) $r['n']], hr_report_headcount_by_department());
            hr_reports_csv_send('headcount-by-department.csv', ['label', 'employees'], $rows);
        case 'headcount_branch':
            $rows = array_map(static fn($r) => ['label' => (string) $r['label'], 'employees' => (int) $r['n']], hr_report_headcount_by_branch());
            hr_reports_csv_send('headcount-by-branch.csv', ['label', 'employees'], $rows);
        case 'performance_reviews':
            $rows = array_map(static fn($r) => ['status' => (string) $r['status'], 'reviews' => (int) $r['n']], hr_report_performance()['by_status']);
            hr_reports_csv_send('performance-reviews.csv', ['status', 'reviews'], $rows);
        case 'payroll_runs':
            $rows = array_map(static fn($r) => ['period' => (string) $r['period'], 'status' => (string) $r['status'], 'headcount' => (int) $r['headcount'], 'gross' => (float) $r['total_gross'], 'net' => (float) $r['total_net']], hr_report_payroll()['runs']);
            hr_reports_csv_send('payroll-runs.csv', ['period', 'status', 'headcount', 'gross', 'net'], $rows);
        case 'assets':
            $rows = array_map(static fn($r) => ['status' => (string) $r['status'], 'assets' => (int) $r['n']], hr_report_assets()['by_status']);
            hr_reports_csv_send('assets.csv', ['status', 'assets'], $rows);
        case 'analytics_headcount':
            $rows = array_map(static fn($p) => ['month' => (string) $p['ym'], 'headcount' => (int) $p['headcount'], 'hires' => (int) $p['hires'], 'exits' => (int) $p['exits']], hr_report_headcount_trend(12));
            hr_reports_csv_send('analytics-headcount.csv', ['month', 'headcount', 'hires', 'exits'], $rows);
        case 'analytics_payroll':
            $rows = array_map(static fn($p) => ['period' => (string) $p['label'], 'gross' => (float) $p['gross'], 'net' => (float) $p['net']], hr_report_payroll_trend(12));
            hr_reports_csv_send('analytics-payroll.csv', ['period', 'gross', 'net'], $rows);
        case 'analytics_attendance':
            $rows = array_map(static fn($p) => ['day' => (string) $p['label'], 'present_rate_pct' => (float) $p['value']], hr_report_attendance_rate($from, $to));
            hr_reports_csv_send('analytics-attendance-rate.csv', ['day', 'present_rate_pct'], $rows);
        default:
            redirect(base_url('/hr/reports'));
    }
}

/* -------------------------------------------------------------------- page ---- */

function hr_page_hr_reports(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    hr_seed();
    [$from, $to] = hr_report_window($_GET['from'] ?? null, $_GET['to'] ?? null);

    $hc = hr_report_headcount();
    $byDept = hr_report_headcount_by_department();
    $byBranch = hr_report_headcount_by_branch();
    $byStatus = hr_report_headcount_by_status();
    $att = hr_report_attendance($from, $to);
    $attDaily = hr_report_attendance_daily($from, $to);
    $leave = hr_report_leave($from, $to);
    $leaveDept = hr_report_leave_by_department($from, $to);
    $leaveEmp = hr_report_leave_by_employee($from, $to);
    $rec = hr_report_recruitment();
    $perf = hr_report_performance();
    $pay = hr_report_payroll();
    $ast = hr_report_assets();
    $life = hr_report_lifecycle();
    $hcTrend = hr_report_headcount_trend(12);
    $payTrend = hr_report_payroll_trend(6);
    $attRate = hr_report_attendance_rate($from, $to);
    $cmp = hr_report_comparison($from, $to);
    $attr = hr_report_attrition($from, $to);

    layout_open('Reports & Analytics', $u, '/hr/reports');
    hr_tabs('/hr/reports');
    ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Reports & Analytics</h4>
    <div class="small text-muted">Headcount, attendance, leave, hiring, payroll and asset health.</div>
  </div>
  <form class="d-flex gap-2 align-items-end" method="get" action="<?= esc(base_url('/hr/reports')) ?>">
    <div><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= esc($from) ?>"></div>
    <div><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= esc($to) ?>"></div>
    <button class="btn btn-sm btn-primary">Apply</button>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Export</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=attendance_daily&from=' . $from . '&to=' . $to)) ?>">Attendance daily (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=leave_by_department&from=' . $from . '&to=' . $to)) ?>">Leave by department (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=leave_by_employee&from=' . $from . '&to=' . $to)) ?>">Leave by employee (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=headcount_department')) ?>">Headcount by department (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=headcount_branch')) ?>">Headcount by branch (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=performance_reviews')) ?>">Performance reviews (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=payroll_runs')) ?>">Payroll runs (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= esc(base_url('/hr/reports/export?report=assets')) ?>">Assets by status (CSV)</a></li>
      </ul>
    </div>
    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </form>
</div>

<div class="row g-3 mb-3">
  <?= hr_report_stat('Total Employees', $hc['total']) ?>
  <?= hr_report_stat('Active', $hc['active'], 'text-success') ?>
  <?= hr_report_stat('New (30d)', $hc['new_joiners']) ?>
  <?= hr_report_stat('Exits (Month)', $hc['exits'], 'text-danger') ?>
</div>

<div class="card p-3 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="sec-title mb-0"><i class="bi bi-activity me-1 text-primary"></i>Trends &amp; Insights</div>
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary" href="<?= esc(base_url('/hr/reports/export?report=analytics_headcount')) ?>">Headcount CSV</a>
      <a class="btn btn-outline-secondary" href="<?= esc(base_url('/hr/reports/export?report=analytics_payroll')) ?>">Payroll CSV</a>
      <a class="btn btn-outline-secondary" href="<?= esc(base_url('/hr/reports/export?report=analytics_attendance&from=' . $from . '&to=' . $to)) ?>">Attendance rate CSV</a>
    </div>
  </div>
  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="border rounded p-2 h-100">
        <div class="small fw-semibold mb-1">Headcount trend (12 months)</div>
        <?= hr_chart_line(array_map(static fn($p) => ['label' => $p['label'], 'value' => $p['headcount']], $hcTrend), '#0d6efd', 150, false, static fn($v) => (string) (int) round((float) $v)) ?>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="border rounded p-2 h-100">
        <div class="small fw-semibold mb-1">Hires vs exits (12 months)</div>
        <?= hr_chart_bars_dual(
              array_map(static fn($p) => $p['label'], $hcTrend),
              array_map(static fn($p) => $p['hires'], $hcTrend),
              array_map(static fn($p) => $p['exits'], $hcTrend),
              'Hires', 'Exits', '#198754', '#dc3545', 160) ?>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="border rounded p-2 h-100">
        <div class="small fw-semibold mb-1">Attendance present rate (<?= esc($from) ?> to <?= esc($to) ?>)</div>
        <?= hr_chart_line($attRate, '#20c997', 150, true) ?>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="border rounded p-2 h-100">
        <div class="small fw-semibold mb-1">Payroll net cost (last <?= count($payTrend) ?> runs)</div>
        <?php if ($payTrend): ?>
          <?= hr_chart_line(array_map(static fn($p) => ['label' => $p['label'], 'value' => $p['net']], $payTrend), '#6f42c1', 150, false, static fn($v) => number_format((float) $v, 0)) ?>
        <?php else: ?><div class="small text-muted">No payroll runs yet.</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="row g-2 mt-1">
    <div class="col-6 col-md-3"><?= hr_delta_chip('Present days', $cmp['present']) ?></div>
    <div class="col-6 col-md-3"><?= hr_delta_chip('Absences', $cmp['absences'], true) ?></div>
    <div class="col-6 col-md-3"><?= hr_delta_chip('Leave days', $cmp['leave_days'], true) ?></div>
    <div class="col-6 col-md-3">
      <div class="border rounded p-2">
        <div class="text-muted" style="font-size:.72rem">Attrition rate (window)</div>
        <div class="fw-bold"><?= esc(number_format($attr['rate'], 1)) ?>%</div>
        <div class="small text-muted"><?= (int) $attr['exits'] ?> exits / <?= (int) $attr['headcount'] ?> staff</div>
      </div>
    </div>
  </div>
  <div class="small text-muted mt-2">Comparisons are against the equal-length window ending <?= esc($cmp['to']) ?>.</div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Headcount by Department</div>
      <?= hr_report_bars($byDept) ?>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Headcount by Branch</div>
      <?= hr_report_bars($byBranch) ?>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Employment Status</div>
      <?= hr_report_bars($byStatus, fn($l) => hr_titlecase($l)) ?>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Performance</div>
      <div class="row g-2 mb-2">
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Avg rating</div><div class="fw-bold"><?= esc((string) $perf['avg_rating']) ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Reviews</div><div class="fw-bold"><?= array_sum(array_map(static fn($r) => (int) $r['n'], $perf['by_status'])) ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Goals achieved</div><div class="fw-bold"><?= (int) $perf['goals_done'] ?> / <?= (int) $perf['goals_total'] ?></div></div></div>
      </div>
      <?= hr_report_bars(array_map(static fn($r) => ['label' => hr_titlecase((string) $r['status']), 'n' => $r['n']], $perf['by_status'])) ?>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Attendance Summary</div>
      <div class="row g-2 mb-2">
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Present days</div><div class="fw-bold"><?= (int) $att['present'] ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Late days</div><div class="fw-bold"><?= (int) $att['late_days'] ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Early departures</div><div class="fw-bold"><?= (int) $att['early_days'] ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Absences</div><div class="fw-bold text-danger"><?= (int) $att['absences'] ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Overtime (min)</div><div class="fw-bold"><?= (int) $att['overtime_minutes'] ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Hours logged</div><div class="fw-bold"><?= esc((string) $att['worked_hours']) ?></div></div></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Lifecycle</div>
      <table class="table table-sm mb-0">
        <tbody>
          <tr><td class="small text-muted">Onboarding active</td><td class="small fw-semibold"><?= (int) $life['onboarding_active'] ?></td></tr>
          <tr><td class="small text-muted">Onboarding completed</td><td class="small fw-semibold"><?= (int) $life['onboarding_completed'] ?></td></tr>
          <tr><td class="small text-muted">Offboarding active</td><td class="small fw-semibold"><?= (int) $life['offboarding_active'] ?></td></tr>
          <tr><td class="small text-muted">Open tasks</td><td class="small fw-semibold"><?= (int) $life['open_tasks'] ?></td></tr>
          <tr><td class="small text-muted">Overdue tasks</td><td class="small fw-semibold text-danger"><?= (int) $life['overdue_tasks'] ?></td></tr>
          <tr><td class="small text-muted">Pending reviews</td><td class="small fw-semibold"><?= (int) $life['pending_reviews'] ?></td></tr>
          <tr><td class="small text-muted">Active goals</td><td class="small fw-semibold"><?= (int) $life['goals'] ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0">Daily Attendance</div>
        <div class="small text-muted"><?= esc($from) ?> to <?= esc($to) ?></div>
      </div>
      <div class="table-responsive" style="max-height:260px;overflow:auto">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Date</th><th>Present</th><th>Absent</th><th>Late</th><th>Early</th><th>Overtime (min)</th></tr></thead>
        <tbody>
        <?php foreach ($attDaily as $r): ?>
          <tr>
            <td class="small"><?= esc((string) $r['work_date']) ?></td>
            <td class="small"><?= (int) $r['present'] ?></td>
            <td class="small"><?= (int) $r['absent'] ?></td>
            <td class="small"><?= (int) $r['late'] ?></td>
            <td class="small"><?= (int) $r['early'] ?></td>
            <td class="small"><?= (int) $r['overtime'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($attDaily === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No attendance records.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0">Leave</div>
        <div class="small text-muted"><?= esc((string) $leave['approved_days']) ?> approved days &middot; <?= (int) $leave['pending'] ?> pending</div>
      </div>
      <?= hr_report_bars(array_map(static fn($r) => ['label' => hr_titlecase((string) $r['label']), 'n' => $r['n']], $leave['by_type'])) ?>
      <?php if ($leaveDept !== []): ?>
        <div class="sec-sub small text-muted mb-1 mt-2">Approved days by department</div>
        <?= hr_report_bars(array_map(static fn($r) => ['label' => (string) $r['label'], 'n' => (int) $r['days']], $leaveDept)) ?>
      <?php endif; ?>
      <?php if ($leaveEmp !== []): ?>
        <div class="sec-sub small text-muted mb-1 mt-2">Top employees by approved days</div>
        <div class="table-responsive" style="max-height:180px;overflow:auto">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Email</th><th>Days</th></tr></thead>
          <tbody>
          <?php foreach ($leaveEmp as $r): ?>
            <tr><td class="small"><?= esc((string) $r['email']) ?></td><td class="small"><?= (int) $r['days'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card p-3 h-100">
      <div class="sec-title mb-2">Recruitment</div>
      <div class="row g-2 mb-2">
        <div class="col-4"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Open roles</div><div class="fw-bold"><?= (int) $rec['open_count'] ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Vacancies</div><div class="fw-bold"><?= (int) $rec['vacancies'] ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Applicants</div><div class="fw-bold"><?= (int) $rec['candidate_total'] ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Selected</div><div class="fw-bold text-success"><?= (int) $rec['selected'] ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Rejected</div><div class="fw-bold text-danger"><?= (int) $rec['rejected'] ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Interviews due</div><div class="fw-bold"><?= (int) $rec['interviews_upcoming'] ?></div></div></div>
        <div class="col-4"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Interviews done</div><div class="fw-bold"><?= (int) $rec['interviews_done'] ?></div></div></div>
      </div>
      <div class="sec-sub small text-muted mb-1">Applicant pipeline</div>
      <?= hr_report_bars(array_map(static fn($r) => ['label' => hr_titlecase((string) $r['status']), 'n' => $r['n']], $rec['candidates'])) ?>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0">Payroll</div>
        <div class="small text-muted">Paid to date <?= esc(number_format((float) $pay['ytd_net'], 2)) ?></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Deductions</div><div class="fw-bold"><?= esc(number_format((float) $pay['deductions'], 2)) ?></div></div></div>
        <div class="col-6"><div class="border rounded p-2"><div class="text-muted" style="font-size:.72rem">Tax withheld</div><div class="fw-bold"><?= esc(number_format((float) $pay['tax'], 2)) ?></div></div></div>
      </div>
      <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Period</th><th>Headcount</th><th>Gross</th><th>Net</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($pay['runs'] as $r): ?>
          <tr>
            <td class="small"><?= esc((string) $r['period']) ?></td>
            <td class="small"><?= (int) $r['headcount'] ?></td>
            <td class="small"><?= esc(number_format((float) $r['total_gross'], 2)) ?></td>
            <td class="small"><?= esc(number_format((float) $r['total_net'], 2)) ?></td>
            <td class="small"><?= esc(hr_titlecase((string) $r['status'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($pay['runs'] === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No payroll runs.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="sec-title mb-0">Assets</div>
        <div class="small text-muted"><?= (int) $ast['assigned'] ?> assigned &middot; <?= (int) $ast['available'] ?> available &middot; <?= (int) $ast['returned'] ?> returned &middot; value <?= esc(number_format((float) $ast['value'], 2)) ?></div>
      </div>
      <div class="row g-3">
        <div class="col-12 col-md-6"><?= hr_report_bars(array_map(static fn($r) => ['label' => hr_titlecase((string) $r['status']), 'n' => $r['n']], $ast['by_status'])) ?></div>
        <div class="col-12 col-md-6"><?= hr_report_bars($ast['by_category']) ?></div>
      </div>
    </div>
  </div>
</div>
    <?php
    layout_close();
}
