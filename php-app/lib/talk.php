<?php
/**
 * WorkFolio PHP - "supervisor talk": live one-way audio pushed from a supervisor's
 * browser to a monitored employee's speakers.
 *
 * WHY THIS EXISTS: a supervisor watching the Live Monitor sometimes needs to say
 * something to the person they are watching ("stop sharing your screen", "come to
 * my desk"). Routing that through a phone call is clumsy, so the voice is simply
 * delivered to the machine that is already being watched.
 *
 * DESIGN, DELIBERATELY:
 *
 *  - It is NOT covert. Every session shows a persistent banner on the employee's
 *    own screen with the supervisor's name, and the employee gets Mute and
 *    Decline. Audio appearing out of an empty laptop is a harassment and a
 *    compliance problem, not a feature.
 *
 *  - TWO RECEIVERS, ONE RELAY. The employee can hear audio either through the
 *    browser page they already have open, or through an agent new enough to know
 *    about talk at all (TalkPlayer.cs). The browser is the primary path and needs
 *    no install: the employee signs into WorkFolio as themselves, which proves who
 *    they are far more directly than a background agent does, and a live sound
 *    listener in their own session is strong evidence they are at that machine.
 *    Both receivers drive the exact same spool and the same ack sequence, so a
 *    supervisor cannot tell which one is in use and cannot break the stream by
 *    swapping between them mid-sentence. Whichever answers first wins; the other
 *    finds the audio already acknowledged and stays quiet.
 *
 *  - It is NOT surveillance data and is never stored. There is no recording of
 *    the audio: chunks exist on disk only until the agent acknowledges playing
 *    them, then they are deleted. The server keeps session metadata (who, when,
 *    whether it was heard/muted/declined) so the supervisor gets honest feedback,
 *    never a transcript.
 *
 *  - The owner is refused, exactly like every other monitored-target refusal. The
 *    owner is never a monitored employee, so they are never talked to either.
 *
 * TRANSPORT: plain HTTP, matching the rest of the agent API. The browser POSTs
 * 16 kHz mono signed-16-bit PCM chunks; the agent long-polls and pulls them one at
 * a time. Delivery is ack-driven - the server will not hand out chunk N+1 until the
 * agent has confirmed chunk N played - which gives natural backpressure and
 * at-most-once playback, so a slow link drops audio instead of building a lag.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/usage.php';
require_once __DIR__ . '/tracks.php';
require_once __DIR__ . '/api_agent.php';
/* activity_log() is used below when a talk starts. index.php happens to load this
   first, but relying on someone else's include order is how a library turns into a
   fatal error the day it is required on its own. */
require_once __DIR__ . '/activity.php';

/* Audio contract, shared with the browser (pages.php) and the agent (TalkPlayer.cs). */
const TALK_SAMPLE_RATE     = 16000;   /* Hz   - voice does not need more      */
const TALK_CHANNELS        = 1;       /* mono - the other half is bandwidth  */
const TALK_BITS            = 16;      /* signed 16-bit little endian PCM      */
const TALK_MAX_CHUNK_MS    = 400;     /* a single POST never exceeds this    */
const TALK_MAX_SESSION_SEC = 600;     /* hard cap on one push, abuse guard   */
const TALK_STALE_AFTER_SEC = 1800;    /* an abandoned session is swept after  */

/* First agent build that knows how to poll for and play talk audio. Anything older
   has no /api/agent/talk/poll call at all, so arming a session for it would let a
   supervisor talk into a void for ten minutes with the panel insisting it was live.
   This only gates the AGENT receiver - the browser receiver has no version to check. */
const TALK_MIN_AGENT_VERSION = '1.1.0';

/* How long a browser listener's heartbeat keeps it counted as listening. The
   employee page re-arms every few seconds, so this only has to outlast one missed
   beat or a short network blip; it must NOT be long, because the moment the page
   is closed nobody is listening and the supervisor deserves to be told. */
const TALK_LISTENER_TTL_SEC = 30;

/* ---------------------------------------------------------------- storage ---- */

/**
 * Audio spool root. Kept beside screenshots, and excluded from the web root by
 * index.php, so a chunk can never be fetched over HTTP even by accident.
 */
