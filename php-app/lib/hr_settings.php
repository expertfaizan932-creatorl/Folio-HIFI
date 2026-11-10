<?php
/**
 * WorkFolio PHP - HR module data layer: Settings.
 *
 * A tiny key/value store (hr_settings) plus the catalogue that names each key,
 * its group, type and default. The catalogue is the single source of truth, so
 * pages render themselves from it and callers read with hr_setting($key) which
 * falls back to the declared default when a key was never saved.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';

/**
 * Every configurable HR setting, grouped for the settings page.
 *   type: text | number | checkbox | select | textarea | time
 */
function hr_settings_schema(): array {
    return [
        'company' => [
            'label' => 'Company',
            'fields' => [
                'company_name'    => ['label' => 'Company name', 'type' => 'text', 'default' => 'WorkFolio'],
                'company_email'   => ['label' => 'HR contact email', 'type' => 'text', 'default' => ''],
                'currency'        => ['label' => 'Currency code', 'type' => 'text', 'default' => 'PKR'],
            ],
        ],
        'attendance' => [
            'label' => 'Attendance & Time',
            'fields' => [
                'workday_start'      => ['label' => 'Workday start', 'type' => 'time', 'default' => '09:00'],
                'workday_end'        => ['label' => 'Workday end', 'type' => 'time', 'default' => '18:00'],
                'grace_minutes'      => ['label' => 'Late grace (minutes)', 'type' => 'number', 'default' => '10'],
                'workweek'           => ['label' => 'Working days', 'type' => 'select', 'default' => 'MON_FRI',
                    'options' => ['MON_FRI' => 'Monday - Friday', 'MON_SAT' => 'Monday - Saturday', 'SUN_THU' => 'Sunday - Thursday']],
            ],
        ],
        'leave' => [
            'label' => 'Leave',
            'fields' => [
                'leave_year_start'   => ['label' => 'Leave year starts (month)', 'type' => 'select', 'default' => '01',
                    'options' => ['01' => 'January', '04' => 'April', '07' => 'July', '10' => 'October']],
                'annual_leave_days'  => ['label' => 'Default annual leave (days)', 'type' => 'number', 'default' => '20'],
                'carry_forward_days' => ['label' => 'Max carry-forward (days)', 'type' => 'number', 'default' => '5'],
            ],
        ],
        'payroll' => [
            'label' => 'Payroll',
            'fields' => [
                'payroll_day'        => ['label' => 'Salary day of month', 'type' => 'number', 'default' => '1'],
                'probation_months'   => ['label' => 'Probation (months)', 'type' => 'number', 'default' => '3'],
            ],
        ],
        'documents' => [
            'label' => 'Documents',
            'fields' => [
                'doc_expiry_alert_days' => ['label' => 'Expiry alert window (days)', 'type' => 'number', 'default' => '60'],
            ],
        ],
        'offboarding' => [
            'label' => 'Offboarding',
            'fields' => [
                'notice_period_days' => ['label' => 'Notice period (days)', 'type' => 'number', 'default' => '30'],
            ],
        ],
    ];
}

/** Flat map of key => ['type','default','label','options'?] across all groups. */
function hr_settings_fields(): array {
    $out = [];
    foreach (hr_settings_schema() as $group) {
        foreach ($group['fields'] as $key => $def) $out[$key] = $def;
    }
    return $out;
}

/* ---------------------------------------------------------------- storage ---- */

function hr_settings_all(bool $fresh = false): array {
    static $cache = null;
    if ($fresh) $cache = null;
    if ($cache === null) {
        try {
            $cache = [];
            foreach (rows('SELECT skey, svalue FROM hr_settings') as $r) {
                $cache[(string) $r['skey']] = (string) $r['svalue'];
            }
        } catch (Throwable $e) { $cache = []; }
    }
    return $cache;
}

function hr_setting(string $key, ?string $default = null): string {
    $all = hr_settings_all();
    if (array_key_exists($key, $all)) return (string) $all[$key];
    if ($default !== null) return $default;
    $fields = hr_settings_fields();
    return (string) ($fields[$key]['default'] ?? '');
}

function hr_setting_set(string $key, string $value, string $by): void {
    $fields = hr_settings_fields();
    if (!isset($fields[$key])) return; /* only known keys are writable */
    $existing = one('SELECT skey FROM hr_settings WHERE skey=?', [$key]);
    if ($existing !== null) {
        q('UPDATE hr_settings SET svalue=?, updated_by=?, updated_at=? WHERE skey=?',
            [$value, strtolower(trim($by)), now_sql(), $key]);
    } else {
        q('INSERT INTO hr_settings (skey,svalue,updated_by,updated_at) VALUES (?,?,?,?)',
            [$key, $value, strtolower(trim($by)), now_sql()]);
    }
    hr_settings_all(true);
}

/** Normalise a submitted value against its field type before storing. */
function hr_setting_normalise(array $def, $raw): string {
    $type = (string) ($def['type'] ?? 'text');
    if ($type === 'checkbox') return !empty($raw) ? '1' : '0';
    $v = trim((string) ($raw ?? ''));
    $v = str_replace("\0", '', $v);
    switch ($type) {
        case 'number':
            return preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : (string) $def['default'];
        case 'select':
            $opts = $def['options'] ?? [];
            return isset($opts[$v]) ? $v : (string) $def['default'];
        case 'time':
            return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : (string) $def['default'];
        case 'textarea':
            return substr(preg_replace("/\r\n?/", "\n", $v) ?? $v, 0, 4000);
        default:
            return substr($v, 0, 200);
    }
}

/** Persist a full submission; unknown keys are ignored. Returns keys saved. */
function hr_settings_save(array $post, string $by): int {
    $fields = hr_settings_fields();
    $n = 0;
    foreach ($fields as $key => $def) {
        /* Checkboxes are absent when unchecked, so always normalise from post. */
        if (($def['type'] ?? '') === 'checkbox') {
            $val = hr_setting_normalise($def, $post[$key] ?? '');
        } elseif (array_key_exists($key, $post)) {
            $val = hr_setting_normalise($def, $post[$key]);
        } else {
            continue; /* untouched optional field */
        }
        hr_setting_set($key, $val, $by);
        $n++;
    }
    return $n;
}
