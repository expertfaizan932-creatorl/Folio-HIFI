<?php
/**
 * Reports any SELECT in the app that names a column the database does not have.
 *
 * Why this exists: /hrm shipped as a 500 because hrm_status_map() selected
 * employee_hrm_records.updated_at, a column the table never had (the real one is
 * last_saved_at). The SQLite unit tests could not catch it because they exercise
 * the HRM helpers, never page_hrm_overview(), so no test ever ran that query.
 * Comparing what the code asks for against what the schema really has is the
 * only way to catch that class of bug before someone opens the page.
 *
 * Run from anywhere:  php tools/colcheck.php
 * Exits 1 if anything is missing, so it can gate a deploy.
 */

$root = dirname(__DIR__);
chdir($root);
require 'lib/core.php';

$pdo = wf_pdo();
$tables = [];
foreach ($pdo->query('SHOW TABLES') as $r) { $tables[] = array_values($r)[0]; }

$cols = [];
foreach ($tables as $t) {
    $set = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) { $set[strtolower($c['Field'])] = true; }
    $cols[strtolower($t)] = $set;
}
echo 'tables: ' . implode(' ', $tables) . "\n\n";

$files = array_merge(glob('lib/*.php'), glob('*.php'), glob('lib/*/*.php'));
$problems = 0;

foreach ($files as $f) {
    $src = file_get_contents($f);
    if (!preg_match_all('/SELECT\s+(.+?)\s+FROM\s+`?([a-z_]+)`?/is', $src, $m, PREG_SET_ORDER)) continue;

    foreach ($m as $hit) {
        $select = $hit[1];
        $table = strtolower($hit[2]);
        if (!isset($cols[$table])) continue;

        foreach (preg_split('/\s*,\s*/', trim($select), -1, PREG_SPLIT_NO_EMPTY) as $id) {
            $id = trim($id, " \t\n\r`");
            // skip wildcards and expressions (COUNT(...), SUM(...), aliases)
            if ($id === '' || strpos($id, '(') !== false || strpos($id, '*') !== false) continue;
            if (preg_match('/\bAS\b/i', $id)) {
                $parts = preg_split('/\s+AS\s+/i', $id);
                $id = trim($parts[0]);
            }
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $id)) continue;
            if (isset($cols[$table][strtolower($id)])) continue;

            echo "MISSING  $f  $table.$id\n";
            $problems++;
        }
    }
}

echo $problems === 0
    ? "no missing columns detected\n"
    : "\n$problems potential missing-column reference(s)\n";

exit($problems === 0 ? 0 : 1);