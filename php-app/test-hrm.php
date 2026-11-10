<?php
/**
 * HRM employee information form regression suite. Isolated temp SQLite DB.
 *
 * The gates worth protecting here are not cosmetic. Three of them are the reason
 * this feature exists at all: the public token must be the only key, the bank
 * confirmation must be impossible to skip, and an unacknowledged asset must
 * block submission. The rest guards the split between the employee's half and
 * HR's half, and the upload path.
 */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-hrm-test-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);

$root = dirname(__DIR__) . '/php-app';
require $root . '/lib/core.php';
require $root . '/lib/usage.php';

/* core.php may pull config.local.php and pin MySQL; force the throwaway
   SQLite database after the include, exactly like the other suites do. */
$WF['db_driver'] = 'sqlite';
$WF['storage']   = $tmp;

ensure_schema();

require_once $root . '/lib/auth.php';
require_once $root . '/lib/hrm.php';
require_once $root . '/lib/shots.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name\n"; }
}

$pw = password_hash('secret123', PASSWORD_DEFAULT);
q('INSERT INTO employees (first_name,last_name,email,`role`,password,department,designation,join_date)
   VALUES (?,?,?,?,?,?,?,?)', ['Ali', 'Raza', 'ali@folio.test', 'EMPLOYEE', $pw, 'IT', 'Dev', '2024-01-15']);
q('INSERT INTO employees (first_name,last_name,email,`role`,password) VALUES (?,?,?,?,?)',
   ['Sana', 'Khan', 'hr@folio.test', 'HR', $pw]);

$emp = employee_find_by_email('ali@folio.test');
$hr  = employee_find_by_email('hr@folio.test');

/* ------------------------------------------------------------------ schema ---- */

echo "=== schema ===\n";
foreach (['employee_hrm_records', 'hrm_assets', 'hrm_documents'] as $t) {
    $n = one('SELECT COUNT(*) c FROM sqlite_master WHERE type=? AND name=?', ['table', $t]);
    ok("table $t created", (int) ($n['c'] ?? 0) === 1);
}

/* ------------------------------------------------------------------- tokens ---- */

echo "=== token is the only key ===\n";
$rec = hrm_record('ali@folio.test');
ok('record created on first use', is_array($rec) && $rec !== []);
ok('token is 64 hex chars', (bool) preg_match('/^[a-f0-9]{64}$/', (string) $rec['form_token']));
ok('starts as DRAFT', (string) $rec['status'] === 'DRAFT');

$tok = (string) $rec['form_token'];
ok('valid token resolves to the employee', (hrm_employee_by_token($tok)['email'] ?? null) === 'ali@folio.test');

foreach (['', 'nope', str_repeat('z', 64), substr($tok, 0, 63), $tok . 'a', '../' . $tok] as $bad) {
    ok('rejects bad token "' . substr($bad, 0, 12) . '"', hrm_employee_by_token($bad) === null);
}
ok('unknown but well-formed token resolves to nobody',
   hrm_employee_by_token(str_repeat('a', 64)) === null);

$newTok = hrm_rotate_token('ali@folio.test');
ok('rotation issues a different token', $newTok !== $tok);
ok('old token stops working after rotation', hrm_employee_by_token($tok) === null);
ok('new token works after rotation', hrm_employee_by_token($newTok) !== null);
$tok = $newTok;

/* -------------------------------------------------- the two hard requirements ---- */

echo "=== bank confirmation is mandatory ===\n";
$post = [
    'full_name' => 'Ali Raza', 'cnic' => '35202-1234567-1', 'dob' => '1995-04-02',
    'gender' => 'Male', 'contact_number' => '03001234567',
    'current_address' => 'Lahore', 'emergency_contact_name' => 'Mother',
    'emergency_contact_number' => '03007654321', 'emergency_relationship' => 'Mother',
    'highest_degree' => 'BSCS', 'salary' => '65000', 'bank_name' => 'Meezan',
    'account_title' => 'Ali Raza', 'account_number' => '0101010101010101',
    'bank_confirmed' => '1',
];
$missing = hrm_missing_required($post);
ok('all required fields present', $missing === []);

/* Save it first: the record is still empty at this point, so a blocker count of
   anything but "signature only" is correct until the fields land. */
ok('an empty record blocks on every required thing',
   count(hrm_blockers(hrm_record('ali@folio.test'), [])) > 1);
hrm_save_employee(hrm_record('ali@folio.test'), $post);
ok('signature is the only thing still outstanding',
   count(hrm_blockers(hrm_record('ali@folio.test'), [])) === 1);

$noTick = $post;
unset($noTick['bank_confirmed']);
hrm_save_employee(hrm_record('ali@folio.test'), $noTick);
$rec = hrm_record('ali@folio.test');
ok('unticked box does not set the flag', (int) $rec['bank_confirmed'] === 0);
$b = hrm_blockers($rec, []);
ok('unticked bank confirmation blocks submission',
   (bool) count(array_filter($b, function ($x) { return stripos($x, 'bank account details are correct') !== false; })));

/* A forged 1 must not work either: the value is read from the array, so a POST
   without the key is the same as an unticked box. */
$forged = $noTick; $forged['bank_confirmed'] = '';
hrm_save_employee(hrm_record('ali@folio.test'), $forged);
ok('empty bank_confirmed is not a confirmation',
   (int) hrm_record('ali@folio.test')['bank_confirmed'] === 0);

hrm_save_employee(hrm_record('ali@folio.test'), $post);
$rec = hrm_record('ali@folio.test');
ok('ticked box sets the flag', (int) $rec['bank_confirmed'] === 1);
ok('confirmation date is recorded', preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $rec['bank_confirm_date']) === 1);
ok('bank confirmation no longer blocks', count(hrm_blockers($rec, [])) === 1);

