<?php
/**
 * Seeds the throwaway SQLite database for test-hr-e2e.php.
 *
 * ensure_schema() runs first so the default owner (admin@workfolio.com /
 * admin123) is created while the roster is still empty; the two employees are
 * added afterwards so the HR screens have something to list.
 */
$tmp = $argv[1] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-hr-seed');
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');

$root = dirname(__DIR__) . '/php-app';
require $root . '/lib/core.php';
require $root . '/lib/usage.php';
$WF['db_driver'] = 'sqlite';
$WF['storage'] = $tmp;

if (!is_dir($tmp)) { mkdir($tmp, 0777, true); }
foreach (glob($tmp . '/*.sqlite') ?: [] as $stale) { unlink($stale); }

ensure_schema();
require_once $root . '/lib/auth.php';

$pw = password_hash('secret123', PASSWORD_DEFAULT);
q('INSERT INTO employees (first_name,last_name,email,`role`,password,department,designation,join_date)
   VALUES (?,?,?,?,?,?,?,?)', ['Ali', 'Raza', 'ali@folio.test', 'EMPLOYEE', $pw, 'IT', 'Developer', '2024-01-15']);
q('INSERT INTO employees (first_name,last_name,email,`role`,password,department,designation,join_date)
   VALUES (?,?,?,?,?,?,?,?)', ['Sara', 'Khan', 'sara@folio.test', 'EMPLOYEE', $pw, 'HR', 'Officer', '2024-03-01']);
q('INSERT INTO employees (first_name,last_name,email,`role`,password,department,designation,join_date)
   VALUES (?,?,?,?,?,?,?,?)', ['Omar', 'Ahmed', 'lead@folio.test', 'EMPLOYEE', $pw, 'Engineering', 'Team Lead', '2023-06-01']);

echo json_encode(['emp_id' => (int) val('SELECT id FROM employees WHERE email=?', ['ali@folio.test'])]);