function talk_root(): string {
    global $WF;
    $dir = rtrim($WF['storage'], '/\\') . '/talk';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** Per-employee directory. Hashed so '@' and '.' in the address are safe as a path. */
function talk_dir(string $email): string {
    $dir = talk_root() . '/' . md5(strtolower($email));
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function talk_state_path(string $email): string {
    return talk_dir($email) . '/state.json';
}

function talk_read(string $email): ?array {
    $f = talk_state_path($email);
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') return null;
    $s = json_decode($raw, true);
    return is_array($s) ? $s : null;
}

/** Writes atomically: the agent polls this file every few hundred ms and must
 *  never observe a half-written state. */
function talk_write(string $email, array $state): void {
    $f = talk_state_path($email);
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($state)) === false) return;
    @rename($tmp, $f);   /* rename is atomic within a filesystem */
}

function talk_delete_chunk(string $email, int $seq): void {
    @unlink(talk_dir($email) . '/' . $seq . '.pcm');
}

function talk_delete_all_chunks(string $email, int $upToSeq): void {
    $dir = talk_dir($email);
    foreach ((array) glob($dir . '/*.pcm') as $f) {
        $n = (int) basename($f, '.pcm');
        if ($n <= $upToSeq) @unlink($f);
    }
}

/** Drops an expired session and every chunk it spooled. */
function talk_purge(string $email): void {
    $s = talk_read($email);
    if ($s !== null) {
        $touch = max((int) ($s['endedAt'] ?? 0), (int) ($s['armedAt'] ?? 0));
        if ($touch > 0 && (time() - $touch) < TALK_STALE_AFTER_SEC) return;
    }
    talk_delete_all_chunks($email, PHP_INT_MAX);
    @unlink(talk_state_path($email));
}

/* ---------------------------------------------------- browser listener presence ---- */

/**
 * Presence file for the listener running in the employee's own web session.
 *
 * This is deliberately NOT a database row. It says something about a browser tab
 * that should vanish the instant the tab does, it is written every few seconds by
 * every signed-in employee, and nothing else in the app needs to query it - so a
 * file that expires on its own is both cheaper and more honest than a row that has
 * to be cleaned up.
 */
function talk_listener_path(string $email): string {
    return talk_dir($email) . '/listener.json';
}

function talk_listener_info(string $email): ?array {
    $f = talk_listener_path($email);
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || (int) ($j['at'] ?? 0) <= 0) return null;
    return ['at' => (int) $j['at'], 'sound' => !empty($j['sound'])];
}

/**
 * Is somebody actually able to hear, right now? A fresh beat is not enough on its
 * own: a page sitting open with sound switched off is a page where the supervisor
 * would be talking into a void, which is the exact failure the version gate existed
 * to prevent. So sound being on is part of being counted as listening.
 */
function talk_listener_live(string $email): bool {
    $i = talk_listener_info($email);
    return $i !== null && $i['sound'] && (time() - $i['at']) <= TALK_LISTENER_TTL_SEC;
}

/**
 * When did this employee's page last check in? Used to tell "open but muted" apart
 * from "never opened", because those need different words in front of the employee.
 */
function talk_listener_seen(string $email): int {
    $i = talk_listener_info($email);
    return $i === null ? 0 : $i['at'];
}

/**
 * Records that this employee has a listener open. Called on load and then every few
 * seconds; the timestamp is what actually matters, so this is idempotent and cheap.
 */
function talk_listener_mark(string $email, bool $soundEnabled): void {
    $f = talk_listener_path($email);
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode(['at' => time(), 'sound' => $soundEnabled])) === false) return;
    @rename($tmp, $f);
}

/* ------------------------------------------------------------- permissions ---- */

/**
 * May this supervisor push audio to this employee?
 *
 * Four gates, each of which has already refused something in this codebase:
 * role, roster visibility (an HR must not reach outside their people), the owner
 * exemption, and the per-employee policy toggle.
 *
 * @return string|null  null when allowed, otherwise a reason for the UI
 */
function talk_permission_error(array $viewer, string $email): ?string {
    $target = employee_find_by_email($email);
    if ($target === null) return 'No such employee';

    /* You cannot talk to yourself through a monitoring feature. */
    if (strcasecmp((string) $viewer['email'], (string) $target['email']) === 0) {
        return 'That is your own account';
    }
    if (!access_can_view_email($viewer, (string) $target['email'])) {
        return 'That employee is not on your roster';
    }
    if (is_super_admin($target)) {
        return 'The owner account is never a target';
    }
    $cfg = config_for((string) $target['email']);
    if (empty($cfg['allow_supervisor_talk'])) {
        return 'Supervisor talk is turned off for this employee';
    }
    return null;
}

