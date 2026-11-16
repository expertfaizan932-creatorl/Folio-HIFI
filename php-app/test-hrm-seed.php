<?php
/**
 * Seeds the throwaway SQLite database for test-hrm-e2e.php and prints the
 * employee's form token as JSON. Run as a separate process because the live
 * server has its own database handle.
 */
$tmp = $argv[1] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-hrm-seed');
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
require $root . '/lib/core.php';
require $root . '/lib/usage.php';
$WF['db_driver'] = 'sqlite';
$WF['storage'] = $tmp;

/* This is explicitly a throwaway database, and ensure_schema() only creates
 * tables when they are absent, so a second run would collide with the employee
 * the previous run inserted. Start from empty every time. */
if (!is_dir($tmp)) { mkdir($tmp, 0777, true); }
foreach (glob($tmp . '/*.sqlite') ?: [] as $stale) { unlink($stale); }

ensure_schema();
require_once $root . '/lib/auth.php';
require_once $root . '/lib/hrm.php';

$pw = password_hash('secret123', PASSWORD_DEFAULT);
q('INSERT INTO employees (first_name,last_name,email,`role`,password,department,designation,join_date)
   VALUES (?,?,?,?,?,?,?,?)', ['Ali', 'Raza', 'ali@folio.test', 'EMPLOYEE', $pw, 'IT', 'Dev', '2024-01-15']);

$rec = hrm_record('ali@folio.test');
echo json_encode([
    'token' => (string) $rec['form_token'],
    'email' => (string) $rec['email'],
]);