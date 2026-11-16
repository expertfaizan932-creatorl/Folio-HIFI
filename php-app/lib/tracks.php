<?php
/**
 * WorkFolio PHP - TrackService port.
 *
 * Everything about one employee-day: check-in / check-out / breaks, the active/
 * idle/break time buckets, mouse+keyboard counters, the current window, and the
 * summary map the agent and the reports both consume. Key maps use the exact
 * camelCase keys the Spring server emitted, so the existing WorkFolioAgent.exe
 * keeps parsing them unchanged.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/activity.php';

const STATUS_OFF = 'OFF';
const STATUS_WORKING = 'WORKING';
const STATUS_BREAK = 'ON_BREAK';

/* ------------------------------------------------------------- records ----- */

function track_record_for(string $email, string $date): array {
    $r = one('SELECT * FROM track_records WHERE email=? AND work_date=?', [$email, $date]);
    if ($r !== null) return $r;
    try {
        q('INSERT INTO track_records (email, work_date, status, last_status) VALUES (?,?,?,?)',
            [$email, $date, STATUS_OFF, 'ACTIVE']);
    } catch (Throwable $e) {
        /* concurrent insert from another process - fall through to the select */
    }
    $r = one('SELECT * FROM track_records WHERE email=? AND work_date=?', [$email, $date]);
    if ($r !== null) return $r;
    return ['email' => $email, 'work_date' => $date, 'status' => STATUS_OFF, 'last_status' => 'ACTIVE'];
}

function track_save(array $r): void {
    q('UPDATE track_records SET check_in=?, check_out=?, break_start=?, last_seen=?, break_seconds=?, active_seconds=?, idle_seconds=?, paused_seconds=?, totals_locked=?, manual_locked=?, screenshot_count=?, status=?, last_status=?, keystrokes=?, mouse_clicks=?, mouse_scrolls=?, mouse_distance_px=?, current_app=?, current_title=?, current_host=?, current_category=?, distinct_apps=?, distinct_sites=?, host_name=?, agent_version=?, agent_ip=?, last_shot_at=? WHERE id=?', [
        $r['check_in'] ?? null, $r['check_out'] ?? null, $r['break_start'] ?? null, $r['last_seen'] ?? null,
        (int) ($r['break_seconds'] ?? 0), (int) ($r['active_seconds'] ?? 0), (int) ($r['idle_seconds'] ?? 0),
        (int) ($r['paused_seconds'] ?? 0), (int) ($r['totals_locked'] ?? 0), (int) ($r['manual_locked'] ?? 0),
        (int) ($r['screenshot_count'] ?? 0), $r['status'] ?? STATUS_OFF, $r['last_status'] ?? 'ACTIVE',
        (int) ($r['keystrokes'] ?? 0), (int) ($r['mouse_clicks'] ?? 0), (int) ($r['mouse_scrolls'] ?? 0),
        (int) ($r['mouse_distance_px'] ?? 0), $r['current_app'] ?? null, $r['current_title'] ?? null,
        $r['current_host'] ?? null, $r['current_category'] ?? null, (int) ($r['distinct_apps'] ?? 0),
        (int) ($r['distinct_sites'] ?? 0), $r['host_name'] ?? null, $r['agent_version'] ?? null,
        $r['agent_ip'] ?? null, $r['last_shot_at'] ?? null, (int) $r['id'],
    ]);
}

/* ------------------------------------------------------------ derived ---- */

function track_on_duty(array $r): bool {
    return $r['status'] !== STATUS_OFF && !empty($r['check_in']);
}

/**
 * Has the owner typed this day's numbers by hand (Manual Update)?
 *
 * A manually written day is frozen: the agent's settle, shift totals, clock
 * in/out and break events all leave the row alone, so what the owner typed is
 * exactly what every report shows. Any other day is untouched.
 */
function track_manual_locked(array $r): bool {
    return (int) ($r['manual_locked'] ?? 0) === 1;
}