/** Can the agent on this machine be reached right now? Mirrors the monitor's freshness rules. */
/** True when the reporting agent is new enough to actually play talk audio. */
function talk_agent_can_play(string $email): bool {
    $r = track_record_for($email, today());
    return version_compare((string) ($r['agent_version'] ?? ''), TALK_MIN_AGENT_VERSION, '>=');
}

function talk_agent_online(string $email): bool {
    $r = track_record_for($email, today());
    if (($r['status'] ?? STATUS_OFF) === STATUS_BREAK) return false;
    $age = secs_since($r['last_seen'] ?? null);
    if ($age === null) return false;
    return $age <= 90;
}

/** Has the agent explicitly clocked onto a break? Kept separate from online(),
 *  because a break has to be honoured even when a browser listener is live. */
function talk_agent_recorded_break(string $email): bool {
    $r = track_record_for($email, today());
    return ($r['status'] ?? '') === STATUS_BREAK;
}

/**
 * Which receiver can take this employee's audio right now.
 *
 * @return string|null 'browser', 'agent', or null when nobody can hear it
 *
 * The browser wins ties on purpose. It is the path that needs no install, so it is
 * the one that should be used when both are available; and preferring it means the
 * employee who has installed nothing still gets a working banner with their name on
 * it, rather than audio that only the agent could have shown.
 */
function talk_receiver_kind(string $email): ?string {
    if (talk_listener_live($email)) return 'browser';
    if (talk_agent_can_play($email) && talk_agent_online($email)) return 'agent';
    return null;
}

function talk_receiver_ready(string $email): bool {
    return talk_receiver_kind($email) !== null;
}

/**
 * Why audio cannot be delivered to this employee right now, or null when it can.
 *
 * The wording matters more than usual here. Getting this wrong is exactly what the
 * old agent-version gate was invented to prevent: the supervisor's panel going
 * "live" while their voice disappears into a machine that will never play it.
 * So every refusal says what the receiver has to do, not just that it is not there.
 */
function talk_receiver_error(string $email): ?string {
    /* A recorded break is checked BEFORE readiness, and that order is the whole
     * point. Checking readiness first would let a supervisor arm a session at
     * somebody who is on a break, because the browser is sitting open: the panel
     * would read "live" while the receiver correctly refused to play. Same void,
     * different cause. */
    if (talk_agent_recorded_break($email)) {
        return 'That person is on a break';
    }

    if (talk_receiver_kind($email) !== null) return null;

    if (talk_agent_can_play($email)) {
        return 'Nobody is listening on that machine right now';
    }
    /* Distinguish "page open but sound never enabled" from "page not open at all",
       because those need different actions from the employee and only a fresh
       beating page proves which one it is right now. */
    $info = talk_listener_info($email);
    if ($info !== null && (time() - $info['at']) <= TALK_LISTENER_TTL_SEC && !$info['sound']) {
        return 'That person has WorkFolio open but talk sound is turned off. '
             . 'They need to click "Enable talk sound".';
    }
    return 'Nobody can hear talk on that machine. They need WorkFolio open in their '
         . 'browser with talk sound enabled, or agent v' . TALK_MIN_AGENT_VERSION . '.';
}

/**
 * Should audio be withheld right now, for reasons that are not about capability?
 * Shared by both receivers so a supervisor gets one consistent set of rules.
 *
 * @return string|null null when it is fine to play, else a machine-readable reason
 */
function talk_receiver_refusal(string $email): ?string {
    $cfg = config_for($email);
    if (empty($cfg['allow_supervisor_talk'])) return 'policy';

    /* A recorded break is an explicit "do not interrupt me", from the employee
     * themselves. It outranks everything else, including an open browser. */
    if (talk_agent_recorded_break($email)) return 'on-break';

    /* Presence. A live listener in their own signed-in session counts, as does the
     * agent being on duty: either one means somebody is at that machine. */
    if (talk_listener_live($email)) return null;
    if (track_on_duty(track_record_for($email, today()))) return null;

    return 'off-duty';
}

