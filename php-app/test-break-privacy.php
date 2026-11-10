<?php
/**
 * Regression harness for the laptop-aware timing + break privacy rules.
 *
 * Runs against a throwaway SQLite database in a temp dir, pointed there by the
 * WF_* environment variables before core.php is loaded. The project's real
 * database is never opened.
 *
 * The bug this guards against: track_settle() used to close an in-progress
 * break on every heartbeat. That flipped the record back to WORKING while the
 * employee was still away, which re-exposed the last pre-break window to HR and
 * left the break-log entry unclosed.
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wf-break-test-' . getmypid();
@mkdir($tmp, 0777, true);
putenv('WF_STORAGE=' . $tmp);

$root = dirname(__DIR__) . '/php-app';
require $root . '/lib/core.php';
require $root . '/lib/tracks.php';
require $root . '/lib/usage.php'; // config_for(), which ensure_schema() calls

// core.php pulls in config.local.php, which may pin MySQL. Point the connection
// at the throwaway database AFTER the include, since wf_pdo() reads $WF lazily.
$WF['db_driver'] = 'sqlite';
$WF['storage']   = $tmp;

ensure_schema();

$pass = 0;
$fail = 0;
function check(string $label, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        printf("  PASS  %s\n", $label);
    } else {
        $fail++;
        printf("  FAIL  %s\n         got:  %s\n         want: %s\n",
            $label, var_export($got, true), var_export($want, true));
    }
}

$email = 'harness@hifimarketing.co';
$date  = today();
$emp   = ['email' => $email, 'role' => 'EMPLOYEE', 'name' => 'Harness'];

function fresh_record(string $email, string $date): void {
    $pdo = wf_pdo();
    $pdo->prepare('DELETE FROM track_records WHERE email=?')->execute([$email]);
    $pdo->prepare('DELETE FROM break_records WHERE email=?')->execute([$email]);
    $pdo->prepare(
        'INSERT INTO track_records
           (email, work_date, check_in, last_seen, status, last_status,
            active_seconds, idle_seconds, break_seconds, paused_seconds,
            current_app, current_title, current_host, current_category)
         VALUES (?,?,?,?,?,?,0,0,0,0,?,?,?,?)'
    )->execute([
        $email, $date,
        date('Y-m-d H:i:s', strtotime('-1 hour')),
        date('Y-m-d H:i:s', strtotime('-5 minutes')),
        STATUS_WORKING, 'ACTIVE',
        'Figma', 'Design file', 'figma.com', 'APP',
    ]);
}

// track_apply_totals() takes the record by reference, so it needs a variable -
// passing track_record_for() straight in would silently mutate a copy.
function apply_totals(array $totals): void {
    $r = track_record_for('harness@hifimarketing.co', today());
    track_apply_totals($r, $totals);
    track_save($r);
}

/**
 * Move break_start (and last_seen) $secs into the past, so a test can pretend
 * the break has been running for a while without the suite sleeping. Returns
 * the moment it was called, so the caller can build a realistic agent total.
 */
function backdate_break(int $secs): int {
    $t0 = time();
    $pdo = wf_pdo();
    $at = date('Y-m-d H:i:s', $t0 - $secs);
    $pdo->prepare('UPDATE track_records SET break_start=?, last_seen=? WHERE email=? AND work_date=?')
        ->execute([$at, $at, 'harness@hifimarketing.co', today()]);
    return $t0;
}

echo "\n== 1. a break survives routine heartbeats ==\n";
fresh_record($email, $date);
track_break_start($emp);
$r = track_record_for($email, $date);
check('status is still ON_BREAK', $r['status'], STATUS_BREAK);
check('break_start is still set', !empty($r['break_start']), true);

// The break has been running 5 minutes. A real agent's absolute breakSec always
// equals the break in progress, so that is what the heartbeats report.
$t0 = backdate_break(300);
$agentBreak = fn(): int => 300 + (time() - $t0); // stands in for the agent clock
// Two heartbeats, exactly what the agent sends every 30s while on break.
track_heartbeat($emp, 'ACTIVE', ['breakSec' => $agentBreak(), 'activeSec' => 1800, 'pausedSec' => 120]);
track_heartbeat($emp, 'ACTIVE', ['breakSec' => $agentBreak(), 'activeSec' => 1800, 'pausedSec' => 120]);
$r = track_record_for($email, $date);
check('status survives 2 heartbeats', $r['status'], STATUS_BREAK);
check('break_start still set', !empty($r['break_start']), true);

echo "\n== 2. no pre-break window leaks while on break ==\n";
check('current_app cleared', $r['current_app'], null);
check('current_title cleared', $r['current_title'], null);
check('current_host cleared', $r['current_host'], null);
check('current_category cleared', $r['current_category'], null);