/**
 * Worked time for the day: active + idle, which is what the employee actually
 * spent on duty.
 *
 * The agent counts those two buckets on a monotonic Stopwatch, so they already
 * exclude time the machine was folded or locked. The wall-clock derivation below
 * is only a fallback for rows written by an older agent that never sent buckets
 * at all - and even then it is bounded by the end of the record's own day, so a
 * row left open (laptop off, crash, no clock-out) cannot keep growing every time
 * the report is opened. It used to measure against `now()`, which meant a
 * forgotten check-out from last week grew by another week on every page view.
 */
function track_worked_seconds(array $r): int {
    if (empty($r['check_in'])) return 0;
    $buckets = (int) ($r['active_seconds'] ?? 0) + (int) ($r['idle_seconds'] ?? 0);
    if ($buckets > 0) return $buckets;

    $start = ts_of($r['check_in']);
    if ($start === null) return 0;

    $day = (string) ($r['work_date'] ?? '');
    $dayEnd = $day !== '' ? day_end_ts($day) : time();

    $end = null;
    if (!empty($r['check_out'])) $end = ts_of($r['check_out']);
    if ($end === null) $end = min(time(), $dayEnd);

    $base = $end - $start;
    if ($base < 0) $base = 0;
    $w = $base
        - (int) ($r['break_seconds'] ?? 0)
        - (int) ($r['paused_seconds'] ?? 0);
    if (!empty($r['break_start'])) {
        $bs = ts_of($r['break_start']);
        if ($bs !== null) $w -= max(0, $end - $bs);
    }
    return max(0, $w);
}

function track_productivity_percent(array $r): int {
    $denom = (int) ($r['active_seconds'] ?? 0) + (int) ($r['idle_seconds'] ?? 0);
    if ($denom <= 0) return 0;
    return (int) round(((int) ($r['active_seconds'] ?? 0) * 100.0) / $denom);
}

/* ---------------------------------------------------------- workday target -- */

/**
 * The daily productive-time target in seconds, from the app config.
 *
 * This is the same target the desktop agent enforces (ProductivityTarget), so a
 * day is judged identically in the tray and in the reports. It is the "worked"
 * figure - active + idle, breaks and paused time excluded - that is compared,
 * because that is what the agent calls eligible productive time.
 */
/**
 * The daily expected-hours target: per-employee override, then the company-wide
 * override the owner set on Manual Update, then the app config (default 8).
 *
 * The owner changes this without a deploy, per person or company-wide, so the
 * "8 hours" the tray and every report judge a day against is just a starting
 * value, not a rule.
 */
function track_target_hours(?string $email = null): float {
    if ($email !== null && $email !== '') {
        $h = val('SELECT target_hours FROM manual_user_prefs WHERE email=?', [$email]);
        if ($h !== null && $h !== '' && (float) $h > 0) return (float) $h;
    }
    $g = val("SELECT svalue FROM manual_settings WHERE skey='target_hours'");
    if ($g !== null && $g !== '' && (float) $g > 0) return (float) $g;
    $hours = isset($GLOBALS['WF']['workday_target_hours']) ? (float) $GLOBALS['WF']['workday_target_hours'] : 8.0;
    if ($hours <= 0) $hours = 8.0;
    return $hours;
}

function track_target_seconds(?string $email = null): int {
    return (int) round(track_target_hours($email) * 3600);
}

/**
 * Classifies a day's worked seconds against the target.
 * Returns BELOW, ON or ABOVE. Zero worked time returns NONE so a day with no
 * record reads as "no time" rather than a red "below target".
 */
function track_target_status(int $workedSec, ?string $email = null): string {
    if ($workedSec <= 0) return 'NONE';
    $target = track_target_seconds($email);
    if ($workedSec < $target) return 'BELOW';
    if ($workedSec === $target) return 'ON';
    return 'ABOVE';
}

/** Human label for a target status key. */
function track_target_label(string $status): string {
    switch ($status) {
        case 'BELOW': return 'Below target';
        case 'ON':    return 'On target';
        case 'ABOVE': return 'Above target';
        default:      return 'No time';
    }
}

/** Bootstrap badge class for a target status key. */
function track_target_badge(string $status): string {
    switch ($status) {
        case 'BELOW': return 'bg-warning text-dark';
        case 'ON':    return 'bg-success';
        case 'ABOVE': return 'bg-primary';
        default:      return 'bg-light text-dark';
    }
}