/** State as the supervisor's browser sees it. Never includes audio. */
function talk_public_state(string $email): array {
    /* receiverKind is the single honest answer to "can they hear me right now".
     * The agent fields are kept for the panel's per-device detail. */
    $base = [
        'listenerOnline' => talk_listener_live($email),
        'receiverKind'   => talk_receiver_kind($email),
        'receiverReady'  => talk_receiver_ready($email),
        'agentOnline'    => talk_agent_online($email),
        'agentCanPlay'   => talk_agent_can_play($email),
        'blockedReason'  => talk_receiver_error($email),
    ];

    $s = talk_read($email);
    if ($s === null) {
        /* Still report liveness before a session exists: the supervisor's panel needs
         * to say "nobody is listening" rather than discover it by being refused. */
        return array_merge(['active' => false, 'ack' => null, 'pendingSec' => 0, 'talkId' => null], $base);
    }
    $sup = employee_find_by_email((string) ($s['supervisor'] ?? ''));
    return array_merge([
        'active'      => !empty($s['active']),
        'talkId'      => $s['talkId'] ?? null,
        'supervisor'  => $s['supervisor'] ?? null,
        'supervisorName' => $sup === null ? null : employee_full_name($sup),
        'ack'         => $s['ack'] ?? null,
        'ackSeq'      => isset($s['ackSeq']) ? (int) $s['ackSeq'] : null,
        'armedAt'     => isset($s['armedAt']) ? (int) $s['armedAt'] : null,
        'elapsedSec'  => isset($s['armedAt']) ? max(0, time() - (int) $s['armedAt']) : 0,
        'playedSec'   => isset($s['ackSeq']) ? (int) $s['ackSeq'] : 0,
'nextSeq'     => isset($s['nextSeq']) ? (int) $s['nextSeq'] : 0,
          /* Chunks the receiver could never get. Reported so a gap in the audio is
             visible to the supervisor instead of silently swallowed - the alternative
             is somebody believing they were heard clearly when they were not. */
          'lostChunks'  => isset($s['lostChunks']) ? (int) $s['lostChunks'] : 0,
      ], $base);
}

/* ----------------------------------------------------------- supervisor api ---- */

function api_monitor_talk_start(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $body = json_decode((string) file_get_contents('php://input'), true);
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    if ($email === '') api_json_response(['ok' => false, 'error' => 'No employee given']);

    $denied = talk_permission_error($u, $email);
    if ($denied !== null) api_json_response(['ok' => false, 'error' => $denied]);

    /* One check, and it is the whole point of the function: refuse unless somebody
     * can actually play this. Before this existed, the panel said "live" while the
     * audio vanished into a machine that had no idea what to do with it. */
    $unreachable = talk_receiver_error($email);
    if ($unreachable !== null) api_json_response(['ok' => false, 'error' => $unreachable]);

    talk_purge($email);
    /* A previous session's chunks are dead the moment a new one starts. */
    talk_delete_all_chunks($email, PHP_INT_MAX);

    $state = [
        'talkId'     => bin2hex(random_bytes(12)),
        'supervisor' => (string) $u['email'],
        'active'     => true,
        'armedAt'    => time(),
        'endedAt'    => 0,
        'nextSeq'    => 0,
        'lastSeq'    => -1,
        'ack'        => null,
        'ackSeq'     => null,
    ];
    talk_write($email, $state);

    activity_log($u, 'TALK_START', 'Started supervisor talk to ' . $email, 'web');
    api_json_response(array_merge(['ok' => true], talk_public_state($email)));
}

