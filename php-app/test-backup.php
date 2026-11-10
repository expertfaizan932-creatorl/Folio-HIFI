<?php
/**
 * Database backup & recovery regression suite. Isolated temp SQLite DB.
 *
 * The restore path is the dangerous one, so it is exercised for real: take a
 * snapshot, change the database, restore, and prove the change is gone and a
 * pre-restore copy of the change survives. The HTTP layer (roles, CSRF, the
 * download headers) is covered by test-hr-modules-e2e.php.
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-backup-test-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);

$root = dirname(__DIR__) . '/php-app';
require $root . '/lib/core.php';
require $root . '/lib/usage.php'; // config_for(), which ensure_schema() calls

/* core.php may pull in config.local.php and pin MySQL; force the throwaway
   SQLite database after the include, exactly like test-superadmin.php does. */
$WF['db_driver'] = 'sqlite';
$WF['storage']   = $tmp;

ensure_schema();

require_once $root . '/lib/auth.php';
require_once $root . '/lib/activity.php';
require_once $root . '/lib/backup.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== '' ? " - $extra" : '') . "\n"; }
}

echo "=== backup create ===\n";
$before = (int) val('SELECT COUNT(*) FROM employees');
ok('the throwaway database has rows', $before > 0, 'count ' . $before);

$b = backup_create('manual');
ok('a backup file is written', is_file(backup_dir() . DIRECTORY_SEPARATOR . $b['name']));
ok('it is not empty', $b['size'] > 1000, 'size ' . $b['size']);
ok('it is identified as sqlite', $b['driver'] === 'sqlite', $b['driver']);
ok('it is labelled manual', $b['note'] === 'manual', $b['note']);
ok('the file name is safe', backup_valid_name($b['name']), $b['name']);

$listed = backup_list();
$found = false;
foreach ($listed as $l) { if ($l['name'] === $b['name']) $found = true; }
ok('the backup appears in the list', $found);

/* ------------------------------------------------------ change + recovery ---- */

echo "=== recovery ===";
$marker = 'backup-marker-' . getmypid() . '@folio.test';
q('INSERT INTO employees (first_name,last_name,email,`role`,password) VALUES (?,?,?,?,?)',
    ['Marker', 'Row', $marker, 'EMPLOYEE', 'x']);
ok('a change lands after the snapshot', (int) val('SELECT COUNT(*) FROM employees WHERE email=?', [$marker]) === 1);

$res = backup_restore($b['name']);
ok('the restore reports success', !empty($res['restored']));
ok('it kept a safety copy first', is_file(backup_dir() . DIRECTORY_SEPARATOR . $res['safety']),
    (string) ($res['safety'] ?? ''));
ok('the change is rolled back', (int) val('SELECT COUNT(*) FROM employees WHERE email=?', [$marker]) === 0);
ok('the original rows are intact', (int) val('SELECT COUNT(*) FROM employees') === $before);
ok('the database still answers queries after reconnect', (int) val('SELECT COUNT(*) FROM employees') === $before);

$listed = backup_list();
$safetySeen = false;
foreach ($listed as $l) { if ($l['name'] === $res['safety']) $safetySeen = true; }
ok('the safety copy is listed as pre-restore', $safetySeen, json_encode(array_column($listed, 'name')));

/* The safety copy itself is a working backup: restoring it brings the change
   back, which is what makes an accidental restore reversible. */
$res2 = backup_restore($res['safety']);
ok('the safety copy restores too', !empty($res2['restored']));
ok('the change is back when the safety copy is restored',
    (int) val('SELECT COUNT(*) FROM employees WHERE email=?', [$marker]) === 1);
$final = backup_restore($b['name']);
ok('and the original backup undoes it again', (int) val('SELECT COUNT(*) FROM employees WHERE email=?', [$marker]) === 0);

/* ---------------------------------------------------------- guards + utils ---- */

echo "=== guards and helpers ===\n";
ok('a traversal name is rejected', backup_path('../secrets') === null);
ok('an absolute path is rejected', backup_path('/etc/passwd') === null);
ok('a name that is not on disk is rejected', backup_path('wf-nope.sqlite') === null);
ok('restore of a missing backup throws', (function () {
    try { backup_restore('wf-does-not-exist.sqlite'); } catch (Throwable $e) { return true; }
    return false;
})());

$st = backup_split_statements("SELECT 'a;b' FROM t;-- note\nSELECT 2;");
ok('statements split on real boundaries only', count($st) === 2 && $st[0] === "SELECT 'a;b' FROM t",
    json_encode($st));
$st2 = backup_split_statements("INSERT INTO t VALUES ('it''s fine');\n");
ok('escaped quotes do not break the splitter', count($st2) === 1, json_encode($st2));

ok('sizes format sensibly', backup_bytes(0) === '0 B' && backup_bytes(2048) === '2 KB'
    && backup_bytes(5 * 1024 * 1024) === '5 MB', backup_bytes(2048));

echo "=== delete ===\n";
ok('the backup deletes', backup_delete($b['name']));
ok('and is gone from the list', backup_path($b['name']) === null);
ok('deleting twice fails cleanly', backup_delete($b['name']) === false);

/* --------------------------------------------------------------- teardown ---- */

foreach (glob($tmp . '/backups/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/backups');
foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