/** Ready-to-render target badge for a worked-seconds value (used by the reports). */
function track_target_chip(int $workedSec, ?string $email = null): string {
    $status = track_target_status($workedSec, $email);
    return '<span class="badge ' . track_target_badge($status) . '">' . esc(track_target_label($status)) . '</span>';
}

/** The target in hours, trimmed for headings: "8", "7.5". */
function track_target_hours_text(?string $email = null): string {
    $s = number_format(track_target_hours($email), 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

function track_check_in_text(array $r): string { return hhmm_a($r['check_in'] ?? null); }
function track_check_out_text(array $r): string { return hhmm_a($r['check_out'] ?? null); }
function track_last_shot_text(array $r): string { return hhmm_a($r['last_shot_at'] ?? null); }

/** Seconds a break that is still open has been running, from its stored start. */
function track_running_break(array $r): int {
    if (empty($r['break_start'])) return 0;
    $bs = ts_of($r['break_start']);
    return $bs === null ? 0 : max(0, time() - $bs);
}

/**
 * Longest span of wall-clock time a single settle may attribute to work.
 *
 * A legacy agent that never sends shift buckets forces the server to measure
 * elapsed time itself, and one missed heartbeat would otherwise dump the whole
 * gap into "active" - the employee closes the lid for an hour and the report
 * says they worked an hour. Five minutes is far longer than the 30s heartbeat
 * interval, so a live agent is never affected, and anything beyond it is
 * treated as absence rather than work.
 */
const TRACK_MAX_SETTLE_SECONDS = 300;

/**
 * Rolls elapsed time into active/idle buckets. Called before every state change
 * so no second is lost or double counted, even if the heartbeat is irregular.
 *
 * Once the agent has reported its own buckets for this day (totals_locked) this
 * function stops adding anything at all. Both sources used to be written to the
 * same columns and the larger number won, which is precisely backwards: the
 * agent's monotonic clock keeps a correct rate through sleep while the server's
 * wall clock does not, so the wall clock could only ever overstate the shift.
 */
function track_settle(array $r): array {
    $now = time();
    $seen = empty($r['last_seen']) ? null : ts_of($r['last_seen']);
    if (empty($r['check_in']) || $seen === null) {
        $r['last_seen'] = now_sql();
        return $r;
    }
    if ($r['status'] === STATUS_OFF) return $r;
    $elapsed = $now - $seen;
    if ($elapsed <= 0) return $r;
    if ($r['status'] === STATUS_BREAK) {
        // A break in progress must survive routine heartbeats and telemetry.
        // Closing it here would silently end the break on the server while the
        // employee is still away, which would re-expose the last pre-break
        // window to HR and leave the break-log entry unclosed. The running break
        // lives in break_start and is added by track_summary / track_break_end,
        // so there is nothing to settle and no time to attribute to work.
        $r['last_seen'] = now_sql();
        return $r;
    }
    if ((int) ($r['totals_locked'] ?? 0) === 1 || track_manual_locked($r)) {
        // The agent owns this day's numbers (or the owner typed them by hand);
        // only the heartbeat stamp moves.
        $r['last_seen'] = now_sql();
        return $r;
    }
    $elapsed = min($elapsed, TRACK_MAX_SETTLE_SECONDS);
    if (strcasecmp((string) ($r['last_status'] ?? 'ACTIVE'), 'IDLE') === 0) {
        $r['idle_seconds'] = (int) ($r['idle_seconds'] ?? 0) + $elapsed;
    } else {
        $r['active_seconds'] = (int) ($r['active_seconds'] ?? 0) + $elapsed;
    }
    $r['last_seen'] = now_sql();
    return $r;
}

/* ---------------------------------------------------------- attendance ---- */

/**
 * Fold the agent's authoritative shift totals into the record.
 *
 * The agent counts on a monotonic Stopwatch, so its numbers survive a folded
 * laptop, a changed system clock and an NTP correction, none of which the
 * server wall clock survives. That makes them the truth for the shift, and the
 * server's own elapsed-time accounting is switched off for the day the moment
 * the first total arrives (track_settle reads totals_locked).
 *
 * Two rules keep that safe:
 *
 *   - a bucket is only touched when the agent actually sent it. An absent key is
 *     NOT the same as zero: "this client does not report shift totals" must
 *     never be read as "this employee worked no seconds", which is how a day's
 *     total used to be able to vanish.
 *   - within one day a total only grows, so a batch that arrives late (a
 *     retried request, a queued offline entry) cannot rewind the day. Nothing
 *     lowers a total any more: a second shift adds to the day rather than
 *     replacing it, so a restarted agent reporting a smaller number is ignored
 *     instead of wiping the morning.
 *
 * breakSec is absolute and includes the break in progress, while the server's
 * break_seconds means "breaks already finished". Subtracting the running break
 * keeps track_summary and track_break_end from adding the same seconds twice.
 */
function track_apply_totals(array &$r, array $totals): void {
    /* A hand-written day belongs to the owner: the agent's monotonic totals
       never lower it, never raise it, never unlock it. */
    if (track_manual_locked($r)) return;
    $locked = false;
    foreach ([
        'activeSec' => 'active_seconds', 'idleSec' => 'idle_seconds',
        'breakSec' => 'break_seconds', 'pausedSec' => 'paused_seconds',
    ] as $k => $col) {
        if (!array_key_exists($k, $totals) || $totals[$k] === null || !is_numeric($totals[$k])) continue;
        $locked = true;
        $value = (int) $totals[$k];
        if ($col === 'break_seconds' && !empty($r['break_start'])) {
            $bs = ts_of($r['break_start']);
            if ($bs !== null) $value = max(0, $value - max(0, time() - $bs));
        }
        $r[$col] = max((int) ($r[$col] ?? 0), $value);
    }
    if ($locked) $r['totals_locked'] = 1;
}

/**
 * One row per finished break, so reports can show "took 3 breaks: 12m, 5m, 18m"
 * instead of a single day total.
 */
function break_record_open(string $email, string $date, string $startedAt): void {
    try {
        q('INSERT INTO break_records (email, work_date, break_start, seconds) VALUES (?,?,?,0)',
            [$email, $date, $startedAt]);
    } catch (Throwable $e) {
        /* already recorded for this start stamp */
    }
}

function break_record_close(string $email, string $date, int $seconds): void {
    try {
        q('UPDATE break_records SET break_end=?, seconds=? WHERE email=? AND work_date=? AND break_end IS NULL',
            [now_sql(), max(0, $seconds), $email, $date]);
    } catch (Throwable $e) {
        error_log('folio: break_record_close failed: ' . $e->getMessage());
    }
}

/** Every break taken on a date, longest first. */
function break_records_for(string $email, string $date): array {
    $rows = rows('SELECT break_start, break_end, seconds FROM break_records WHERE email=? AND work_date=? ORDER BY break_start ASC',
        [$email, $date]);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'start'  => hhmm_a($row['break_start'] ?? null),
            'end'    => empty($row['break_end']) ? null : hhmm_a($row['break_end']),
            'seconds'=> (int) ($row['seconds'] ?? 0),
            'dur'    => fmt_dur((int) ($row['seconds'] ?? 0)),
        ];
    }
    return $out;
}