function api_monitor_talk_chunk(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $body = json_decode((string) file_get_contents('php://input'), true);
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $talkId = (string) ($body['talkId'] ?? '');
    $seq  = (int) ($body['seq'] ?? -1);
    $pcm  = (string) ($body['pcm'] ?? '');
    $last = !empty($body['last']);

    $s = talk_read($email);
    if ($s === null || empty($s['active'])) {
        api_json_response(['ok' => false, 'error' => 'No talk session']);
    }
    if ($talkId === '' || !hash_equals((string) ($s['talkId'] ?? ''), $talkId)) {
        api_json_response(['ok' => false, 'error' => 'Talk session expired - start again']);
    }
    /* A supervisor may not append to somebody else's session, even a real one. */
    if (strcasecmp((string) ($s['supervisor'] ?? ''), (string) $u['email']) !== 0) {
        api_json_response(['ok' => false, 'error' => 'Not your talk session']);
    }

    $elapsed = time() - (int) $s['armedAt'];
    if ($elapsed > TALK_MAX_SESSION_SEC) {
        api_json_response(['ok' => false, 'error' => 'Talk session hit the maximum length']);
    }

    if ($pcm !== '') {
        $raw = base64_decode($pcm, true);
        $maxBytes = (int) ceil(TALK_SAMPLE_RATE * TALK_CHANNELS * (TALK_BITS / 8) * (TALK_MAX_CHUNK_MS / 1000)) + 64;
        if ($raw === false) {
            api_json_response(['ok' => false, 'error' => 'Bad audio chunk']);
        }
        if (strlen($raw) > $maxBytes) {
            api_json_response(['ok' => false, 'error' => 'Audio chunk too large']);
        }
        /* Strictly increasing. A retried chunk below the agent's watermark has
           already been heard and acknowledged, so it is a harmless no-op; anything
           else must be the next one in order or the stream is out of sequence. */
        if ($seq < 0) {
            api_json_response(['ok' => false, 'error' => 'Bad sequence number']);
        }
        if ($seq < (int) ($s['nextSeq'] ?? 0)) {
            api_json_response(['ok' => true, 'skipped' => 'stale-seq', 'nextSeq' => (int) $s['nextSeq']]);
        }
        $file = talk_dir($email) . '/' . $seq . '.pcm';
        if (@file_put_contents($file, $raw) === false) {
            api_json_response(['ok' => false, 'error' => 'Could not spool audio']);
        }
    }

    if ($pcm !== '') $s['lastSeq'] = max((int) ($s['lastSeq'] ?? -1), $seq);

    if ($last) {
        /* Stop accepting new audio, but the agent keeps polling until it has pulled
           everything already spooled - poll() treats "still has pending chunks" as
           active, so the banner stays up for the tail and drops on its own. */
        $s['active'] = false;
        $s['endedAt'] = time();
    }
    talk_write($email, $s);

    api_json_response(['ok' => true, 'seq' => $seq, 'active' => empty($last)]);
}

function api_monitor_talk_stop(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $body = json_decode((string) file_get_contents('php://input'), true);
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $talkId = (string) ($body['talkId'] ?? '');

    $s = talk_read($email);
    if ($s === null) api_json_response(['ok' => true]);

    if ($talkId !== '' && !hash_equals((string) ($s['talkId'] ?? ''), $talkId)) {
        api_json_response(['ok' => false, 'error' => 'Talk session expired']);
    }

    $s['active'] = false;
    $s['endedAt'] = time();
    talk_write($email, $s);
    activity_log($u, 'TALK_STOP', 'Stopped supervisor talk to ' . $email, 'web');
    api_json_response(['ok' => true]);
}

function api_monitor_talk_state(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $email = strtolower(trim((string) ($_GET['email'] ?? '')));
    if ($email === '') api_json_response(['ok' => false, 'error' => 'No employee given']);
    if (!access_can_view_email($u, $email)) api_json_response(['ok' => false, 'error' => 'Not on your roster']);
    api_json_response(array_merge(['ok' => true], talk_public_state($email)));
}

/* ------------------------------------------------------- shared receiver core ---- */

/**
 * The one place a receiver's next chunk is decided, used by BOTH the agent and the
 * browser listener. Keeping this single is the point: a supervisor must not be able
 * to hit a delivery bug in one receiver that the other does not have, and both must
 * advance through the identical ack sequence so that switching receiver mid-sentence
 * cannot replay or skip audio.
 */
