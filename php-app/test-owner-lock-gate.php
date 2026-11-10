<?php
/* Owner-lock gate child: prints ALLOWED only if require_role lets an ADMIN
   through an owner-only gate. Denied path exits silently via redirect(). */
$tmp = sys_get_temp_dir() . '/wf-lock-gate-' . getmypid();
putenv('WF_STORAGE=' . $tmp);
putenv('WF_DB_DRIVER=sqlite');
if (!is_dir($tmp)) mkdir($tmp, 0777, true);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/usage.php';
$WF['db_driver'] = 'sqlite';
$WF['storage'] = $tmp;
ensure_schema();
require_once __DIR__ . '/lib/auth.php';

$pw = password_hash('secret123', PASSWORD_DEFAULT);
q('INSERT INTO employees (first_name,last_name,email,`role`,password,department,designation,join_date)
   VALUES (?,?,?,?,?,?,?,?)', ['Ad', 'Min', 'admin@x.test', 'ADMIN', $pw, 'IT', 'Admin', '2024-01-01']);
$_SESSION['uid'] = (int) val('SELECT id FROM employees WHERE email=?', ['admin@x.test']);

echo "BEFORE\n";
require_role(['SUPER_ADMIN']);      /* denied -> redirect()/exit */
echo "ALLOWED\n";