function track_summary(array $emp, string $date): array {
    $r = track_record_for($emp['email'], $date);
    $breakNow = (int) ($r['break_seconds'] ?? 0) + track_running_break($r);
    return [
        'status'           => $r['status'] ?? STATUS_OFF,
        'onDuty'           => track_on_duty($r),
        'manualLocked'     => track_manual_locked($r),
        'targetHours'      => track_target_hours($emp['email'] ?? null),
        'targetSec'        => track_target_seconds($emp['email'] ?? null),
        'checkIn'          => track_check_in_text($r),
        'checkOut'         => track_check_out_text($r),
        'worked'           => fmt_dur(track_worked_seconds($r)),
        'active'           => fmt_dur((int) ($r['active_seconds'] ?? 0)),
        'idle'             => fmt_dur((int) ($r['idle_seconds'] ?? 0)),
        'break'            => fmt_dur($breakNow),
        'paused'           => fmt_dur((int) ($r['paused_seconds'] ?? 0)),
        'screenshots'      => (int) ($r['screenshot_count'] ?? 0),
        'date'             => $r['work_date'] ?? $date,
        'workedSec'        => track_worked_seconds($r),
        'activeSec'        => (int) ($r['active_seconds'] ?? 0),
        'idleSec'          => (int) ($r['idle_seconds'] ?? 0),
        'breakSec'         => $breakNow,
        'pausedSec'        => (int) ($r['paused_seconds'] ?? 0),
        'productivity'     => track_productivity_percent($r),
        'keystrokes'       => (int) ($r['keystrokes'] ?? 0),
        'mouseClicks'      => (int) ($r['mouse_clicks'] ?? 0),
        'mouseScrolls'     => (int) ($r['mouse_scrolls'] ?? 0),
        'mouseDistancePx'  => (int) ($r['mouse_distance_px'] ?? 0),
        'currentApp'       => $r['current_app'] ?? null,
        'currentTitle'     => $r['current_title'] ?? null,
        'currentHost'      => $r['current_host'] ?? null,
        'currentCategory'  => $r['current_category'] ?? null,
        'distinctApps'     => (int) ($r['distinct_apps'] ?? 0),
        'distinctSites'    => (int) ($r['distinct_sites'] ?? 0),
        'lastShot'         => track_last_shot_text($r),
        'hostName'         => $r['host_name'] ?? null,
        'agentVersion'     => $r['agent_version'] ?? null,
        'offline'          => (empty($r['last_seen']) || !track_on_duty($r)),
    ];
}