function talk_pull_next(string $email, ?array $s): array {
    if ($s === null) return ['ok' => true, 'active' => false];

    $resp = [
        'ok'      => true,
        /* Active means "keep the banner up": either the supervisor is still speaking,
           or audio is spooled that this receiver has not played yet. */
        'active'  => !empty($s['active']) || ((int) ($s['nextSeq'] ?? 0) <= (int) ($s['lastSeq'] ?? -1)),
        'talkId'  => $s['talkId'] ?? null,
        'ack'     => $s['ack'] ?? null,
        'seq'     => null,
        'pcm'     => null,
        'sampleRate' => TALK_SAMPLE_RATE,
        'channels'=> TALK_CHANNELS,
        'bits'    => TALK_BITS,
    ];

    /* Who is talking, resolved server-side. The banner has to name a real person
       before the first sample plays, and neither receiver should need a second
       round trip to find out. */
    if (!empty($s['active'])) {
        $sup = employee_find_by_email((string) ($s['supervisor'] ?? ''));
        $resp['supervisor']     = $s['supervisor'] ?? null;
        $resp['supervisorName'] = $sup === null ? null : employee_full_name($sup);
    }

    $next = (int) ($s['nextSeq'] ?? 0);
    $dir  = talk_dir($email);
    $file = $dir . '/' . $next . '.pcm';
    $raw  = is_file($file) ? (string) @file_get_contents($file) : '';

    /* A file that exists but reads as empty is a torn read - the supervisor is still
       writing it - not a chunk that went missing. Treating that as a hole would skip
       audio that was about to arrive and then report a phantom gap to the supervisor,
       which is worse than waiting a few milliseconds. */
    if ($raw === '' && is_file($file)) {
        clearstatcache(true, $file);
        $raw = (string) @file_get_contents($file);
    }

    /* Step over a hole instead of stalling on it. Waiting for a chunk that is never
       going to arrive used to strand every later chunk on disk: the banner stayed up
       and the employee heard silence until the session expired. One lost chunk is a
       fraction of a second of speech, whereas stalling throws away the whole sentence
       that follows it. So resume at the first chunk that really made it, and count
       the loss, because a gap that vanishes quietly is how a supervisor ends up
       believing they were heard. */
    if ($raw === '' && !is_file($file)) {
        $best = null; $bestFile = null;
        foreach ((array) @glob($dir . '/*.pcm') as $cand) {
            $k = (int) basename($cand, '.pcm');
            if ($k < $next || (int) @filesize($cand) <= 0) continue;
            if ($best === null || $k < $best) { $best = $k; $bestFile = $cand; }
        }
        if ($best !== null) $raw = (string) @file_get_contents($bestFile);
        if ($raw !== '' && $best !== null && $best > $next) {
            $s['lostChunks'] = (int) ($s['lostChunks'] ?? 0) + ($best - $next);
            $s['nextSeq'] = $best;
            $next = $best;
            talk_write($email, $s);
        }
    }

    /* Everything the supervisor claimed to send is gone. Retire those bytes so the
       banner can drop on its own, instead of both ends polling a phantom chunk until
       the stale sweep eventually collects it. */
    if ($raw === '' && $next <= (int) ($s['lastSeq'] ?? -1)) {
        $s['lostChunks'] = (int) ($s['lostChunks'] ?? 0) + ((int) $s['lastSeq'] - $next + 1);
        $s['nextSeq'] = (int) $s['lastSeq'] + 1;
        talk_write($email, $s);
        $resp['active'] = !empty($s['active']);
    }

    if ($raw !== '') {
        $resp['seq'] = $next;
        $resp['pcm'] = base64_encode($raw);
    }
    return $resp;
}

/**
 * A receiver confirms a chunk finished playing, or that the employee muted or
 * declined. Advancing only on ack is what makes delivery at-most-once and applies
 * the backpressure that keeps a slow link dropping audio instead of lagging.
 */
function talk_apply_ack(string $email, int $seq, string $ack): array {
    if (!in_array($ack, ['heard', 'muted', 'declined'], true)) $ack = 'heard';

    $s = talk_read($email);
    if ($s === null) return ['ok' => true, 'skipped' => 'no-session'];

    if ($seq >= 0) {
        /* Refuse to walk backwards, or a duplicate ack would replay old audio. */
        if ($seq >= (int) ($s['nextSeq'] ?? 0)) $s['nextSeq'] = $seq + 1;
        $s['ackSeq'] = max((int) ($s['ackSeq'] ?? -1), $seq);
        talk_delete_chunk($email, $seq);
    }
    $s['ack'] = $ack;

    /* Decline ends it. Everything still spooled is deleted rather than left to
     * age out: the employee has just said "no", so keeping their supervisor's
     * unread audio on disk for another half hour would contradict the one promise
     * this feature makes about storage. */
    if ($ack === 'declined') {
        $s['active'] = false;
        $s['endedAt'] = time();
        talk_delete_all_chunks($email, PHP_INT_MAX);
    }
    talk_write($email, $s);

    return ['ok' => true, 'nextSeq' => (int) $s['nextSeq']];
}