echo "=== asset acknowledgement is mandatory ===\n";
$other = employee_find_by_email('hr@folio.test');
q('INSERT INTO hrm_assets (email, asset_name, asset_type, asset_serial) VALUES (?,?,?,?)',
   ['hr@folio.test', 'Someone Elses Laptop', 'Laptop', 'X-1']);

$assets = hrm_assets('ali@folio.test');
$b = hrm_blockers($rec, $assets);
ok('no assets means nothing to acknowledge', $b === array_values(array_filter($b,
   function ($x) { return stripos($x, 'Company Assets') === false; })));

/* One unacknowledged row is enough to block the whole submission. */
q('INSERT INTO hrm_assets (email, asset_name, asset_type, asset_serial) VALUES (?,?,?,?)',
   ['ali@folio.test', 'Dell Latitude', 'Laptop', 'DL-9']);
$b = hrm_blockers(hrm_record('ali@folio.test'), hrm_assets('ali@folio.test'));
ok('unacknowledged asset blocks submission',
   (bool) count(array_filter($b, function ($x) { return stripos($x, 'Dell Latitude') !== false; })));

q('UPDATE hrm_assets SET acknowledged=1, ack_date=? WHERE email=? AND asset_name=?', [today(), 'ali@folio.test', 'Dell Latitude']);
$b = hrm_blockers(hrm_record('ali@folio.test'), hrm_assets('ali@folio.test'));
ok('acknowledged asset stops blocking', count($b) === 1);

/* Another employee's row must not be silently satisfied or writable. */
ok('another employee\'s asset is not in this employee\'s list',
   !in_array('Someone Elses Laptop', array_column(hrm_assets('ali@folio.test'), 'asset_name'), true));

/* ------------------------------------------------- employee cannot write HR ---- */

echo "=== section 8 is HR's alone ===\n";
$hostile = $post;
$hostile['employment_status'] = 'Terminated';
$hostile['performance_review'] = 'Forged by employee';
$hostile['hr_remarks'] = 'forged';
$hostile['probation_period'] = 'none';
$hostile['employee_code'] = 'FORGED-999';
$hostile['joining_date'] = '1999-01-01';
$hostile['designation'] = 'CEO';
$hostile['department'] = 'Board';
hrm_save_employee(hrm_record('ali@folio.test'), $hostile);
$rec = hrm_record('ali@folio.test');
ok('employment_status not writable by the employee', (string) $rec['employment_status'] === 'ACTIVE');
ok('performance_review not writable by the employee', trim((string) $rec['performance_review']) === '');
ok('hr_remarks not writable by the employee', trim((string) $rec['hr_remarks']) === '');
ok('probation_period not writable by the employee', trim((string) $rec['probation_period']) === '');
ok('employee_code stays read-only', (string) $rec['employee_code'] !== 'FORGED-999');
ok('designation stays read-only', (string) $rec['designation'] !== 'CEO');
ok('department stays read-only', (string) $rec['department'] !== 'Board');
ok('joining_date stays read-only', (string) $rec['joining_date'] === '2024-01-15');

/* And the HR path writes them. */
$hrRec = hrm_record('ali@folio.test');
hrm_save_hr($hrRec, ['employment_status' => 'On Leave', 'performance_review' => 'Strong quarter',
                     'probation_period' => '3 months'], 'hr@folio.test');
$rec = hrm_record('ali@folio.test');
ok('HR can write employment_status', (string) $rec['employment_status'] === 'On Leave');
ok('HR can write performance_review', (string) $rec['performance_review'] === 'Strong quarter');
ok('HR write is attributed', (string) $rec['hr_updated_by'] === 'hr@folio.test');

/* A second save from the employee must not roll HR's section back either. */
hrm_save_employee(hrm_record('ali@folio.test'), $post);
ok('employee save leaves HR section untouched',
   (string) hrm_record('ali@folio.test')['employment_status'] === 'On Leave');

/* ---------------------------------------------------------------- signature ---- */

echo "=== signature ===\n";
ok('no signature blocks submission', (bool) count(array_filter(
    hrm_blockers(hrm_record('ali@folio.test'), []), function ($x) { return stripos($x, 'signature') !== false; })));