/**
 * Opens a shift on the record and returns it.
 *
 * Called from every path that starts work: an explicit clock-in, and the
 * heartbeat/telemetry auto clock-in that fires when the server day rolls over
 * while the agent keeps running past midnight.
 *
 * The buckets are zeroed here because the agent zeroes its own ledger on the
 * matching event (agent/src/MainForm.cs DoClockIn, and MonitoringEngine.Start).
 * It did not, and the two sets of numbers were then combined with max(): the
 * morning's high-water mark stayed on the row for the rest of the day and every
 * batch from the afternoon was smaller, so an employee's second shift
 * contributed nothing. A day with a lunch-hour clock-out reported only the
 * morning.
 *
 * totals_locked is released at the same time so the agent's monotonic totals
 * are adopted again from zero rather than being rejected against the old value.
 *
 * Screenshot count and the input counters are deliberately NOT reset - they are
 * the day's evidence, not the shift's, and HR is entitled to both halves.
 */
function track_begin_shift(array $r): array {
    $r['check_in'] = now_sql();
    $r['check_out'] = null;
    $r['break_start'] = null;
    $r['break_seconds'] = 0;
    $r['active_seconds'] = 0;
    $r['idle_seconds'] = 0;
    $r['paused_seconds'] = 0;
    $r['totals_locked'] = 0;
    $r['status'] = STATUS_WORKING;
    $r['last_status'] = 'ACTIVE';
    $r['last_seen'] = now_sql();
    return $r;
}

/**
 * Reopens the day for a second shift without destroying the first one.
 *
 * This function used not to exist: a second clock-in on the same day went
 * through track_begin_shift(), which zeroed active/idle/paused and unlocked the
 * totals. That silently threw away the whole morning whenever an employee
 * clocked out for lunch and back in, and it is the single biggest reason a
 * report showed less time than the employee actually worked - the agent even
 * auto clocks in on every launch, so a reboot or a re-install was enough to wipe
 * the morning.
 *
 * A workday is cumulative, so a new shift must only:
 *   - keep check_in as the first clock-in of the day, so "started at" stays true
 *   - keep every bucket, so the earlier shift is still counted
 *   - keep totals_locked, because re-arming the server's own wall clock on top of
 *     totals the agent already reported is how a day gets counted twice
 *   - clear a stale open break, which belonged to the shift that just ended
 */
function track_resume_shift(array $r): array {
    $r['break_start'] = null;
    $r['status'] = STATUS_WORKING;
    $r['last_status'] = 'ACTIVE';
    $r['last_seen'] = now_sql();
    return $r;
}