echo "\n== 3. a telemetry batch during the break cannot restore window data ==\n";
track_record_telemetry($emp, [
    'status' => 'ACTIVE', 'app' => 'Chrome', 'title' => 'Gmail',
    'host' => 'mail.google.com', 'category' => 'APP',
    'breakSec' => $agentBreak(), 'activeSec' => 1800, 'pausedSec' => 120,
]);
$r = track_record_for($email, $date);
check('status still ON_BREAK', $r['status'], STATUS_BREAK);
check('app still hidden', $r['current_app'], null);
check('title still hidden', $r['current_title'], null);
check('host still hidden', $r['current_host'], null);

echo "\n== 4. break time is not double counted ==\n";
// The break opened in section 1 is still the running one. The agent's absolute
// total must read as break_seconds(finished) + the running break, not be stored
// on top of it.
$abs = $agentBreak();
apply_totals(['breakSec' => $abs]);
$r = track_record_for($email, $date);
check('the running break is not stored as finished break time', (int) $r['break_seconds'] <= 3, true);
$sum = track_summary($emp, $date);
check('summary breakSec tracks the agent total', abs((int) $sum['breakSec'] - $abs) <= 3, true);
$log = break_records_for($email, $date);
check('one open break-log row', count($log), 1);
check('break-log row is still open', $log[0]['end'], null);

echo "\n== 5. break log closes and totals are right on break end ==\n";
track_break_end($emp);
$r = track_record_for($email, $date);
check('status back to WORKING', $r['status'], STATUS_WORKING);
check('break_start cleared', empty($r['break_start']), true);
$log = break_records_for($email, $date);
check('break-log row now closed', $log[0]['end'] !== null, true);
check('summary breakSec matches the break log', abs((int) $r['break_seconds'] - (int) $log[0]['seconds']) <= 3, true);
check('summary breakSec is not double the break', (int) $r['break_seconds'] <= $abs + 3, true);
check('summary breakSec is at least the break log', (int) $r['break_seconds'] >= (int) $log[0]['seconds'] - 3, true);

echo "\n== 6. worked time excludes paused ==\n";
fresh_record($email, $date);
wf_pdo()->prepare('UPDATE track_records SET active_seconds=18000, idle_seconds=3600, paused_seconds=7200, status=?, last_seen=? WHERE email=? AND work_date=?')
    ->execute([STATUS_WORKING, now_sql(), $email, $date]);
$r = track_record_for($email, $date);
check('worked = active + idle (6h)', track_worked_seconds($r), 21600);

echo "\n== 7. paused accumulates and never rewinds ==\n";
fresh_record($email, $date);
apply_totals(['pausedSec' => 600]);
apply_totals(['pausedSec' => 900]);
check('paused took the larger total', (int) track_record_for($email, $date)['paused_seconds'], 900);
apply_totals(['pausedSec' => 300]);
check('a late stale batch cannot rewind paused', (int) track_record_for($email, $date)['paused_seconds'], 900);

echo "\n== 8. check-out settles work but keeps paused out of worked ==\n";
fresh_record($email, $date);
wf_pdo()->prepare('UPDATE track_records SET active_seconds=3600, idle_seconds=600, paused_seconds=1800, status=?, last_seen=? WHERE email=? AND work_date=?')
    ->execute([STATUS_WORKING, now_sql(), $email, $date]);
$r = track_record_for($email, $date);
check('worked before checkout', track_worked_seconds($r), 4200);
track_check_out($emp);
$r = track_record_for($email, $date);
check('status OFF after checkout', $r['status'], STATUS_OFF);
check('paused preserved through checkout', (int) $r['paused_seconds'], 1800);
check('worked unchanged by checkout', track_worked_seconds($r), 4200);

echo "\n== 9. the server refuses activity uploads while on break ==\n";
// agent_on_break() is the single decision the usage / screenshot / live-frame
// endpoints each consult, so testing it covers the gate for all three.
require_once $root . '/lib/api_agent.php';
fresh_record($email, $date);
check('not on break while working', agent_on_break($emp), false);
track_break_start($emp);
check('on break right after break start', agent_on_break($emp), true);
// Still true after heartbeats, i.e. the gate does not lapse mid-break.
track_heartbeat($emp, 'ACTIVE', ['breakSec' => 0]);
check('gate holds after a heartbeat', agent_on_break($emp), true);
track_break_end($emp);
check('gate lifts when the break ends', agent_on_break($emp), false);

// A record left in ON_BREAK with no break_start is not a live break and must
// not block the employee from working.
fresh_record($email, $date);
wf_pdo()->prepare('UPDATE track_records SET status=?, last_seen=? WHERE email=? AND work_date=?')
    ->execute([STATUS_BREAK, now_sql(), $email, $date]);
check('stale ON_BREAK with no break_start does not block work', agent_on_break($emp), false);
$clean = wf_pdo();
$clean->prepare('DELETE FROM track_records WHERE email=?')->execute([$email]);
$clean->prepare('DELETE FROM break_records WHERE email=?')->execute([$email]);

printf("\n%s: %d passed, %d failed\n", $fail === 0 ? 'ALL PASS' : 'FAILURES', $pass, $fail);
exit($fail === 0 ? 0 : 1);