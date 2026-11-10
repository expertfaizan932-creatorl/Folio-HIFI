<?php
/* Owner-lock smoke test: SUPER_ADMIN must be unreachable in this build. */
$tmp = sys_get_temp_dir() . '/wf-lock-' . getmypid();
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');
if (!is_dir($tmp)) mkdir($tmp, 0777, true);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/usage.php';
$WF['db_driver'] = 'sqlite';
$WF['storage'] = $tmp;
ensure_schema();
require_once __DIR__ . '/lib/auth.php';

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  - $label\n"; }
    else { $fail++; echo " FAIL - $label\n"; }
}

$owner = ['email' => 'owner@x.test', 'role' => 'SUPER_ADMIN'];
$admin = ['email' => 'admin@x.test', 'role' => 'ADMIN'];

ok('is_super_admin() is always false', is_super_admin($owner) === false);
ok('ADMIN cannot be granted SUPER_ADMIN', !in_array('SUPER_ADMIN', access_assignable_roles($admin), true));
ok('owner row itself cannot be granted SUPER_ADMIN', !in_array('SUPER_ADMIN', access_assignable_roles($owner), true));
ok('owner cannot read everyone', access_can_view($owner, $admin) === false);
ok('owner is not a supervisor', is_supervisor($owner) === false);
ok('owner is still hidden from rosters', !in_array($owner['email'], array_column(access_visible_employees($admin), 'email'), true));

/* An owner row in the DB must be refused at the door. */
$pw = password_hash('secret123', PASSWORD_DEFAULT);
q('INSERT INTO employees (first_name,last_name,email,`role`,password,department,designation,join_date)
   VALUES (?,?,?,?,?,?,?,?)', ['Ow', 'Ner', 'owner@x.test', 'SUPER_ADMIN', $pw, 'IT', 'Boss', '2024-01-01']);
ok('owner account cannot sign in', do_login('owner@x.test', 'secret123') === null);

/* require_role must drop SUPER_ADMIN from every allow-list (runs in a child
   process because the denied path exits through redirect()). */
$php = PHP_BINARY;
$gate = escapeshellarg(__DIR__ . '/test-owner-lock-gate.php');
$out = (string) shell_exec($php . ' -d extension=php_pdo_sqlite.dll ' . $gate . ' 2>&1');
ok('owner-only gate denies everyone', strpos($out, 'BEFORE') !== false && strpos($out, 'ALLOWED') === false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