function track_check_in(array $emp): array {
    $day = track_record_for($emp['email'], today());
    if (track_manual_locked($day)) return track_summary($emp, today());
    $r = track_settle($day);
    if (empty($r['check_in'])) {
        // First clock-in of the day: the record is brand new, zeroing is correct.
        $r = track_begin_shift($r);
    } else {
        // Any later clock-in today reopens the day and adds to it. Zeroing here
        // is what used to make a split shift lose its first half.
        $r = track_resume_shift($r);
        $r['check_out'] = null;
    }
    track_save($r);
    activity_log($emp, 'CLOCK_IN', 'Clocked in via agent at ' . date('h:i A'), 'agent');
    return track_summary($emp, today());
}

function track_check_out(array $emp): array {
    $day = track_record_for($emp['email'], today());
    if (track_manual_locked($day)) return track_summary($emp, today());
    $r = track_settle($day);
    $worked = track_worked_seconds($r);
    $r['check_out'] = now_sql();
    $r['status'] = STATUS_OFF;
    $r['break_start'] = null;
    $r['last_seen'] = now_sql();
    track_save($r);
    activity_log($emp, 'CLOCK_OUT', 'Clocked out after ' . fmt_dur($worked) . ' of work', 'agent');
    return track_summary($emp, today());
}

function track_break_start(array $emp): array {
    $day = track_record_for($emp['email'], today());
    if (track_manual_locked($day)) return track_summary($emp, today());
    $r = track_settle($day);
    if (empty($r['check_in'])) $r = track_begin_shift($r);
    if (empty($r['break_start'])) {
        $r['break_start'] = now_sql();
        $r['status'] = STATUS_BREAK;
        $r['last_seen'] = now_sql();
        track_save($r);
        break_record_open($emp['email'], today(), $r['break_start']);
        activity_log($emp, 'BREAK_START', 'Break started at ' . date('h:i A'), 'agent');
    }
    return track_summary($emp, today());
}

function track_break_end(array $emp): array {
    $day = track_record_for($emp['email'], today());
    if (track_manual_locked($day)) return track_summary($emp, today());
    $r = $day;
    if (!empty($r['break_start'])) {
        $bs = ts_of($r['break_start']);
        $secs = $bs === null ? 0 : max(0, time() - $bs);
        $r['break_seconds'] = (int) ($r['break_seconds'] ?? 0) + $secs;
        $r['break_start'] = null;
        break_record_close($emp['email'], today(), $secs);
        activity_log($emp, 'BREAK_END', 'Break of ' . fmt_dur($secs) . ' ended', 'agent');
    }
    $r['status'] = STATUS_WORKING;
    $r['last_status'] = 'ACTIVE';
    $r['last_seen'] = now_sql();
    track_save($r);
    return track_summary($emp, today());
}

/**
 * Liveness ping from the agent.
 *
 * The agent beats every 30s. During a break the beat carries totals only - no
 * window, no usage, no input counters - which is what keeps HR from seeing any
 * employee activity while the person is on break.
 */
function track_heartbeat(array $emp, string $status, array $totals = [], ?string $agentVersion = null): array {
    $day = track_record_for($emp['email'], today());
    // A hand-written day is frozen; the beat only needs to return the summary.
    if (track_manual_locked($day)) return track_summary($emp, today());
    $r = track_settle($day);
    // The agent only beats while it is locally on duty and never sends after an
    // explicit clock-out, so a beat with no check_out today means the shift is
    // live: open it when the server day rolled over while the app kept running
    // past midnight, and keep the record in step with the agent.
    $idle = strcasecmp($status, 'IDLE') === 0;
    if (empty($r['check_in']) && !$idle) {
        $r = track_begin_shift($r);
    }
    if (empty($r['check_out']) && !empty($r['check_in'])) {
        $r['last_status'] = $idle ? 'IDLE' : 'ACTIVE';
        $r['status'] = $r['status'] === STATUS_BREAK ? $r['status'] : STATUS_WORKING;
        $r['last_seen'] = now_sql();
        /* The beat is the only call every agent makes, including one sitting idle, so
           the version has to land here. Relying on telemetry alone left the recorded
           version stale for anyone not generating events, and features gated on it
           (supervisor talk) then refused a laptop that was in fact up to date. */
        if ($agentVersion !== null && $agentVersion !== '') $r['agent_version'] = $agentVersion;
        track_apply_totals($r, $totals);
        if ($r['status'] === STATUS_BREAK) {
            $r['current_app'] = null;
            $r['current_title'] = null;
            $r['current_host'] = null;
            $r['current_category'] = null;
            $r['last_shot_at'] = null;
        }
        track_save($r);
    }
    return track_summary($emp, today());
}