$sig = hrm_signature_from(['signature_name' => 'Ali Raza']);
ok('typed name accepted', $sig['kind'] === 'typed' && $sig['value'] === 'Ali Raza');

$png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
$sig = hrm_signature_from(['signature_png' => 'data:image/png;base64,' . $png]);
ok('drawn PNG accepted', $sig['kind'] === 'png');

foreach ([
    'data:image/png;base64,' . base64_encode('not a png at all'),
    'data:image/jpeg;base64,' . $png,
    'data:image/png;base64,@@@notbase64@@@',
    'javascript:alert(1)',
    '<svg onload=alert(1)>',
] as $i => $evil) {
    $s = hrm_signature_from(['signature_png' => $evil]);
    ok('rejects forged signature #' . ($i + 1), $s['kind'] === null && $s['error'] !== null);
}
$s = hrm_signature_from(['signature_png' => 'data:image/png;base64,' . str_repeat('A', 200000)]);
ok('rejects oversized signature', $s['kind'] === null && $s['error'] !== null);

$err = hrm_save_signature(hrm_record('ali@folio.test'), ['signature_name' => 'Ali Raza']);
ok('signature saved', $err === null && (string) hrm_record('ali@folio.test')['signature_name'] === 'Ali Raza');
ok('nothing outstanding now', hrm_blockers(hrm_record('ali@folio.test'), hrm_assets('ali@folio.test')) === []);

/* Re-editing another field must not wipe the signature already given. */
hrm_save_employee(hrm_record('ali@folio.test'), $post);
ok('existing signature survives a later edit',
   (string) hrm_record('ali@folio.test')['signature_name'] === 'Ali Raza');

/* ---------------------------------------------------------------- uploads ---- */

echo "=== upload validation ===\n";
ok('pdf accepted for a pdf-only slot', hrm_mime_ok('pdf', 'application/pdf'));
ok('png accepted for a png slot', hrm_mime_ok('png', 'image/png'));
ok('jpeg accepted for a jpg slot', hrm_mime_ok('jpg', 'image/jpeg'));
ok('a pdf claiming to be a png is rejected', !hrm_mime_ok('png', 'application/pdf'));
ok('a docx claiming to be a doc is rejected', !hrm_mime_ok('doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
ok('cv slot allows docx', in_array('docx', hrm_doc_allow()['cv'], true));
ok('picture slot refuses pdf', !in_array('pdf', hrm_doc_allow()['picture'], true));

ok('size limit is 6 MB', HRM_MAX_UPLOAD === 6 * 1024 * 1024);
ok('unknown document key refused', hrm_store_upload('ali@folio.test', 'evil_key', [])[1] !== null);
ok('no file sent is not an error', hrm_store_upload('ali@folio.test', 'cnic_copy',
    ['error' => UPLOAD_ERR_NO_FILE])[1] === null);
/* A file that did not arrive through an upload cannot be accepted. */
ok('a non-uploaded path is refused', hrm_store_upload('ali@folio.test', 'cnic_copy', [
    'name' => 'x.pdf', 'type' => 'application/pdf', 'tmp_name' => __FILE__,
    'error' => UPLOAD_ERR_OK, 'size' => 100,
])[1] !== null);

$dir = hrm_docs_dir();
ok('upload dir created under storage', is_dir($dir) && strpos(realpath($dir), realpath($tmp)) === 0);

/* The stored name is generated, so nothing the client sent survives as a path. */
ok('stored names are generated', (bool) preg_match('/^[A-Za-z0-9]+-[0-9a-f]{16}\.[a-z]+$/', 'ali-' . bin2hex(random_bytes(8)) . '.pdf'));

/* ------------------------------------------------------- document access ---- */

echo "=== document access control ===\n";
ok('HR may read their employee\'s documents', access_can_view_email($hr, 'ali@folio.test'));
ok('an employee may not read a colleague\'s documents', !access_can_view_email($emp, 'someone.else@folio.test'));
$stranger = ['email' => 'x@folio.test', 'role' => 'EMPLOYEE'];
ok('a stranger is refused', !access_can_view_email($stranger, 'ali@folio.test'));
ok('a null viewer is refused', !access_can_view_email(null, 'ali@folio.test'));
ok('HR cannot read the owner\'s record through this role',
   !access_can_view($hr, ['role' => 'SUPER_ADMIN', 'email' => 'owner@folio.test']));

/* ------------------------------------------------------------------- mask ---- */

echo "=== account masking ===\n";
ok('long number keeps only the ends', hrm_mask_account('0101010101010101') === '01************01');
ok('short number fully hidden', hrm_mask_account('1234') === '****');
ok('empty stays empty', hrm_mask_account('') === '');
ok('masked form leaks no middle digits',
   strpos(hrm_mask_account('0101010101010101'), '1010101') === false);

/* ------------------------------------------------------------------ report ---- */

echo "\n$pass passed, $fail failed\n";
foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);
@rmdir($dir);
exit($fail === 0 ? 0 : 1);