/* --------------------------------------------------------------- agent api ---- */

/**
 * The agent's poll. Answers with the single chunk it is allowed to have next, or
 * nothing. Never blocks: the agent re-polls on its own cadence, which keeps this
 * cheap enough to run continuously while an employee is clocked in.
 *
 * This is deliberately NOT queued for retry on the agent side. Replaying audio
 * after a network blip would repeat words the employee already heard, so a failed
 * poll simply tries again and the chunk stays where it is.
 */
function api_agent_talk_poll(): void {
    $body = agent_json_body();
    $emp = api_body_employee($body);
    if ($emp === null) { api_json_response(['ok' => false, 'error' => 'Unauthorized - please login again']); return; }

    $email = (string) $emp['email'];
    if (!agent_tracking_enabled($emp)) { api_json_response(['ok' => true, 'active' => false, 'refused' => 'untracked']); return; }

    $refused = talk_receiver_refusal($email);
    if ($refused !== null) { api_json_response(['ok' => true, 'active' => false, 'refused' => $refused]); return; }

    talk_purge($email);
    api_json_response(talk_pull_next($email, talk_read($email)));
}

/** The agent's ack. Same shared core as the browser listener's. */
function api_agent_talk_ack(): void {
    $body = agent_json_body();
    $emp = api_body_employee($body);
    if ($emp === null) { api_json_response(['ok' => false, 'error' => 'Unauthorized - please login again']); return; }
    if (!agent_tracking_enabled($emp)) { api_json_response(['ok' => true, 'skipped' => 'untracked']); return; }

    api_json_response(talk_apply_ack(
        (string) $emp['email'],
        (int) ($body['seq'] ?? -1),
        (string) ($body['ack'] ?? 'heard')
    ));
}

/* ------------------------------------------------------- browser listener api ---- */

/**
 * The listener's identity is whoever is signed in. There is deliberately no email
 * parameter anywhere below: a listener can only ever ask for its own audio, so this
 * endpoint cannot be talked into pulling somebody else's.
 */
function talk_listener_identity(): array {
    $u = cur_user();
    if ($u === null) api_json_response(['ok' => false, 'error' => 'Unauthorized - please login again']);
    return $u;
}

/**
 * The page reports that it is open, and whether it actually has sound on.
 *
 * Also answers with the session state in the same round trip, so the banner can
 * appear on the first beat instead of waiting on a poll that may never come.
 */
function api_talk_listener_ready(): void {
    $u = talk_listener_identity();
    $email = strtolower((string) $u['email']);
    $body = json_decode((string) file_get_contents('php://input'), true);
    talk_listener_mark($email, !empty($body['sound']));
    api_json_response(array_merge(['ok' => true], talk_public_state($email)));
}

/**
 * The browser listener's poll. Same contract as the agent's, same shared core.
 *
 * This doubles as the presence heartbeat on purpose. A separate beat endpoint would
 * double the request count on a pool small enough to matter, and the page is already
 * asking - the only thing added is whether sound is on, which is the difference
 * between "somebody can hear" and "a tab is open somewhere".
 */
function api_talk_listener_poll(): void {
    $u = talk_listener_identity();
    $email = strtolower((string) $u['email']);

    talk_listener_mark($email, (string) ($_GET['sound'] ?? '') === '1');

    $refused = talk_receiver_refusal($email);
    if ($refused !== null) { api_json_response(['ok' => true, 'active' => false, 'refused' => $refused]); return; }

    talk_purge($email);
    api_json_response(talk_pull_next($email, talk_read($email)));
}

/** The browser listener's ack. */
function api_talk_listener_ack(): void {
    $u = talk_listener_identity();
    $email = strtolower((string) $u['email']);
    $body = json_decode((string) file_get_contents('php://input'), true);

    api_json_response(talk_apply_ack(
        $email,
        (int) ($body['seq'] ?? -1),
        (string) ($body['ack'] ?? 'heard')
    ));
}