/** One telemetry batch: absolute counters overwrite, current window fields refresh. */
function track_record_telemetry(array $emp, array $t): array {
    $day = track_record_for($emp['email'], today());
    // A hand-written day is frozen; telemetry may not reopen or rewrite it.
    if (track_manual_locked($day)) return track_summary($emp, today());
    $r = track_settle($day);
    // Day-rollover auto clock-in (see track_heartbeat).
    $idleT = strcasecmp((string) ($t['status'] ?? 'ACTIVE'), 'IDLE') === 0;
    if (empty($r['check_in']) && !$idleT) $r = track_begin_shift($r);
    if (empty($r['check_out']) && !empty($r['check_in'])) {
        $r['last_status'] = $idleT ? 'IDLE' : 'ACTIVE';
        if ($r['status'] !== STATUS_BREAK) $r['status'] = STATUS_WORKING;
        $r['last_seen'] = now_sql();
    }
    $r['keystrokes'] = max((int) ($r['keystrokes'] ?? 0), (int) ($t['keystrokes'] ?? 0));
    $r['mouse_clicks'] = max((int) ($r['mouse_clicks'] ?? 0), (int) ($t['mouseClicks'] ?? 0));
    $r['mouse_scrolls'] = max((int) ($r['mouse_scrolls'] ?? 0), (int) ($t['mouseScrolls'] ?? 0));
    $r['mouse_distance_px'] = max((int) ($r['mouse_distance_px'] ?? 0), (int) ($t['mouseDistancePx'] ?? 0));

    // Authoritative shift totals. The agent counts these on a monotonic clock, so
    // they survive a folded laptop where the server wall clock would have run on.
    // Never let them move backwards: a delayed batch can legitimately arrive late.
    track_apply_totals($r, $t);

    $onBreak = $r['status'] === STATUS_BREAK;
    foreach ([
        'app' => 'current_app', 'title' => 'current_title', 'host' => 'current_host',
        'category' => 'current_category', 'hostName' => 'host_name',
        'agentVersion' => 'agent_version', 'ip' => 'agent_ip',
    ] as $k => $col) {
        // While the employee is on break nothing about their machine is stored, so
        // the previous window cannot linger and be read as break-time activity.
        if ($onBreak) {
            if ($col === 'current_app' || $col === 'current_title' || $col === 'current_host'
                || $col === 'current_category') {
                $r[$col] = null;
            }
            continue;
        }
        if (isset($t[$k]) && $t[$k] !== null && $t[$k] !== '') $r[$col] = $t[$k];
    }
    if ($onBreak) $r['last_shot_at'] = null;
    track_save($r);
    return track_summary($emp, today());
}

function track_save_screenshot(array $emp, ?string $base64, string $trigger, ?string $app, ?string $title, ?string $host, bool $live): ?array {
    $saved = shots_store($emp['email'], $base64, $trigger, $app, $title, $host, $live);
    if ($saved === null) return null;
    $r = track_record_for($emp['email'], today());
    // Live frames stream every few seconds; counting them would swamp the day's
    // evidence tally, so only AUTO/MANUAL captures move the counter.
    if (!$live) $r['screenshot_count'] = (int) ($r['screenshot_count'] ?? 0) + 1;
    $r['last_shot_at'] = now_sql();
    track_save($r);
    return $saved;
}

/* ------------------------------------------------------------- queries ---- */

function track_today_record(string $email): array {
    return track_record_for($email, today());
}

function track_history(string $email): array {
    return rows('SELECT * FROM track_records WHERE email=? ORDER BY work_date DESC', [$email]);
}

function track_session_log(): array {
    return rows('SELECT * FROM track_records WHERE status <> ?', [STATUS_OFF]);
}

function track_range(string $email, string $start, string $end): array {
    return rows('SELECT * FROM track_records WHERE email=? AND (check_in IS NOT NULL AND check_in BETWEEN ? AND ?) ORDER BY check_in ASC',
        [$email, $start, $end]);
}

/** All records for every tested employee on one date (the /day segments view). */
function track_all_on_date(string $date): array {
    return rows('SELECT * FROM track_records WHERE work_date=? ORDER BY check_in ASC', [$date]);
}

/**
 * Adjust time buckets for a past day (History -> Adjust).
 * When an admin adjusts a day, freeze it so the agent cannot re-raise totals later;
 * also force-refresh HR attendance so the change appears everywhere.
 */
function track_adjust(string $email, string $date, int $activeDelta, int $idleDelta, int $breakDelta, bool $lock = true): array {
    $r = track_record_for($email, $date);
    if (empty($r['check_in'])) {
        $r['check_in'] = date('Y-m-d 09:00:00');
        $r['status'] = STATUS_OFF;
    }
    if (empty($r['check_out'])) $r['check_out'] = now_sql();
    $r['active_seconds'] = max(0, (int) ($r['active_seconds'] ?? 0) + $activeDelta);
    $r['idle_seconds'] = max(0, (int) ($r['idle_seconds'] ?? 0) + $idleDelta);
    $r['break_seconds'] = max(0, (int) ($r['break_seconds'] ?? 0) + $breakDelta);
    $r['break_start'] = null;
    $r['last_seen'] = now_sql();
    $r['totals_locked'] = 1;
    $r['manual_locked'] = $lock ? 1 : (int) ($r['manual_locked'] ?? 0);
    track_save($r);
    try { q('UPDATE break_records SET break_end=COALESCE(break_end,?), seconds=? WHERE email=? AND work_date=? AND break_end IS NULL',
        [now_sql(), (int) $r['break_seconds'], strtolower($email), $date]); } catch (Throwable $e) { }
    if (function_exists('hr_attendance_sync_day')) {
        hr_attendance_sync_day($email, $date, true);
    }
    return $r;
}

/* ------------------------------------------------------------ screenshots ---- */

function shot_entity(int $id): ?array {
    return one('SELECT * FROM screenshots WHERE id=?', [$id]);
}

function shot_count(string $email): int {
    return (int) val('SELECT COUNT(*) FROM screenshots WHERE email=? AND live=0', [$email]);
}

/**
 * Re-derives screenshot_count for one day after rows were removed by hand.
 * The counter is incremented on upload and never decremented, so deleting a
 * screenshot (or an employee) would otherwise leave the report claiming
 * captures that no longer exist.
 */
function track_recount_screenshots(string $email, string $day): void {
    $n = (int) val('SELECT COUNT(*) FROM screenshots WHERE email=? AND live=0 AND DATE(captured_at)=?',
        [$email, $day]);
    q('UPDATE track_records SET screenshot_count=? WHERE email=? AND work_date=?', [$n, $email, $day]);
}

function shot_latest(string $email): ?array {
    return one('SELECT * FROM screenshots WHERE email=? ORDER BY captured_at DESC, id DESC LIMIT 1', [$email]);
}

function shot_latest_live(string $email): ?array {
    return one('SELECT * FROM screenshots WHERE email=? AND live=1 ORDER BY captured_at DESC, id DESC LIMIT 1', [$email]);
}

function shot_bytes(int $id): ?string {
    $s = shot_entity($id);
    return $s === null ? null : shots_read_bytes($s);
}

function shot_latest_base64(string $email): ?string {
    $s = shot_latest($email);
    return $s === null ? null : shots_read_base64($s);
}

function shots_between(string $from, string $to, ?string $email = null): array {
    // live=0 keeps the gallery on the scheduled evidence; live frames belong to
    // the live view and would otherwise flood it.
    if ($email !== null) {
        return rows('SELECT * FROM screenshots WHERE email=? AND live=0 AND captured_at >= ? AND captured_at < ? ORDER BY captured_at DESC, id DESC',
            [$email, $from, $to]);
    }
    return rows('SELECT * FROM screenshots WHERE live=0 AND captured_at >= ? AND captured_at < ? ORDER BY captured_at DESC, id DESC',
        [$from, $to]);
}