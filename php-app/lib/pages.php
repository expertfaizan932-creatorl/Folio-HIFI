<?php
/**
 * WorkFolio PHP - all web pages (the reports UI webview).
 *
 * Same screens as the Spring app: login, dashboard, my-time, live monitor,
 * history, the activity report, agent settings, users, employees, activity
 * tracker. Role gating mirrors SecurityConfig; row-level visibility mirrors
 * ReportAccessService on every email/id parameter.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tracks.php';
require_once __DIR__ . '/usage.php';
require_once __DIR__ . '/views.php';

/* ------------------------------------------------------------- helpers ---- */

function page_parse_day(?string $v, ?string $fallback): string {
    if ($v !== null && $v !== '' && strtotime($v) !== false) return date('Y-m-d', strtotime($v));
    return $fallback ?? today();
}

/* ----------------------------------------------------------------- login ---- */

function page_login(): void {
    if (cur_user() !== null) redirect(base_url('/dashboard'));
    $error = !empty($_GET['error']);
    $logout = !empty($_GET['logout']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        /* Login CSRF: a sign-in must carry the token minted for this session,
           otherwise a third-party site could sign a visitor into an account of
           its choosing. */
        require_csrf();
        $email = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $emp = do_login($email, $pass);
        if ($emp !== null) redirect(base_url('/dashboard'));
        // A refusal leaves a trace with the address and the source, so
        // "somebody else signed in with my details" can be answered from the
        // audit trail instead of from memory.
        activity_log(null, 'LOGIN_FAILED', 'Refused sign-in for ' . $email . ' from '
            . (string) ($_SERVER['REMOTE_ADDR'] ?? '?'), 'web');
        redirect(base_url('/login?error=1'));
    }

    global $WF;
    $showDemo = !empty($WF['show_demo']);
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Folio - Sign in</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= esc(base_url('/assets/wf.css')) ?>?v=20261005a" rel="stylesheet">
<script>
(function () { try { var t = localStorage.getItem('wf-theme');
  if (!t) t = (window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t); } catch (e) {} })();
</script>
<?= pwa_head() ?>
<style>
    /* Login is its own layout, not the app shell: one centred column on a phone,
       side-by-side with the brand panel from ~900px up. Everything is sized in
       clamp() against the viewport so nothing depends on a fixed pixel height,
       which is what used to push the sign-in button off the bottom of a short
       window or cut the last line of text. */
    body.login-body { min-height: 100vh; min-height: 100svh; display: grid; place-items: center; padding: clamp(.9rem, 3vw, 2rem); }
    .login-wrap { width: 100%; max-width: 980px; }
    .login-grid { display: grid; gap: clamp(1.25rem, 3vw, 2.5rem); align-items: center; }
    @media (min-width: 900px) { .login-grid { grid-template-columns: 1.05fr .95fr; } }

    .login-brand { text-align: center; }
    @media (min-width: 900px) { .login-brand { text-align: left; } }
    .login-brand img { width: clamp(72px, 13vw, 104px); height: clamp(72px, 13vw, 104px); object-fit: contain; border-radius: 50%; box-shadow: 0 18px 44px -18px rgba(49, 16, 120, .55); }
    .login-brand-name { font-weight: 800; font-size: clamp(1.9rem, 5.2vw, 2.6rem); letter-spacing: -.025em; line-height: 1.14; margin: .9rem 0 .2rem; color: var(--wf-violet); }
    .login-brand-sub { color: var(--wf-muted); font-size: clamp(.86rem, 2.4vw, .95rem); }

    .login-card { border-radius: 22px; overflow: hidden; border: 1px solid var(--wf-border); }
    .login-body-form { padding: clamp(1.25rem, 4vw, 1.9rem); }
    .login-body-form .form-label { font-weight: 600; }
    /* The gradient title used to be painted with -webkit-text-fill-color:
       transparent, which clipped the cap-height on some Windows font stacks and
       left a visibly sliced word. The sign-in heading is solid brand colour for
       that reason; the gradient now lives on the brand wordmark, which is short,
       fixed-width and never wraps. */
    .login-h { font-size: clamp(1.15rem, 3.4vw, 1.4rem); font-weight: 700; letter-spacing: -.02em; }
    .login-sub-note { color: var(--wf-muted); font-size: .86rem; }
    .demo-box { background: var(--wf-surface-2); border: 1px dashed var(--wf-border-strong); border-radius: var(--wf-radius-sm); }
    .demo-box code { color: var(--wf-text); background: transparent; padding: 0; }
    .demo-row { display: flex; justify-content: space-between; gap: .75rem; align-items: center; }
    @media (max-width: 380px) { .demo-row { flex-wrap: wrap; } }
</style>
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-grid">
    <div class="login-brand">
      <img src="<?= esc(base_url('/icons/icon-192.png')) ?>?v=20261005a" alt="" width="104" height="104">
      <div class="login-brand-name wf-brand-name">Folio</div>
      <p class="login-brand-sub mb-0">Employee Monitoring &amp; Reports</p>
    </div>
  <div class="card login-card wf-reveal wf-in">
    <div class="login-body-form">
      <h1 class="login-h">Sign in</h1>
      <p class="login-sub-note">Use your work email to continue.</p>
      <?php if ($error): ?><div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Invalid email or password.</div><?php endif; ?>
      <?php if ($logout): ?><div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>You have been logged out.</div><?php endif; ?>
      <form method="post" action="<?= esc(base_url('/login')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="lfEmail">Work email</label>
          <div class="input-group">
            <span class="input-group-text" style="background:var(--wf-surface-2);border-color:var(--wf-border);color:var(--wf-muted)"><i class="bi bi-envelope"></i></span>
            <input type="email" id="lfEmail" name="username" class="form-control" placeholder="you@workfolio.com" required autofocus autocomplete="username">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="lfPass">Password</label>
          <div class="input-group">
            <span class="input-group-text" style="background:var(--wf-surface-2);border-color:var(--wf-border);color:var(--wf-muted)"><i class="bi bi-lock"></i></span>
            <input type="password" id="lfPass" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
            <button class="btn btn-outline-secondary" type="button" id="lfEye" aria-label="Show password" style="border-radius:0 var(--wf-radius-sm) var(--wf-radius-sm) 0"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100" style="min-height:44px"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
      </form>
      <?php if ($showDemo): ?>
      <div class="demo-box small mt-4 p-3">
        <div class="fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>Demo accounts</div>
        <div class="demo-row"><code>admin@workfolio.com</code><code>admin123</code></div>
      </div>
      <?php endif; ?>
      <div class="text-center text-muted small mt-3">
        <i class="bi bi-shield-check me-1"></i>Protected workspace &middot; v<?= esc(app_version()) ?>
      </div>
    </div>
  </div>
  </div>
</div>
<script src="<?= esc(base_url('/assets/wf.js')) ?>?v=20261005a"></script>
<script>
(function () {
  var eye = document.getElementById('lfEye'), pass = document.getElementById('lfPass');
  if (eye && pass) eye.addEventListener('click', function () {
    var show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    eye.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
  });
})();
</script>
</body>
</html>
<?php
    exit;
}

function page_logout(): void {
    do_logout();
    redirect(base_url('/login?logout=1'));
}

/* ------------------------------------------------------------ dashboard ---- */

function page_dashboard(): void {
    $u = require_login();
    $totalEmployees = employee_count();
    $totalAdmins = employee_count_role('ADMIN');
    $totalHR = employee_count_role('HR');
    /* Salary is restricted: only supervisors (SUPER_ADMIN/ADMIN/HR) may see the
       aggregate. Everyone else gets no salary figure at all (req 11/20). */
    $avgSalary = is_supervisor($u) ? round(employee_avg_salary() * 100) / 100 : null;
    $date = today();

    layout_open('Dashboard', $u, '/dashboard');

    /* live duty summary for the roles that supervise */
    $onDuty = null; $todayWorked = null;
    if (is_supervisor($u)) {
        $onDuty = 0;
        $todayWorked = 0;
        foreach (access_visible_employees($u) as $e) {
            $r = track_record_for($e['email'], $date);
            if (track_on_duty($r)) $onDuty++;
            $todayWorked += track_worked_seconds($r);
        }
    }
    ?>
<div class="page-head">
  <div>
    <h4>Welcome back, <?= esc(employee_full_name($u)) ?></h4>
    <div class="page-sub"><?= esc($u['designation'] ?? '-') ?> &middot; <?= esc($u['department'] ?? '-') ?> &middot; <?= esc(date('l, d M Y')) ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= esc(base_url('/time')) ?>"><i class="bi bi-clock"></i> My Time</a>
    <?php if (is_supervisor($u)): ?>
    <a class="btn btn-primary" href="<?= esc(base_url('/monitor')) ?>"><i class="bi bi-broadcast-pin"></i> Open Live Monitor</a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card p-3 card-hover wf-reveal">
    <span class="stat-ico"><i class="bi bi-people-fill"></i></span>
    <div class="stat-txt"><div class="stat-num"><?= (int) $totalEmployees ?></div><div class="stat-lbl">Employees</div></div>
  </div></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card p-3 card-hover wf-reveal">
    <span class="stat-ico" style="background:linear-gradient(135deg,#f472b6,#db2777)"><i class="bi bi-shield-check"></i></span>
    <div class="stat-txt"><div class="stat-num"><?= (int) $totalAdmins ?></div><div class="stat-lbl">Admins</div></div>
  </div></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card p-3 card-hover wf-reveal">
    <span class="stat-ico" style="background:linear-gradient(135deg,#4ade80,#16a34a)"><i class="bi bi-person-badge"></i></span>
    <div class="stat-txt"><div class="stat-num"><?= (int) $totalHR ?></div><div class="stat-lbl">HR</div></div>
  </div></div>
  <?php if (is_supervisor($u)): ?>
  <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card p-3 card-hover wf-reveal">
    <span class="stat-ico" style="background:linear-gradient(135deg,#fbbf24,#f59e0b)"><i class="bi bi-cash-stack"></i></span>
    <div class="stat-txt"><div class="stat-num">₹<?= number_format((float) $avgSalary, 0) ?></div><div class="stat-lbl">Avg Salary</div></div>
  </div></div>
  <?php endif; ?>
  <?php if (is_supervisor($u)): ?>
  <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card p-3 card-hover wf-reveal">
    <span class="stat-ico" style="background:linear-gradient(135deg,#f87171,#dc2626)"><i class="bi bi-broadcast"></i></span>
    <div class="stat-txt"><div class="stat-num"><?= (int) $onDuty ?></div><div class="stat-lbl">On Duty Now</div></div>
  </div></div>
  <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card p-3 card-hover wf-reveal">
    <span class="stat-ico" style="background:linear-gradient(135deg,#7dd3fc,#0ea5e9)"><i class="bi bi-hourglass-split"></i></span>
    <div class="stat-txt"><div class="stat-num"><?= esc(fmt_dur($todayWorked)) ?></div><div class="stat-lbl">Worked Today</div></div>
  </div></div>
  <?php endif; ?>
</div>

<?php if (is_supervisor($u)): ?>
<div class="card p-3 mt-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <div class="fw-bold"><i class="bi bi-broadcast-pin me-2" style="color:var(--wf-primary-2)"></i>Live floor</div>
      <div class="page-sub">See every visible screen as a live video channel, the way a broadcast plays.</div>
    </div>
    <a class="btn btn-primary" href="<?= esc(base_url('/monitor')) ?>"><i class="bi bi-play-circle"></i> Watch live</a>
  </div>
</div>
<?php endif; ?>

<?php /* ------------------------------------------------- talk listener (browser) --
 * The other half of supervisor talk, and the one that needs no install: whoever is
 * signed in here gets a receiver that plays the supervisor's voice out of their own
 * speakers.
 *
 * It lives on the dashboard because that is the page an employee already keeps open
 * while working, so "they have the site open" is the same statement as "they can
 * hear me" - no second app, no version to roll out.
 *
 * The banner is the whole reason this is allowed to exist. Audio coming out of
 * nobody's mouth with no way to stop it is harassment, so the supervisor is named on
 * screen before the first sample plays and Mute/Decline are right there.
 */ ?>
<?php if (!is_super_admin($u)): ?>
<div class="card p-3 mt-3" id="wf-talk-sound">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <div class="fw-bold"><i class="bi bi-headset me-2" style="color:var(--wf-primary-2)"></i>Talk sound</div>
      <div class="page-sub" id="wf-talk-note">Lets your manager speak to you through this computer. You will always see who is speaking, and you can mute or decline at any time.</div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-bg-light border" id="wf-talk-dot"><i class="bi bi-volume-mute"></i> Off</span>
      <button class="btn btn-primary" id="wf-talk-enable" type="button"><i class="bi bi-volume-up"></i> Enable talk sound</button>
    </div>
  </div>
</div>

<div id="wf-talk-banner" style="position:fixed;left:50%;transform:translateX(-50%);bottom:24px;z-index:2000;display:none;min-width:340px;max-width:92vw">
  <div class="card border-warning shadow-lg" style="border-width:2px">
    <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-2">
      <span class="badge text-bg-danger"><i class="bi bi-mic-fill"></i> LIVE TALK</span>
      <span class="fw-bold" id="wf-talk-who">&nbsp;</span>
      <span class="ms-auto d-flex gap-2">
        <button class="btn btn-sm btn-outline-light" id="wf-talk-mute" type="button"><i class="bi bi-volume-up-fill"></i> Mute</button>
        <button class="btn btn-sm btn-danger" id="wf-talk-decline" type="button">Decline</button>
      </span>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* ---------------------------------------------------------- talk listener ------
 * Plays supervisor audio in the employee's own browser, so nobody has to install
 * or update anything to be reachable.
 *
 * Three browser facts shape this, because each one breaks the obvious version:
 *
 * 1. AUTOPLAY IS BLOCKED until the user interacts with the page. An AudioContext
 *    starts suspended and stays there. So sound is genuinely off until a real
 *    gesture happens, and we take the first one anywhere on the page instead of
 *    making people find one specific button.
 *
 * 2. A HIDDEN TAB HAS ITS TIMERS THROTTLED to roughly once a minute, which would
 *    turn live talk into a stutter and then into silence - exactly while the
 *    employee is doing the work this feature is for. So the poll loop runs in a
 *    Worker, whose timers are not throttled that way. Web Audio keeps rendering
 *    while hidden, so already-scheduled audio still plays.
 *
 * 3. EVERY REQUEST COSTS A PHP PROCESS, and the production pool is small enough
 *    that a screenful of open dashboards could starve the site, the Live Monitor
 *    included. So the cadence adapts: fast only while a session is actually live,
 *    slow when there is nothing to hear. The poll doubles as the presence
 *    heartbeat, so proving the page is open costs no extra requests at all.
 */
(function () {
  var root = document.getElementById('wf-talk-sound');
  if (!root) return;

var POLL  = <?= json_encode(base_url('/api/talk/listener/poll'), JSON_UNESCAPED_SLASHES) ?>;
  var MARK  = <?= json_encode(base_url('/api/talk/listener/ready'), JSON_UNESCAPED_SLASHES) ?>;
  var ACK   = <?= json_encode(base_url('/api/talk/listener/ack'), JSON_UNESCAPED_SLASHES) ?>;

  /* A Worker created from a blob: URL uses that blob as its base URL, and a blob:
     URL is not hierarchical, so fetch() inside it cannot resolve a relative path
     and throws "Failed to parse URL". The request never leaves the worker, so
     from the page nothing looks wrong: no error event, no exception, the poll
     loop simply stops and the supervisor is never answered again. That is the
     exact reason this had to be an absolute URL. app_origin() is the same
     validated helper the sign-in email uses, so the Host header cannot bend it. */
  var WPOLL = <?= json_encode(app_origin() . base_url('/api/talk/listener/poll'), JSON_UNESCAPED_SLASHES) ?>;

  var RATE  = 16000;
  var FAST  = 180;    /* a session is live: keep up with speech */
  var IDLE  = 1500;   /* sound is on, waiting for someone to speak */
  var DARK  = 4000;   /* sound is off: just prove the page is open */

  var noteEl    = document.getElementById('wf-talk-note');
  var dotEl     = document.getElementById('wf-talk-dot');
  var enableEl  = document.getElementById('wf-talk-enable');
  var bannerEl  = document.getElementById('wf-talk-banner');
  var whoEl     = document.getElementById('wf-talk-who');
  var muteEl    = document.getElementById('wf-talk-mute');
  var declineEl = document.getElementById('wf-talk-decline');

  var ctx = null, sound = false, muted = false, inFlight = 0, shownTo = null;
  var chain = Promise.resolve(), wait = DARK, worker = null, timer = null;
  var lastBeat = 0, stalled = 0;
    /* Highest seq already queued, so one chunk can never be played twice. The server
       deliberately re-sends a chunk until it is acked, so a poll can legitimately
       hand back the same seq. */
    var queued = -1, curTalk = null;

  var REFUSALS = {
    'policy':  'Talk is switched off for your account, so nobody can reach you.',
    'on-break':'You are on a break, so talk will not interrupt you.',
    'off-duty':'You are not on duty right now, so talk will not play.'
  };

  function note(msg) { if (noteEl) noteEl.textContent = msg; }

  /* ---- turning sound on ---------------------------------------------------
   * The one silent sample below is the gesture the browser wants, played inside
   * the handler. That is why sound can start on the very first click instead of
   * the second one after a failed attempt. */
  function enable() {
    if (!ctx) {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { note('This browser cannot play talk audio.'); return; }
      ctx = new AC();
      /* The context reports its own state changes, and the card follows them.
         resume() is asynchronous, so without this the button kept claiming to be
         off long after sound was in fact working, which reads as broken. */
      ctx.addEventListener('statechange', function () {
        render();
        if (ctx.state === 'running') { retune(); poll(); }
      });
    }
    sound = true;

    /* Safari will not start a context from resume() alone; it wants a source
       actually started inside the gesture. One silent sample is enough. */
    try {
      var s = ctx.createBufferSource();
      s.buffer = ctx.createBuffer(1, 1, RATE);
      s.connect(ctx.destination);
      s.start(0);
    } catch (e) { /* the wait() below is the real check */ }

    note('Ready. You will see a banner naming whoever is speaking, and it can be muted or declined.');
    render();
    /* Called synchronously from the click, so the resume() inside is still inside
       the gesture and browsers allow it. */
    ready().then(function (running) {
      render();
      if (running) { retune(); poll(); return; }
      note('Your browser is still holding sound back. Click anywhere on this page once, then press the button again.');
    });
  }

  enableEl.addEventListener('click', enable);

  /* Any interaction counts. People click on this page constantly; insisting they
     find one particular button would be a worse product for no benefit. */
  ['pointerdown', 'keydown'].forEach(function (ev) {
    window.addEventListener(ev, function once() {
      if (!ctx) return;
      window.removeEventListener(ev, once);
      ctx.resume().then(function () {
        if (ctx.state === 'running' && !sound) { sound = true; render(); retune(); poll(); }
      });
    }, { once: true });
  });

  function render() {
    var on = sound && ctx && ctx.state === 'running';
    var held = sound && ctx && ctx.state !== 'running';
    dotEl.className = 'badge ' + (on ? 'text-bg-success' : (held ? 'text-bg-warning' : 'text-bg-light border'));
    dotEl.innerHTML = on ? '<i class="bi bi-volume-up"></i> On'
      : (held ? '<i class="bi bi-pause-fill"></i> Paused'
              : '<i class="bi bi-volume-mute"></i> Off');
    enableEl.className = 'btn ' + (on ? 'btn-outline-secondary' : 'btn-primary');
    enableEl.innerHTML = on ? '<i class="bi bi-check-lg"></i> Sound is on'
      : (held ? '<i class="bi bi-volume-up"></i> Resume talk sound'
              : '<i class="bi bi-volume-up"></i> Enable talk sound');
  }

  /* ---- banner: the transparency guarantee ---------------------------------
   * It never hides itself while somebody is speaking, and it never plays a single
   * sample before it is on screen. */
  function show(name) {
    if (shownTo === name) return;
    shownTo = name;
    whoEl.textContent = name;
    bannerEl.style.display = 'block';
  }
  function hide() { shownTo = null; bannerEl.style.display = 'none'; }

  function setMuted(on) {
    muted = on;
    muteEl.className = 'btn btn-sm ' + (on ? 'btn-warning' : 'btn-outline-light');
    muteEl.innerHTML = on ? '<i class="bi bi-volume-mute-fill"></i> Muted'
                          : '<i class="bi bi-volume-up-fill"></i> Mute';
  }
  muteEl.addEventListener('click', function () { setMuted(!muted); });

  /* Decline is final for this session. seq -1 ends the session server-side
     without claiming any chunk was ever heard, so the supervisor is told the
     truth: they were cut off, not ignored. */
  declineEl.addEventListener('click', function () {
    setMuted(true);
    ack(-1, 'declined').then(function () {
      hide();
      note('You declined the talk. Nothing is being played.');
    });
  });

  /* ---------------------------------------------------------------- audio ---- */
  function decodePcm(b64) {
    var bin = atob(b64), n = bin.length >> 1, i;
    var bytes = new Uint8Array(n * 2);
    for (i = 0; i < bytes.length; i++) bytes[i] = bin.charCodeAt(i);
    var view = new DataView(bytes.buffer), out = new Float32Array(n);
    for (i = 0; i < n; i++) out[i] = view.getInt16(i * 2, true) / 32768;
    var buf = ctx.createBuffer(1, n, RATE);
    buf.copyToChannel(out, 0);
    return buf;
  }

  function ack(seq, kind) {
    return fetch(ACK, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ seq: seq, ack: kind })
    }).then(function (r) { return r.json(); }).catch(function () { return null; });
  }

  /* Resolves true once the context is genuinely rendering. Bounded, so a context
     that never starts cannot wedge the play queue and silence every later chunk
     behind it - which is what used to happen. */
  function ready(ms) {
    if (!ctx) return Promise.resolve(false);
    if (ctx.state === 'running') return Promise.resolve(true);
    ms = ms || 4000;
    return new Promise(function (done) {
      var over = false, timer = null;
      function finish(v) {
        if (over) return;
        over = true;
        if (timer) clearTimeout(timer);
        try { ctx.removeEventListener('statechange', on); } catch (e) {}
        done(v);
      }
      function on() { if (ctx.state === 'running') finish(true); }
      timer = setTimeout(function () { finish(ctx.state === 'running'); }, ms);
      try { var r = ctx.resume(); if (r && r.then) r.then(on).catch(function () {}); } catch (e) {}
      try { ctx.addEventListener('statechange', on); } catch (e) {}
      on();
    });
  }

  /* Chunks play strictly in order and are acked only once each has actually
     finished. That is what keeps the supervisor's panel honest about what was
     heard, and stops a slow link piling up lag behind the live voice. */
  function play(seq, pcm) {
    /* At-most-once, enforced on this side as well as the server's. The server keeps
       re-offering a chunk until it is acked, so a fast poll - which the main-thread
       fallback always is - can return the same seq again while the first copy is
       still playing. Queuing it a second time repeats the sentence back at the
       employee, which is a far worse fault than a brief stutter. */
    if (seq <= queued) return;
    queued = seq;
    inFlight++;
    chain = chain.then(function () {
      /* Mute is the employee exercising control, so discarding is right. A context
         that is merely not running yet is not the same thing: the words are still
         on the server, and acking them "muted" deleted them before a single sample
         was rendered. That is why this page was silent even with everything else
         working. Wait for the context instead. */
      if (muted) { stalled = 0; return ack(seq, 'muted'); }
      if (seq == null || !pcm) return Promise.resolve(null);
      return ready().then(function (running) {
        if (!running) {
          /* The chunk stays on the server so the next poll offers it again: these
             words have not been heard yet, and discarding them is precisely how
             this page ended up silent. Bounded, so a context that is never going
             to start cannot hold the queue for ever. */
          stalled++;
          if (stalled <= 3) return null;
          return ack(seq, 'muted');
        }
        stalled = 0;
        return new Promise(function (done) {
          var src = ctx.createBufferSource();
          src.buffer = decodePcm(pcm);
          src.connect(ctx.destination);
          src.onended = function () { done(); };
          src.start();
        }).then(function () { return ack(seq, 'heard'); });
      });
    }).catch(function () {
      return ack(seq, 'muted');
    }).then(function (r) {
      inFlight--;
      retune();
      return r;
    });
  }

  function retune() {
    var want = (inFlight > 0 || shownTo !== null) ? FAST : (sound ? IDLE : DARK);
    if (want === wait) return;
    wait = want;
    if (worker) worker.postMessage({ wait: wait, sound: sound });
  }

  /* ------------------------------------------------------------------ loop --- */
  function handle(j) {
    if (!j || !j.ok) return;

    /* A new session restarts the sequence at zero, so the played watermark has to
       reset with it or the first chunks of the next talk would be discarded. */
    if (j.talkId !== curTalk) { curTalk = j.talkId; queued = -1; }

    if (j.refused) { note(REFUSALS[j.refused] || ''); hide(); return; }
    if (j.active && j.supervisorName) show(j.supervisorName);
    if (j.ack === 'declined') { hide(); note('That talk was declined.'); return; }

    if (j.seq != null && j.pcm) { play(j.seq, j.pcm); return; }

    if (!j.active && inFlight === 0) hide();
    retune();
  }

  function poll() {
    fetch(POLL + (POLL.indexOf('?') < 0 ? '?' : '&') + 'sound=' + (sound ? 1 : 0), {
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (r) { return r.json(); })
      .then(handle)
      .catch(function () {});
  }

  /* The loop runs in a Worker so a hidden tab keeps polling. The main-thread
     fallback still works; it is just throttled while the tab is in the
     background, which is why the Worker is tried first. */
  function loop() {
    poll();
    timer = setTimeout(loop, wait);
  }

  /* The main-thread loop, and the permanent answer to a broken Worker. Its timers
     are throttled while the tab is hidden, which is worse - but it is degraded,
     not deaf. */
  function startTimer() {
    if (timer) return;
    loop();
  }

  function dropWorker() {
    if (worker) { try { worker.terminate(); } catch (e) {} worker = null; }
    startTimer();
  }

  /* The loop runs in a Worker so a hidden tab keeps polling. The main-thread
     fallback still works; it is just throttled while the tab is in the
     background, which is why the Worker is tried first. */
  function kick() {
    if (worker || timer) return;
    try {
      var src = [
        'var u = ' + JSON.stringify(WPOLL) + ';',
        'var wait = ' + DARK + ';',
        'var sound = 0;',
        'self.onmessage = function (e) {',
        '  if (!e.data) return;',
        '  if (e.data.wait) wait = e.data.wait;',
        '  sound = e.data.sound ? 1 : 0;',
        '};',
        'function loop() {',
        '  var sep = u.indexOf("?") < 0 ? "?" : "&";',
        '  fetch(u + sep + "sound=" + sound, { credentials: "same-origin", cache: "no-store" })',
        '    .then(function (r) { return r.json(); })',
        '    .then(function (j) { self.postMessage(j); })',
        '    .catch(function () {})',
        '    .then(function () { setTimeout(loop, wait); });',
        '}',
        'loop();'
      ].join('\n');
      worker = new Worker(URL.createObjectURL(new Blob([src], { type: 'application/javascript' })));
      worker.onmessage = function (e) { lastBeat = Date.now(); handle(e.data); };
      /* An error inside the worker never surfaces as a page exception, so it is
         caught explicitly rather than left to strand the page in silence. */
      worker.onerror = function () { dropWorker(); };
      lastBeat = Date.now();
      return;
    } catch (e) { worker = null; }

    startTimer();
  }

  /* Belt and braces for the failure that actually happened: the Worker was built
     fine, then quietly stopped being able to reach the network. Nothing throws in
     that case, so the page watches for the beat going stale instead of trusting
     that silence means "nothing to play". */
  setInterval(function () {
    if (worker && Date.now() - lastBeat > 12000) dropWorker();
  }, 4000);

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) return;
    /* Coming back to the tab is the one moment we are definitely allowed to
       restart a context, and it is also when anything queued while hidden has to
       be picked up again. */
    if (ctx && ctx.state !== 'running') ctx.resume().then(function () { render(); poll(); });
    render();
    retune();
    if (!worker) startTimer();
  });

  /* A tab being closed must not leave the supervisor believing somebody is still
     listening, so mark sound off on the way out. Best effort - the 30 second
     presence timeout is the real backstop. */
  window.addEventListener('pagehide', function () {
    if (navigator.sendBeacon) {
      navigator.sendBeacon(MARK, new Blob(['{"sound":false}'], { type: 'application/json' }));
    }
  });

  render();
  retune();
  kick();
})();
</script>
    <?php
    layout_close();
}

/* ---------------------------------------------------------------- profile ---- */

function page_profile(): void {
    $u = require_login();
    layout_open('Profile', $u, '/profile');
    $rows = [
        'Full Name' => employee_full_name($u),
        'Email' => $u['email'],
        'Role' => $u['role'],
        'Department' => $u['department'] ?? '-',
        'Designation' => $u['designation'] ?? '-',
        'Phone' => $u['phone'] ?? '-',
        'Join Date' => $u['join_date'] ?? '-',
        'Address' => $u['address'] ?? '-',
    ];
    ?>
<div class="card p-4" style="max-width:640px">
  <h4><i class="bi bi-person-circle me-2 text-primary"></i>My Profile</h4>
  <hr>
  <table class="table table-sm">
    <?php foreach ($rows as $k => $v): ?>
    <tr><th class="text-muted" style="width:180px"><?= esc($k) ?></th><td><?= esc((string) $v) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
    <?php
    layout_close();
}

/* ------------------------------------------------------------------ time ---- */

function page_time(): void {
    $viewer = require_login();
    $emp = $viewer;
    $requested = trim((string) ($_GET['email'] ?? ''));
    if ($requested !== '' && access_can_view_email($viewer, $requested)) {
        $found = employee_find_by_email($requested);
        if ($found !== null) $emp = $found;
    }
    $summary = track_summary($emp, today());
    $history = track_history($emp['email']);

    layout_open('My Time', $viewer, '/time');
    ?>
<div class="page-head">
  <div>
    <h4><?= esc(employee_full_name($emp)) ?> &middot; Today</h4>
    <div class="page-sub"><?= esc(date('l, d M Y')) ?> &middot; live figures update as the agent reports</div>
  </div>
</div>
<?php
$tiles = [
    [$summary['worked'], 'Worked', 'bi-stopwatch', ''],
    [$summary['active'], 'Active', 'bi-lightning-charge', 'linear-gradient(135deg,#4ade80,#16a34a)'],
    [$summary['idle'], 'Idle', 'bi-pause-circle', 'linear-gradient(135deg,#fbbf24,#f59e0b)'],
    [$summary['break'], 'Break', 'bi-cup-hot', 'linear-gradient(135deg,#7dd3fc,#0ea5e9)'],
    // Lid-closed / asleep time. Shown apart from Idle so a manager can tell the
    // employee away from their desk apart from the employee sitting idle.
    [$summary['paused'], 'Laptop Away', 'bi-laptop', 'linear-gradient(135deg,#94a3b8,#64748b)'],
    [(int) $summary['productivity'] . '%', 'Productivity', 'bi-speedometer2', 'linear-gradient(135deg,#f472b6,#db2777)'],
    [(int) $summary['screenshots'], 'Screenshots', 'bi-camera', 'linear-gradient(135deg,#a78bfa,#7c3aed)'],
    [(int) $summary['keystrokes'], 'Keystrokes', 'bi-keyboard', ''],
    [(int) $summary['mouseClicks'], 'Clicks', 'bi-cursor', ''],
    [esc(mouse_distance_text((int) $summary['mouseDistancePx'])), 'Mouse Distance', 'bi-arrows-move', ''],
    [(int) $summary['distinctApps'] . ' / ' . (int) $summary['distinctSites'], 'Apps / Sites', 'bi-window-stack', ''],
];
?>
<div class="row g-3 mb-4">
  <?php foreach ($tiles as $t): ?>
  <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card p-3 card-hover wf-reveal">
    <?php if ($t[3] !== ''): ?><span class="stat-ico" style="background:<?= esc($t[3]) ?>"><i class="bi <?= esc($t[2]) ?>"></i></span><?php endif; ?>
    <?php if ($t[3] === ''): ?><span class="stat-ico"><i class="bi <?= esc($t[2]) ?>"></i></span><?php endif; ?>
    <div class="stat-txt"><div class="stat-num"><?= $t[0] ?></div><div class="stat-lbl"><?= esc($t[1]) ?></div></div>
  </div></div>
  <?php endforeach; ?>
</div>
<?php
// Each break gets its own line, with its own length, so "how long was the
// break" is answerable without adding up anything. Nothing about what the
// employee did during the break is stored or shown - that is the point.
$todayBreaks = break_records_for($emp['email'], today());
?>
<?php if ($todayBreaks || (int) ($summary['breakSec'] ?? 0) > 0): ?>
<div class="card p-3 mb-4">
  <h5><i class="bi bi-cup-hot me-2 text-primary"></i>Break log
    <span class="text-soft small fw-normal ms-1">- <?= esc($summary['break']) ?> total today, nothing recorded during a break</span>
  </h5>
  <div class="table-responsive">
  <table class="table table-sm mb-0">
    <thead class="table-light"><tr><th>#</th><th>From</th><th>To</th><th>Length</th></tr></thead>
    <tbody>
    <?php if (!$todayBreaks): ?>
      <tr><td colspan="4" class="text-muted">Break in progress - no completed break yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($todayBreaks as $i => $b): ?>
      <tr>
        <td><?= (int) ($i + 1) ?></td>
        <td><?= esc($b['start']) ?></td>
        <td><?= esc($b['end'] ?? 'in progress') ?></td>
        <td><?= esc($b['dur']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($summary['currentApp']) || !empty($summary['currentHost'])): ?>
<div class="card p-3 mb-4">
  <div class="text-soft small mb-1">Currently working on</div>
  <div class="fw-semibold"><?= esc($summary['currentApp'] ?? '-') ?> <?= !empty($summary['currentTitle']) ? '&middot; ' . esc($summary['currentTitle']) : '' ?></div>
  <div class="text-muted small"><?= esc($summary['currentHost'] ?? '') ?> <span class="badge bg-light text-dark ms-1"><?= esc($summary['currentCategory'] ?? '') ?></span></div>
</div>
<?php endif; ?>
<div class="card p-3">
  <h5><i class="bi bi-calendar3 me-2 text-primary"></i>History</h5>
  <div class="table-responsive">
  <table class="table table-sm table-hover">
    <thead class="table-light"><tr><th>Date</th><th>Check In</th><th>Check Out</th><th>Worked</th><th>Active</th><th>Idle</th><th>Break</th><th>Paused</th><th>Productivity</th><th>Shots</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td><?= esc($h['work_date']) ?></td>
        <td><?= esc(track_check_in_text($h)) ?></td>
        <td><?= esc(track_check_out_text($h)) ?></td>
        <td><?= esc(fmt_dur(track_worked_seconds($h))) ?></td>
        <td><?= esc(fmt_dur((int) $h['active_seconds'])) ?></td>
        <td><?= esc(fmt_dur((int) $h['idle_seconds'])) ?></td>
        <td><?= esc(fmt_dur((int) $h['break_seconds'])) ?></td>
        <td><?= esc(fmt_dur((int) ($h['paused_seconds'] ?? 0))) ?></td>
        <td><?= (int) track_productivity_percent($h) ?>%</td>
        <td><?= (int) $h['screenshot_count'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="9" class="text-center text-muted py-3">No tracking yet for <?= esc(employee_full_name($emp)) ?>.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

/* ---------------------------------------------------------------- monitor ---- */

function page_monitor(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $employees = access_visible_employees($u);
    $apiLive = base_url('/api/monitor/live');
    $apiFrame = base_url('/api/monitor/live-frame');
    $apiShot = base_url('/api/monitor/shot');
    $apiUsage = base_url('/api/monitor/usage');
    $apiTalkStart = base_url('/api/monitor/talk/start');
    $apiTalkChunk = base_url('/api/monitor/talk/chunk');
    $apiTalkStop  = base_url('/api/monitor/talk/stop');
    $apiTalkState = base_url('/api/monitor/talk/state');
    layout_open('Live Monitor', $u, '/monitor');
    ?>
<style>
    .wf-pulse { animation:wfPulse 1.2s infinite; }
    @keyframes wfPulse { 0%,100% { opacity:1 } 50% { opacity:.25 } }
    .wf-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#fff; margin-right:6px; }
    .stage { position:relative; width:100%; aspect-ratio:16/9; background:#05070d; border-radius:12px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.30); }
    .stage canvas, .tile canvas { width:100%; height:100%; display:block; }
    .ovl { position:absolute; pointer-events:none; color:#fff; text-shadow:0 1px 4px rgba(0,0,0,.85); }
    .ovl-tl { top:10px; left:12px; } .ovl-tr { top:10px; right:12px; text-align:right; }
    .ovl-b { left:0; right:0; bottom:0; padding:30px 12px 10px; background:linear-gradient(180deg, transparent, rgba(0,0,0,.82)); }
    .ovl-b.tiny { padding:20px 8px 6px; }
    .stage-note { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#9aa0b0; font-size:.92rem; text-align:center; padding:24px; }
    .tile { position:relative; width:100%; aspect-ratio:16/9; background:#05070d; border-radius:10px; overflow:hidden; cursor:pointer; border:2px solid transparent; }
    .tile.sel { border-color:#0d6efd; box-shadow:0 0 0 3px rgba(13,110,253,.15); }
    .tile:hover { border-color:#adb5bd; }
    .tile.sel:hover { border-color:#0d6efd; }
    .chan { display:inline-flex; align-items:center; gap:8px; padding:5px 12px; border-radius:999px; border:1px solid #e6e8ee; background:#fff; cursor:pointer; font-size:.82rem; }
    .chan.sel { border-color:#0d6efd; box-shadow:0 0 0 2px rgba(13,110,253,.15); font-weight:600; }
    .chan .pip { width:8px; height:8px; border-radius:50%; background:#c9ccd6; flex:0 0 auto; }
    .chan.on .pip { background:#e03131; }
    .chan.on .pip.wf-pulse { background:#e03131; }
    .stat-strip { font-size:.78rem; }
</style>

<div class="page-head">
  <div>
    <h4><i class="bi bi-broadcast-pin me-2" style="color:var(--wf-primary-2)"></i>Live Monitor <span class="badge bg-secondary ms-2" id="mon-count">connecting</span></h4>
    <div class="page-sub" id="mon-updated" data-wf-now="<?= esc((string) time()) ?>" data-wf-tz="<?= esc(wf_tz()) ?>">&nbsp;</div>
  </div>
  <div class="text-end">
    <div class="page-sub" id="mon-stats">&nbsp;</div>
  </div>
</div>

<div class="card p-2 mb-3">
  <div class="stage" id="stage">
    <canvas id="main-canvas" width="1280" height="720"></canvas>
    <div class="ovl ovl-tl" id="main-live"></div>
    <div class="ovl ovl-tr" id="main-who"></div>
    <div class="ovl ovl-b" id="main-what"></div>
    <div class="stage-note" id="main-note">Loading live channels&hellip;</div>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2 mt-2 px-1">
    <div class="d-flex flex-wrap gap-2" id="mon-channels"></div>
<div class="ms-auto d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-danger" id="main-talk" disabled
              data-bs-toggle="tooltip" title="Talk to the selected person's laptop: click to start, click again to stop. They can mute or decline at any time.">
        <i class="bi bi-mic-fill me-1"></i>Talk
      </button>
      <span class="align-self-center small text-muted d-none" id="talk-state"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="main-pause"><i class="bi bi-pause-fill me-1"></i>Pause</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="main-full"><i class="bi bi-fullscreen me-1"></i>Full screen</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="main-rec"><i class="bi bi-camera me-1"></i>Archived shot</button>
      <button type="button" class="btn btn-sm btn-outline-primary" id="main-usage"><i class="bi bi-bar-chart me-1"></i>Apps / Sites</button>
    </div>
  </div>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 px-1">
    <div class="text-muted small">Live frames overwrite one slot per person, so the stream costs no archive space. Use <b>Pause</b> when nobody is watching to save bandwidth.</div>
    <button type="button" class="btn btn-sm btn-outline-danger" id="pause-all"><i class="bi bi-pause-fill me-1"></i>Pause all streams</button>
  </div>
</div>

<h6 class="text-muted small text-uppercase">All live channels</h6>
<div class="row g-3" id="mon-grid"></div>

<!-- apps / sites drill-down -->
<div class="modal fade" id="ddModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="ddTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="ddBody"></div></div></div></div>

<!-- last archived (evidence) screenshot -->
<div class="modal fade" id="recModal" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="recTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center" id="recBody"></div></div></div></div>

<script>
(function () {
  var LIVE = '<?= esc($apiLive) ?>';
  var FRAME = '<?= esc($apiFrame) ?>';
  var SHOT = '<?= esc($apiShot) ?>';
  var USAGE = '<?= esc($apiUsage) ?>';
  var TALK_START = '<?= esc($apiTalkStart) ?>';
  var TALK_CHUNK = '<?= esc($apiTalkChunk) ?>';
  var TALK_STOP  = '<?= esc($apiTalkStop) ?>';
  var TALK_STATE = '<?= esc($apiTalkState) ?>';

  var grid = document.getElementById('mon-grid');
  var chanBox = document.getElementById('mon-channels');
  var upd = document.getElementById('mon-updated');
  var clock = document.getElementById('mon-clock');
  var countEl = document.getElementById('mon-count');
  var statsEl = document.getElementById('mon-stats');
  var stage = document.getElementById('stage');
  var note = document.getElementById('main-note');
  var mainCanvas = document.getElementById('main-canvas');
  var mainCtx = mainCanvas.getContext('2d');
  var oLive = document.getElementById('main-live');
  var oWho = document.getElementById('main-who');
  var oWhat = document.getElementById('main-what');
  var pauseBtn = document.getElementById('main-pause');
  var usageBtn = document.getElementById('main-usage');
  var pauseAllBtn = document.getElementById('pause-all');

  var streams = {};      // email -> live stream state
  var order = [];        // display order
  var selected = null;   // email shown in the big player
  var pausedAll = false;
  var framesDrawn = 0;

function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
  function attr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function t12(d) { return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' }); }

  /* Every time on this page comes from the server: frame ages, "last seen",
     capture stamps. The header clock used to read the viewer's own machine, so
     on a laptop set to the wrong zone the monitor claimed frames were minutes
     old while the ages beside them said seconds. The page carries the server's
     stamp in data-wf-now and we tick from that instead. performance.now() keeps
     the elapsed part monotonic, so correcting the system clock cannot make it
     jump or stall. */
  var srvStart = parseInt(upd.getAttribute('data-wf-now'), 10);
  var srvBase = performance.now();
  if (isNaN(srvStart)) srvStart = Math.floor(Date.now() / 1000);
  function srvDate() { return new Date((srvStart + (performance.now() - srvBase) / 1000) * 1000); }
  function agoTxt(sec) {
    if (sec == null) return '?';
    if (sec < 2) return 'just now';
    if (sec < 60) return Math.round(sec) + 's ago';
    return Math.round(sec / 60) + 'm ago';
  }

  function makeStream(r) {
    var c = document.createElement('canvas');
    c.width = 640; c.height = 360;
    return {
      email: r.email, row: r, seq: 0, busy: false, lastTry: 0, lastDraw: 0,
      lastNew: 0, frames: 0, paused: false,
      canvas: c, ctx: c.getContext('2d'), tile: null, badgeEl: null, whatEl: null
    };
  }

  /* letterboxed draw: keeps the employee's real aspect ratio, TV style */
  function draw(ctx, canvas, bmp) {
    var w = bmp.width || bmp.naturalWidth, h = bmp.height || bmp.naturalHeight;
    if (!w || !h) return;
    var s = Math.min(canvas.width / w, canvas.height / h);
    var dw = w * s, dh = h * s;
    ctx.fillStyle = '#05070d';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bmp, (canvas.width - dw) / 2, (canvas.height - dh) / 2, dw, dh);
  }

  function decode(got) {
    if (window.createImageBitmap) {
      return createImageBitmap(got.blob).then(function (b) { return { bmp: b, seq: got.seq }; });
    }
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () { resolve({ bmp: img, seq: got.seq }); };
      img.onerror = function () { reject(new Error('decode failed')); };
      img.src = URL.createObjectURL(got.blob);
    });
  }

  /* One poll of the newest frame. ?seq makes it conditional, so polling faster
     than the agent streams costs a 304 and no pixels. The 120ms floor matches
     the agent's frame interval: the viewer must never be what caps the rate. */
  function pull(st, force) {
    if (st.busy) return;
    if (!force && st.lastDraw && (Date.now() - st.lastDraw) < 120) return;
    var seq = force ? 0 : st.seq;
    st.busy = true;
    st.lastTry = Date.now();
    fetch(FRAME + '?email=' + encodeURIComponent(st.email) + '&seq=' + seq, { cache: 'no-store' })
      .then(function (r) {
        if (r.status === 304 || r.status === 404) return null;
        if (!r.ok) throw new Error('http ' + r.status);
        var s = parseInt(r.headers.get('X-Frame-Seq') || '0', 10);
        return r.blob().then(function (b) { return { blob: b, seq: s }; });
      })
      .then(function (got) { return got ? decode(got) : null; })
      .then(function (img) {
        if (!img) return;
        draw(st.ctx, st.canvas, img.bmp);
        if (selected === st.email) draw(mainCtx, mainCanvas, img.bmp);
        if (img.seq) st.seq = img.seq;
        st.lastDraw = Date.now();
        st.lastNew = Date.now();
        st.frames++;
        framesDrawn++;
        if (img.bmp && img.bmp.close) img.bmp.close();
        renderMain();
      })
      .catch(function () { })
      .then(function () { st.busy = false; });
  }

function liveBadge(r, small) {
    if (r.streaming) {
      return '<span class="badge bg-danger"><span class="wf-dot wf-pulse"></span>LIVE &middot; ' + (r.frameAgeSec != null ? r.frameAgeSec + 's ago' : 'now') + '</span>';
    }
    if (!r.frameSeq) {
      // The two reasons a channel never produces a frame at all: nobody switched
      // live streaming on for this person, or their agent simply is not connected
      // yet. Saying which is the difference between a fixable setting and a wait.
      if (r.streamingSet === false) {
        return '<span class="badge bg-secondary" title="Turn on Live frame interval in Agent Settings">live off</span>';
      }
      return r.onDuty
        ? '<span class="badge bg-dark">waiting for stream&hellip;</span>'
        : '<span class="badge bg-secondary">off duty</span>';
    }
    return '<span class="badge bg-warning text-dark">signal lost &middot; ' + agoTxt(r.frameAgeSec) + '</span>';
  }

  function renderWall() {
    var sig = order.join(',');
    if (grid.getAttribute('data-sig') !== sig) {
      grid.innerHTML = order.map(function (email) {
        return '<div class="col-12 col-sm-6 col-xl-4"><div class="tile" data-tile="' + attr(email) + '">'
          + '<div class="slot"></div><div class="ovl ovl-tl tiny"></div><div class="ovl ovl-b tiny"></div></div>'
          + '<div class="small mt-1 px-1 d-flex justify-content-between"><span class="fw-semibold" data-nm></span><span class="text-muted" data-tt></span></div></div>';
      }).join('') || '<div class="col-12 text-center text-muted py-4">No people visible</div>';
      var tiles = grid.querySelectorAll('.tile');
      for (var i = 0; i < tiles.length; i++) {
        var st = streams[tiles[i].getAttribute('data-tile')];
        if (!st) continue;
        tiles[i].querySelector('.slot').appendChild(st.canvas);   // moving the canvas keeps playback alive
        st.tile = tiles[i];
        st.badgeEl = tiles[i].querySelector('.ovl-tl');
        st.whatEl = tiles[i].querySelector('.ovl-b');
        st.nameEl = tiles[i].parentNode.querySelector('[data-nm]');
        st.subEl = tiles[i].parentNode.querySelector('[data-tt]');
      }
      grid.setAttribute('data-sig', sig);
    }
    order.forEach(function (email) {
      var st = streams[email];
      if (!st || !st.tile) return;
      var r = st.row;
      st.tile.classList.toggle('sel', selected === email);
      st.badgeEl.innerHTML = liveBadge(r);
      st.whatEl.innerHTML = '<div class="fw-semibold text-truncate">' + esc(r.currentApp || r.currentHost || 'No active window') + '</div>'
        + (r.currentTitle ? '<div class="text-truncate">' + esc(r.currentTitle) + '</div>' : '');
      if (st.nameEl) st.nameEl.textContent = r.name;
      if (st.subEl) st.subEl.textContent = r.onDuty ? (r.idle ? 'Idle' : 'On duty') : 'Off';
    });
  }

  function renderChannels() {
    chanBox.innerHTML = order.map(function (email) {
      var st = streams[email];
      return '<span class="chan' + (selected === email ? ' sel' : '') + (st.row.streaming ? ' on' : '') + '" data-chan="' + attr(email) + '">'
        + '<span class="pip' + (st.row.streaming ? ' wf-pulse' : '') + '"></span>' + esc(st.row.name) + '</span>';
    }).join('');
  }

  function renderMain() {
    var st = selected ? streams[selected] : null;
    if (!st) {
      note.style.display = 'flex';
      note.textContent = 'No live channels available';
      oLive.innerHTML = ''; oWho.innerHTML = ''; oWhat.innerHTML = '';
      return;
    }
    var r = st.row;
    var gotFrame = st.frames > 0;
    note.style.display = gotFrame ? 'none' : 'flex';
if (!gotFrame) {
      if (r.streamingSet === false) {
        note.innerHTML = 'Live streaming is switched <b>off</b> for ' + esc(r.name)
          + '<br><span class="small">set <b>Live frame interval</b> in Agent Settings</span>';
      } else {
        note.textContent = r.onDuty
          ? 'Connecting to ' + r.name + "'s screen\\u2026 waiting for the agent's live stream"
          : r.name + ' is off duty \\u2014 nothing is being streamed';
      }
    }
    oLive.innerHTML = liveBadge(r);
    var duty = r.onDuty ? '<span class="badge bg-success">On duty</span>' : '<span class="badge bg-secondary">Off</span>';
    var extra = r.idle ? ' <span class="badge bg-warning text-dark">Idle</span>' : '';
    if (r.status === 'ON_BREAK') extra += ' <span class="badge bg-info">Break</span>';
    oWho.innerHTML = '<div class="fw-semibold">' + esc(r.name) + '</div>'
      + '<div class="small">' + esc(r.designation || r.role || '') + (r.department ? ' &middot; ' + esc(r.department) : '') + '</div>'
      + '<div class="mt-1">' + duty + extra + '</div>';
    oWhat.innerHTML = '<div class="fw-semibold text-truncate">' + esc(r.currentApp || r.currentHost || 'No active window') + '</div>'
      + (r.currentTitle ? '<div class="text-truncate">' + esc(r.currentTitle) + '</div>' : '')
      + '<div class="small mt-1">Worked <b>' + esc(r.worked) + '</b> &middot; Active ' + esc(r.active)
      + ' &middot; Idle ' + esc(r.idleTime) + ' &middot; Break ' + esc(r.breakTime)
      + ' &middot; last seen ' + esc(r.lastSeenAgo) + '</div>';
    pauseBtn.innerHTML = st.paused
      ? '<i class="bi bi-play-fill me-1"></i>Resume'
      : '<i class="bi bi-pause-fill me-1"></i>Pause';
  }

  function render() { renderWall(); renderChannels(); renderMain(); }

function select(email) {
    if (selected === email) return;
    /* Switching target mid-session would stream the previous person's audio at the
     * new one, so end the session first. */
    if (talk.on) talkStop();
    selected = email;
    var st = streams[email];
    if (st) st.lastTry = 0;                 // grab the newest frame immediately
    mainCtx.clearRect(0, 0, mainCanvas.width, mainCanvas.height);
    render();
  }

  function refresh() {
    return fetch(LIVE, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (rows) {
        if (!rows || !rows.length) { upd.textContent = 'no people visible'; return; }
var next = [], live = 0, seen = {}, liveOff = [];
        rows.forEach(function (r) {
          seen[r.email] = true;
          next.push(r.email);
          if (!streams[r.email]) streams[r.email] = makeStream(r);
          var st = streams[r.email];
          st.row = r;
          if (r.streaming) live++;
          if (r.streamingSet === false) liveOff.push(r.name);
          // an agent restart resets its frame counter, so follow the server again
          if (r.frameSeq != null && st.seq > r.frameSeq) st.seq = 0;
          // nothing new for 6s although the server has a frame: re-sync
          if (r.frameSeq != null && st.seq === r.frameSeq && st.lastNew && (Date.now() - st.lastNew) > 6000) st.seq = 0;
        });
        Object.keys(streams).forEach(function (e) { if (!seen[e]) delete streams[e]; });
        order = next;
        if (selected && !streams[selected]) selected = null;
        if (!selected && order.length) selected = order[0];
        countEl.textContent = live + ' of ' + rows.length + ' streaming';
        countEl.className = 'badge ms-2 ' + (live ? 'bg-danger' : 'bg-secondary');
        // "0 of 11 streaming" is ambiguous on its own, so name the reason once
        // here rather than making a viewer open every tile to find it.
        upd.textContent = liveOff.length && !live
          ? 'updated ' + t12(srvDate()) + ' \\u2014 live streaming is off for ' + liveOff.length
            + ' of ' + rows.length + ' people (' + esc(liveOff.slice(0, 3).join(', '))
            + (liveOff.length > 3 ? ', \\u2026' : '') + '). Set Live frame interval in Agent Settings.'
          : 'updated ' + t12(srvDate());
render();
        talkSync();
      })
      .catch(function () { upd.textContent = 'offline \\u2013 retrying\\u2026'; });
  }

  function tick() {
    if (clock) clock.textContent = t12(srvDate());
    var now = Date.now();
    // Hidden tabs poll rarely - nobody is watching, so the requests are waste.
    var gap = document.hidden ? 6000 : 120;
    order.forEach(function (email) {
      var st = streams[email];
      if (!st || st.paused || pausedAll) return;
      if (now - st.lastTry < gap) return;
      pull(st, false);
    });
    statsEl.textContent = framesDrawn + ' frames played';
  }

  /* ---- drill-down: apps + sites ---- */
  function fmt(secs) { secs = secs || 0; var h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60); return h + 'h ' + (m < 10 ? '0' : '') + m + 'm'; }
  function openDD(email) {
    if (!email) return;
    fetch(USAGE + '?email=' + encodeURIComponent(email))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        document.getElementById('ddTitle').textContent = d.email + ' - ' + d.date;
        var apps = (d.apps || []).map(function (a) { return '<tr><td>' + esc(a.name) + '</td><td>' + esc(a.category) + '</td><td>' + esc(a.time) + '</td></tr>'; }).join('');
        var sites = (d.sites || []).map(function (a) { return '<tr><td>' + esc(a.name) + '</td><td>' + esc(a.category) + '</td><td>' + esc(a.time) + '</td></tr>'; }).join('');
        document.getElementById('ddBody').innerHTML =
          '<div class="row g-2 mb-2"><div class="col-3 small">Worked <b>' + fmt(d.workedSec) + '</b></div>'
          + '<div class="col-3 small">Active <b>' + fmt(d.activeSec) + '</b></div>'
          + '<div class="col-3 small">Idle <b>' + fmt(d.idleSec) + '</b></div>'
          + '<div class="col-3 small">Shots <b>' + d.screenshotCount + '</b></div></div>'
          + '<h6 class="text-muted">Apps (' + d.appCount + ')</h6><div class="table-responsive"><table class="table table-sm">' + (apps || '<tr><td class="text-muted">none</td></tr>') + '</table></div>'
          + '<h6 class="text-muted">Sites (' + d.siteCount + ')</h6><div class="table-responsive"><table class="table table-sm">' + (sites || '<tr><td class="text-muted">none</td></tr>') + '</table></div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ddModal')).show();
      });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest && t.closest('[data-chan]')) { select(t.closest('[data-chan]').getAttribute('data-chan')); return; }
    if (t.closest && t.closest('[data-tile]')) { select(t.closest('[data-tile]').getAttribute('data-tile')); return; }
  });

  pauseBtn.addEventListener('click', function () {
    var st = selected ? streams[selected] : null;
    if (!st) return;
    st.paused = !st.paused;
    st.lastTry = 0;
    renderMain();
  });
  usageBtn.addEventListener('click', function () { if (selected) openDD(selected); });
  document.getElementById('main-rec').addEventListener('click', function () {
    var st = selected ? streams[selected] : null;
    if (!st || !st.row.shotId) return;
    document.getElementById('recTitle').textContent = st.row.name + ' \\u2013 archived screenshot ' + (st.row.shotAtFmt || '');
    document.getElementById('recBody').innerHTML = '<img src="' + SHOT + '/' + st.row.shotId + '?v=' + Date.now()
      + '" class="img-fluid" style="max-height:78vh" alt="archived screenshot">'
      + '<div class="text-muted small mt-2">Evidence shots are stored on the schedule; the live stream above is not archived.</div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('recModal')).show();
  });
  document.getElementById('main-full').addEventListener('click', function () {
    if (document.fullscreenElement) { document.exitFullscreen(); return; }
    if (stage.requestFullscreen) stage.requestFullscreen();
  });
  pauseAllBtn.addEventListener('click', function () {
    pausedAll = !pausedAll;
    pauseAllBtn.innerHTML = pausedAll
      ? '<i class="bi bi-play-fill me-1"></i>Resume all streams'
      : '<i class="bi bi-pause-fill me-1"></i>Pause all streams';
    if (!pausedAll) order.forEach(function (e) { streams[e].lastTry = 0; });
  });
  document.addEventListener('visibilitychange', function () {
    order.forEach(function (e) { if (streams[e]) streams[e].lastTry = 0; });
if (!document.hidden) refresh();
  });

  /* ------------------------------------------------ talk to a laptop's speakers --
   * Streams this browser's microphone to one employee's WorkFolioAgent, which plays
   * it out of their speakers.
   *
   * The employee is not a passive speaker: their agent shows a banner and can Mute
   * or Decline, and the agent acks every chunk. Those acks come back here, so this
   * panel always shows whether the words were actually heard. Without that feedback
   * a supervisor would have no way to tell a working mic from a muted employee, and
   * would keep talking at someone who has already opted out.
   */
  var talk = {
    on: false, id: null, email: null, seq: 0,
    ctx: null, mic: null, node: null, pending: [], chain: Promise.resolve(), watch: null
  };
  var TALK_RATE = 16000, TALK_CHUNK_MS = 100, TALK_CHUNK_SAMPLES = TALK_RATE * TALK_CHUNK_MS / 1000;

  var talkBtn = document.getElementById('main-talk');
  var talkOut = document.getElementById('talk-state');

  function talkSay(txt, cls) {
    talkOut.textContent = txt || '';
    talkOut.className = 'align-self-center small d-none' + (txt ? ' d-block ' + (cls || 'text-muted') : '');
  }

  /* Only offer the button when there is somebody selected to talk to. */
  function talkSync() {
    if (talk.on) return;
    talkBtn.disabled = !selected;
  }

  function talkJson(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  function stopTracks(s) { if (s && s.getTracks) s.getTracks().forEach(function (t) { t.stop(); }); }

  function talkStart() {
    if (!selected || talk.on) return;
    var email = selected;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      talkSay('This browser cannot capture audio', 'text-danger');
      return;
    }

    talkSay('asking for microphone permission...', 'text-muted');
    talkBtn.disabled = true;

    navigator.mediaDevices.getUserMedia({
      audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      video: false
    }).then(function (stream) {
      return talkJson(TALK_START, { email: email }).then(function (s) {
        if (!s || !s.ok) { stopTracks(stream); throw new Error((s && s.error) || 'could not start'); }
        talkOpen(stream, email, s.talkId);
      });
    }).catch(function (e) {
      stopTracks(talk.mic);
      talk.on = false; talkBtn.disabled = false; talkBtn.classList.remove('btn-danger');
      talkBtn.innerHTML = '<i class="bi bi-mic-fill me-1"></i>Talk';
      talkSay(e && e.name === 'NotAllowedError' ? 'microphone permission denied' : (e.message || 'microphone failed'),
              'text-danger');
    });
  }

  function talkOpen(stream, email, id) {
    var Ctx = window.AudioContext || window.webkitAudioContext;
    var ctx = new Ctx();
    /* Ask for 16 kHz directly; browsers that honour it skip the resampler below. */
    if (ctx.sampleRate !== TALK_RATE) { try { ctx.close(); ctx = new Ctx({ sampleRate: TALK_RATE }); } catch (e) { } }

    var src = ctx.createMediaStreamSource(stream);
    var sink = ctx.createGain();
    sink.gain.value = 0;                 /* never echo the supervisor back at themselves */
    src.connect(sink);
    sink.connect(ctx.destination);

    /* ScriptProcessor is deprecated but is the only tap available in every browser
     * without shipping a separate AudioWorklet module. Voice in 100 ms chunks does
     * not care about the extra main-thread hop. */
    var node = ctx.createScriptProcessor(4096, 1, 1);
    node.onaudioprocess = function (e) { talk.push(e.inputBuffer.getChannelData(0)); };
    src.connect(node);
    node.connect(ctx.destination);

    talk.on = true; talk.email = email; talk.id = id; talk.seq = 0; talk.pending = [];
    talk.ctx = ctx; talk.mic = stream; talk.node = node;
    talkBtn.classList.remove('btn-outline-danger');
    talkBtn.classList.add('btn-danger');
    talkBtn.innerHTML = '<i class="bi bi-mic-fill me-1"></i>Stop talking';
    talkSay('you are talking to ' + email, 'text-danger');

    talk.watch = setInterval(talkPollState, 1000);
    talkPollState();
  }

  /* Collect mic samples, emit fixed 100 ms chunks. */
  talk.push = function (input) {
    for (var i = 0; i < input.length; i++) talk.pending.push(input[i]);
    var inRate = talk.ctx.sampleRate;
    var need = Math.ceil(TALK_CHUNK_SAMPLES * inRate / TALK_RATE);
    while (talk.pending.length >= need + 1) {
      var out = new Float32Array(TALK_CHUNK_SAMPLES), step = inRate / TALK_RATE;
      for (var j = 0; j < TALK_CHUNK_SAMPLES; j++) {
        var p = j * step, i0 = Math.floor(p), f = p - i0;
        out[j] = talk.pending[i0] * (1 - f) + talk.pending[i0 + 1] * f;
      }
      talk.pending = talk.pending.slice(need);
      talkSendChunk(out);
    }
  };

  /* Chunks must arrive in order and must not overlap, so every POST is chained. */
  function talkSendChunk(samples) {
    var buf = new ArrayBuffer(samples.length * 2), dv = new DataView(buf);
    for (var i = 0; i < samples.length; i++) {
      var s = Math.max(-1, Math.min(1, samples[i]));
      dv.setInt16(i * 2, s < 0 ? s * 0x8000 : s * 0x7fff, true);   /* little-endian PCM16 */
    }
var b64 = btoa(String.fromCharCode.apply(null, new Uint8Array(buf)));
      var seq = talk.seq++;
      /* A chunk is a slice of a sentence, so one failed POST must not be taken as a
         reason to hang up on the person. Retry the same seq a few times: the seq was
         already allocated, and sending it twice is harmless because the receiver has
         not heard it yet. Only give up once retries are spent. */
      talk.chain = talk.chain.then(function () { return talkUpload(b64, seq, 0); })
        .catch(function () { });
    }

    function talkUpload(b64, seq, attempt) {
      return talkJson(TALK_CHUNK, { email: talk.email, talkId: talk.id, seq: seq, pcm: b64 })
        .then(function (r) {
          if (r && r.ok) return r;
          if (attempt >= 2) { talkFail((r && r.error) || 'audio upload failed'); return null; }
          return new Promise(function (res) { setTimeout(res, 400); }).then(function () {
            return talkUpload(b64, seq, attempt + 1);
          });
        })
        .catch(function () {
          if (attempt >= 2) { talkFail('connection lost'); return null; }
          return new Promise(function (res) { setTimeout(res, 400); }).then(function () {
            return talkUpload(b64, seq, attempt + 1);
          });
        });
    }

  function talkFail(msg) {
    talkSay(msg, 'text-danger');
    talkStop();
  }

  /* Report what the employee actually did with it. */
  function talkPollState() {
    if (!talk.on) return;
    fetch(TALK_STATE + '?email=' + encodeURIComponent(talk.email), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
.then(function (s) {
          if (!s || !s.ok) return;
          if (s.ack === 'declined') {
            talkSay('declined by ' + talk.email, 'text-danger');
            talkStop(true);
            return;
          }
          if (!s.active) { talkStop(); return; }
          /* Lost chunks are rare and cannot be undone, but they must not be hidden:
             a supervisor who can see the gap can slow down or repeat themselves. */
          if (s.lostChunks > 0) {
            talkSay('live - ' + talk.email + ' - ' + s.elapsedSec + 's - audio gap, '
                    + s.lostChunks + ' chunk(s) could not be delivered', 'text-warning');
            return;
          }
          if (s.ack === 'muted') {
            talkSay('muted by ' + talk.email + ' - keep going or stop', 'text-warning');
          } else if (s.agentOnline === false) {
            talkSay('their agent is offline - audio is queuing', 'text-warning');
          } else {
            talkSay('live - ' + talk.email + ' - ' + s.elapsedSec + 's', 'text-danger');
          }
        })
      .catch(function () { });
  }

  function talkStop(silent) {
    var wasOn = talk.on, email = talk.email, id = talk.id;
    talk.on = false;
    if (talk.watch) { clearInterval(talk.watch); talk.watch = null; }
    if (talk.node) { talk.node.onaudioprocess = null; try { talk.node.disconnect(); } catch (e) { } }
    if (talk.mic) stopTracks(talk.mic);
    if (talk.ctx) { try { talk.ctx.close(); } catch (e) { } }
    talk.ctx = null; talk.mic = null; talk.node = null; talk.pending = [];
    talkBtn.classList.remove('btn-danger');
    talkBtn.classList.add('btn-outline-danger');
    talkBtn.innerHTML = '<i class="bi bi-mic-fill me-1"></i>Talk';
    talkBtn.disabled = !selected;
    if (!silent) talkSay('');
    if (wasOn && email && id) {
      talkJson(TALK_STOP, { email: email, talkId: id }).catch(function () { });
    }
  }

  talkBtn.addEventListener('click', function () { talk.on ? talkStop() : talkStart(); });

  refresh();
  tick();
  setInterval(refresh, 2500);
  setInterval(tick, 120);
})();
</script>


    <?php
    layout_close();
}

/* ---------------------------------------------------------------- history ---- */

function page_history(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $employees = access_visible_employees($u);
    $range = $_GET['range'] ?? 'day';
    $selected = access_resolve_selection($u, $_GET['email'] ?? null);
    $end = time();
    switch ($range) {
        case 'week': $start = $end - 7 * 86400; break;
        case 'month': $start = $end - 30 * 86400; break;
        case 'year': $start = $end - 365 * 86400; break;
        default: $start = strtotime(today()); break;
    }
    $records = [];
    if ($selected !== null) {
        $records = track_range($selected, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end));
    } else {
        foreach ($employees as $e) {
            foreach (track_range($e['email'], date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)) as $r) $records[] = $r;
        }
        usort($records, function ($a, $b) {
            $ca = !empty($a['check_in']) ? $a['check_in'] : '0000-00-00 00:00:00';
            $cb = !empty($b['check_in']) ? $b['check_in'] : '0000-00-00 00:00:00';
            return strcmp($ca, $cb);
        });
    }

    layout_open('History / Reports', $u, '/portal/history');
    ?>
<div class="page-head">
  <div>
    <h4>History &amp; Reports</h4>
    <div class="page-sub">Attendance, working time and productivity for the selected range. Times are shown in 12-hour format. Each day is classified against the <?= esc(track_target_hours_text()) ?>-hour daily target.</div>
  </div>
</div>
<div class="card p-3 mb-3">
  <form method="get" action="<?= esc(base_url('/portal/history')) ?>" class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Employee</label>
      <select name="email" class="form-select">
        <option value="">All visible employees</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= esc($e['email']) ?>" <?= $selected === $e['email'] ? 'selected' : '' ?>><?= esc(employee_full_name($e)) ?> (<?= esc($e['email']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Range</label>
      <select name="range" class="form-select">
        <?php foreach (['day' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'year' => 'Last 365 days'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $range === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><button class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button></div>
  </form>
</div>
<div class="card p-3">
  <h5><i class="bi bi-clock-history me-2 text-primary"></i>Records (<?= count($records) ?>)</h5>
  <div class="table-responsive">
  <table class="table table-sm table-hover">
    <thead class="table-light"><tr><th>Employee</th><th>Date</th><th>Check In</th><th>Check Out</th><th>Worked</th><th>Target</th><th>Active</th><th>Idle</th><th>Break</th><th>Paused</th><th>Productivity</th><th>Shots</th><th>Adjust</th></tr></thead>
    <tbody>
    <?php foreach ($records as $r): $empRow = employee_find_by_email($r['email']); ?>
      <tr>
        <td><?= esc($empRow !== null ? employee_full_name($empRow) : $r['email']) ?></td>
        <td><?= esc($r['work_date']) ?></td>
        <td><?= esc(track_check_in_text($r)) ?></td>
        <td><?= esc(track_check_out_text($r)) ?></td>
        <td><?= esc(fmt_dur(track_worked_seconds($r))) ?></td>
        <td><?= track_target_chip(track_worked_seconds($r), $r['email'] ?? null) ?></td>
        <td><?= esc(fmt_dur((int) $r['active_seconds'])) ?></td>
        <td><?= esc(fmt_dur((int) $r['idle_seconds'])) ?></td>
        <td><?= esc(fmt_dur((int) $r['break_seconds'])) ?></td>
        <td><?= esc(fmt_dur((int) ($r['paused_seconds'] ?? 0))) ?></td>
        <td><?= (int) track_productivity_percent($r) ?>%</td>
        <td><?= (int) $r['screenshot_count'] ?></td>
        <td><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#adj-<?= (int) $r['id'] ?>">Adjust</button></td>
      </tr>
      <tr class="collapse" id="adj-<?= (int) $r['id'] ?>"><td colspan="13" class="bg-light">
        <form method="post" action="<?= esc(base_url('/portal/history/adjust')) ?>" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= esc($r['email']) ?>">
          <input type="hidden" name="date" value="<?= esc($r['work_date']) ?>">
          <div class="col-md-2"><label class="form-label small">Active + (min)</label><input type="number" class="form-control form-control-sm" name="activeDelta" value="0"></div>
          <div class="col-md-2"><label class="form-label small">Idle + (min)</label><input type="number" class="form-control form-control-sm" name="idleDelta" value="0"></div>
          <div class="col-md-2"><label class="form-label small">Break + (min)</label><input type="number" class="form-control form-control-sm" name="breakDelta" value="0"></div>
          <div class="col-md-3 form-check mt-4"><input class="form-check-input" type="checkbox" name="lock" id="adjlock-<?= (int) $r['id'] ?>" value="1" checked><label class="form-check-label small" for="adjlock-<?= (int) $r['id'] ?>">Freeze (agent can't override)</label></div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary">Save</button></div>
        </form>
      </td></tr>
    <?php endforeach; ?>
    <?php if (!$records): ?><tr><td colspan="13" class="text-center text-muted py-3">No records in this range.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function page_history_adjust(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    // An HR must not adjust the admin's or another HR's record.
    if (!access_can_view_email($u, $email)) redirect(base_url('/portal/history'));
    $date = page_parse_day($_POST['date'] ?? null, today());
    $lock = !empty($_POST['lock']);
    /* Treat deltas as minutes for consistency with Manual Update. */
    $ad = ((int) ($_POST['activeDelta'] ?? 0)) * 60;
    $id = ((int) ($_POST['idleDelta'] ?? 0)) * 60;
    $bd = ((int) ($_POST['breakDelta'] ?? 0)) * 60;
    track_adjust($email, $date, $ad, $id, $bd, $lock);
    if (function_exists('activity_log')) {
        activity_log($u, 'HISTORY_ADJUST',
            'Adjusted ' . $email . ' ' . $date . ' (a+' . $ad . 's/i+' . $id . 's/b+' . $bd . 's)',
            'web');
    }
    redirect(base_url('/portal/history') . '?email=' . rawurlencode($email));
}

/* ------------------------------------------------------------------ usage ---- */

function page_usage(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $viewer = $u;
    $scope = $_GET['scope'] ?? 'all';
    $toParam = $_GET['to'] ?? null;
    $endDay = page_parse_day($toParam, today());
    $startDay = null; $scopeLabel = '';
    switch ($scope) {
        case 'day': $startDay = $endDay; $scopeLabel = date('d M Y', strtotime($endDay)); break;
        case 'week': $startDay = date('Y-m-d', strtotime($endDay . ' -6 days')); $scopeLabel = 'Last 7 days'; break;
        case 'month': $startDay = date('Y-m-d', strtotime($endDay . ' -29 days')); $scopeLabel = 'Last 30 days'; break;
        default:
            $startDay = page_parse_day($_GET['from'] ?? null, date('Y-m-d', strtotime($endDay . ' -6 days')));
            $scopeLabel = 'Custom range';
    }
    if ($startDay > $endDay) { $t = $startDay; $startDay = $endDay; $endDay = $t; }
    $days = [];
    for ($d = $startDay; $d <= $endDay;) { $days[] = $d; $d = date('Y-m-d', strtotime($d . ' +1 day')); }

    $employees = access_visible_employees($viewer);
    $selected = access_resolve_selection($viewer, $_GET['email'] ?? null);

    $totalWorked = 0; $totalActive = 0; $totalIdle = 0; $totalBreak = 0;
    $totalApp = 0; $totalSite = 0; $totalKeys = 0; $totalClicks = 0; $totalScrolls = 0; $totalShots = 0;
    $maxShots = 0;
    $perEmployee = [];
    foreach ($days as $day) {
        foreach ($employees as $e) {
            if ($selected !== null && strcasecmp($selected, $e['email']) !== 0) continue;
            $r = track_record_for($e['email'], $day);
            $appSec = usage_app_seconds($e['email'], $day);
            $siteSec = usage_site_seconds($e['email'], $day);
            $w = track_worked_seconds($r);
            if ($w === 0 && $appSec === 0 && (int) ($r['screenshot_count'] ?? 0) === 0) continue;
            $a = (int) $r['active_seconds']; $i = (int) $r['idle_seconds']; $b = (int) $r['break_seconds'];
            $sh = (int) $r['screenshot_count']; $k = (int) $r['keystrokes']; $c = (int) $r['mouse_clicks']; $sc = (int) $r['mouse_scrolls'];
            $totalWorked += $w; $totalActive += $a; $totalIdle += $i; $totalBreak += $b;
            $totalApp += $appSec; $totalSite += $siteSec; $totalKeys += $k; $totalClicks += $c; $totalScrolls += $sc; $totalShots += $sh;
            if (!isset($perEmployee[$e['email']])) $perEmployee[$e['email']] = [0, 0, 0, 0, 0, 0, 0, 0];
            $pe = &$perEmployee[$e['email']];
            $pe[0] += $w; $pe[1] += $a; $pe[2] += $i; $pe[3] += $b; $pe[4] += $appSec + $siteSec;
            $pe[5] += $sh; $pe[6] += $k; $pe[7] += $c;
            $maxShots = max($maxShots, $pe[5]);
            unset($pe);
        }
    }

    $perEmployeeRows = [];
    foreach ($perEmployee as $em => $bucket) {
        $empRow = employee_find_by_email($em);
        $perEmployeeRows[] = [
            'email' => $em,
            'name' => $empRow !== null ? employee_full_name($empRow) : $em,
            'department' => $empRow !== null ? ($empRow['department'] ?? '-') : '-',
            'worked' => fmt_dur($bucket[0]), 'workedSec' => $bucket[0],
            'active' => fmt_dur($bucket[1]), 'idle' => fmt_dur($bucket[2]), 'break' => fmt_dur($bucket[3]),
            'tracked' => fmt_dur($bucket[4]), 'screenshots' => $bucket[5],
            'keystrokes' => $bucket[6], 'clicks' => $bucket[7],
            'productivity' => percent($bucket[1], $bucket[1] + $bucket[2]),
        ];
    }
    usort($perEmployeeRows, fn($a, $b) => $b['workedSec'] <=> $a['workedSec']);
    $maxShots = max($maxShots, 1);

    $appTotals = []; $siteTotals = [];
    $perDayRows = [];
    $distinctApps = 0; $distinctSites = 0;
    foreach ($days as $day) {
        $dayWorked = 0; $dayActive = 0; $dayIdle = 0; $dApps = 0; $dSites = 0;
        foreach ($employees as $e) {
            if ($selected !== null && strcasecmp($selected, $e['email']) !== 0) continue;
            $r = track_record_for($e['email'], $day);
            $dayWorked += track_worked_seconds($r);
            $dayActive += (int) $r['active_seconds'];
            $dayIdle += (int) $r['idle_seconds'];
            foreach (usage_top_apps($e['email'], $day, 200) as $k => $v) $appTotals[$k] = ($appTotals[$k] ?? 0) + $v;
            foreach (usage_top_sites($e['email'], $day, 200) as $k => $v) $siteTotals[$k] = ($siteTotals[$k] ?? 0) + $v;
            $dApps += usage_app_count($e['email'], $day);
            $dSites += usage_site_count($e['email'], $day);
        }
        if ($selected !== null) { $distinctApps = $dApps; $distinctSites = $dSites; }
        else { $distinctApps += $dApps; $distinctSites += $dSites; }
        if ($dayWorked === 0) continue;
        $perDayRows[] = [
            'date' => $day, 'worked' => fmt_dur($dayWorked), 'workedSec' => $dayWorked, 'active' => fmt_dur($dayActive),
            'idle' => fmt_dur($dayIdle), 'productivity' => percent($dayActive, $dayActive + $dayIdle),
        ];
    }
    arsort($appTotals); arsort($siteTotals);
    $appTotals = array_slice($appTotals, 0, 15, true);
    $siteTotals = array_slice($siteTotals, 0, 15, true);

    $fromTs = $startDay . ' 00:00:00';
    $toTs = date('Y-m-d 00:00:00', strtotime($endDay . ' +1 day'));
    $shots = shots_between($fromTs, $toTs, $selected);
    $shots = array_slice($shots, 0, 24);

    $storageUsed = shots_storage_bytes();
    $shotBase = base_url('/api/monitor/shot');
    $csvUrl = base_url('/admin/usage.csv') . '?email=' . rawurlencode($selected ?? '')
        . '&from=' . rawurlencode($startDay) . '&to=' . rawurlencode($endDay);

    layout_open('Activity Report', $u, '/admin/usage');
    ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Activity Report</h4>
  <div class="text-muted"><?= esc($scopeLabel) ?> &middot; <?= esc(date('d M Y', strtotime($startDay))) ?> &rarr; <?= esc(date('d M Y', strtotime($endDay))) ?></div>
</div>

<div class="card p-3 mb-3">
  <form method="get" action="<?= esc(base_url('/admin/usage')) ?>" class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small text-muted">Employee</label>
      <select name="email" class="form-select">
        <option value="">All visible employees</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= esc($e['email']) ?>" <?= $selected === $e['email'] ? 'selected' : '' ?>><?= esc(employee_full_name($e)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label small text-muted">Range</label>
      <select name="scope" class="form-select">
        <?php foreach (['all' => 'Custom', 'day' => 'Day', 'week' => 'Last 7 days', 'month' => 'Last 30 days'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $scope === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label small text-muted">From</label><input type="date" class="form-control" name="from" value="<?= esc($startDay) ?>"></div>
    <div class="col-md-2"><label class="form-label small text-muted">To</label><input type="date" class="form-control" name="to" value="<?= esc($endDay) ?>"></div>
    <div class="col-md-3 d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
      <a class="btn btn-outline-success" href="<?= esc($csvUrl) ?>"><i class="bi bi-file-earmark-arrow-down me-1"></i>CSV</a>
      <?php if ($selected !== null): ?>
      <a class="btn btn-outline-info" href="<?= esc(base_url('/admin/usage-targets.csv') . '?email=' . rawurlencode($selected) . '&date=' . rawurlencode($endDay)) ?>"><i class="bi bi-table me-1"></i>Targets</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= esc(fmt_dur($totalWorked)) ?></div><div class="text-soft small">Worked</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold text-success"><?= esc(fmt_dur($totalActive)) ?></div><div class="text-soft small">Active</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold text-warning"><?= esc(fmt_dur($totalIdle)) ?></div><div class="text-soft small">Idle</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold text-info"><?= esc(fmt_dur($totalBreak)) ?></div><div class="text-soft small">Break</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= esc(fmt_dur($totalApp)) ?></div><div class="text-soft small">App Time</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= esc(fmt_dur($totalSite)) ?></div><div class="text-soft small">Site Time</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= esc(fmt_dur($totalApp + $totalSite)) ?></div><div class="text-soft small">Total Tracked</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= percent($totalActive, $totalActive + $totalIdle) ?>%</div><div class="text-soft small">Productivity</div></div></div>
  <div class="col-md-2 col-4"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= (int) $totalKeys ?></div><div class="text-soft small">Keystrokes</div></div></div>
  <div class="col-md-2 col-4"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= (int) $totalClicks ?></div><div class="text-soft small">Clicks</div></div></div>
  <div class="col-md-2 col-4"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= (int) $totalShots ?></div><div class="text-soft small">Screenshots</div></div></div>
  <div class="col-md-2 col-6"><div class="card stat-card p-3"><div class="fs-6 fw-bold"><?= esc(fmt_bytes($storageUsed)) ?></div><div class="text-soft small">Storage</div></div></div>
  <div class="col-md-2 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= (int) $distinctApps ?> / <?= (int) $distinctSites ?></div><div class="text-soft small">Apps / Sites</div></div></div>
</div>

<div class="card p-3 mb-3">
  <h5>Per Employee</h5>
  <div class="table-responsive">
  <table class="table table-sm table-hover">
    <thead class="table-light"><tr><th>Name</th><th>Department</th><th>Worked</th><th>Target</th><th>Active</th><th>Idle</th><th>Break</th><th>Tracked</th><th>Shots</th><th>Keys</th><th>Clicks</th><th>Productivity</th></tr></thead>
    <tbody>
    <?php foreach ($perEmployeeRows as $row): ?>
      <tr>
        <td><a class="text-decoration-none" href="<?= esc(base_url('/time') . '?email=' . rawurlencode($row['email'])) ?>"><?= esc($row['name']) ?></a></td>
        <td><?= esc($row['department']) ?></td>
        <td><?= esc($row['worked']) ?></td>
        <td><?= track_target_chip((int) $row['workedSec'], $row['email'] ?? null) ?></td>
        <td><?= esc($row['active']) ?></td>
        <td><?= esc($row['idle']) ?></td>
        <td><?= esc($row['break']) ?></td>
        <td><?= esc($row['tracked']) ?></td>
        <td><?= (int) $row['screenshots'] ?></td>
        <td><?= (int) $row['keystrokes'] ?></td>
        <td><?= (int) $row['clicks'] ?></td>
        <td><?= (int) $row['productivity'] ?>%</td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$perEmployeeRows): ?><tr><td colspan="12" class="text-center text-muted py-3">No activity in this range.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card p-3">
      <h5>Top Apps</h5>
      <?php $maxApp = $appTotals ? max($appTotals) : 1; foreach ($appTotals as $k => $v): ?>
      <div class="mb-2"><div class="d-flex justify-content-between small"><span><?= esc($k) ?></span><span class="text-muted"><?= esc(time_text((int) $v)) ?></span></div>
      <div class="progress" style="height:6px"><div class="progress-bar bg-primary" style="width:<?= (int) round($v * 100 / $maxApp) ?>%"></div></div></div>
      <?php endforeach; ?>
      <?php if (!$appTotals): ?><div class="text-muted small">No app usage in range.</div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3">
      <h5>Top Sites</h5>
      <?php $maxSite = $siteTotals ? max($siteTotals) : 1; foreach ($siteTotals as $k => $v): ?>
      <div class="mb-2"><div class="d-flex justify-content-between small"><span><?= esc($k) ?></span><span class="text-muted"><?= esc(time_text((int) $v)) ?></span></div>
      <div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:<?= (int) round($v * 100 / $maxSite) ?>%"></div></div></div>
      <?php endforeach; ?>
      <?php if (!$siteTotals): ?><div class="text-muted small">No website usage in range.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h5>Per Day</h5>
  <div class="table-responsive">
  <table class="table table-sm">
    <thead class="table-light"><tr><th>Date</th><th>Worked</th><th>Target</th><th>Active</th><th>Idle</th><th>Productivity</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($perDayRows) as $row): ?>
      <tr><td><?= esc($row['date']) ?></td><td><?= esc($row['worked']) ?></td><td><?= track_target_chip((int) $row['workedSec']) ?></td><td><?= esc($row['active']) ?></td><td><?= esc($row['idle']) ?></td><td><?= (int) $row['productivity'] ?>%</td></tr>
    <?php endforeach; ?>
    <?php if (!$perDayRows): ?><tr><td colspan="6" class="text-center text-muted py-3">No working days in this range.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0">Recent Screenshots</h5>
    <?php if ($selected !== null && count($shots) > 0): ?>
    <form method="post" action="<?= esc(base_url('/admin/usage/delete-shots')) ?>"
          onsubmit="return confirm('Delete all screenshots shown for <?= esc(addslashes($selected)) ?>? This cannot be undone.')">
      <?= csrf_field() ?>
      <input type="hidden" name="email" value="<?= esc($selected) ?>">
      <input type="hidden" name="scope" value="<?= esc((string) ($_GET['scope'] ?? '')) ?>">
      <input type="hidden" name="from" value="<?= esc($startDay) ?>">
      <input type="hidden" name="to" value="<?= esc($endDay) ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete all shown</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="row g-2">
    <?php foreach ($shots as $s): ?>
    <div class="col-4 col-md-3 col-lg-2">
      <div class="position-relative">
        <a href="<?= esc($shotBase . '/' . (int) $s['id']) ?>" target="_blank">
          <img src="<?= esc($shotBase . '/' . (int) $s['id']) ?>" class="img-fluid rounded w-100" style="height:90px;object-fit:cover" loading="lazy" alt="shot <?= (int) $s['id'] ?>">
        </a>
        <form method="post" action="<?= esc(base_url('/admin/usage/delete-shot')) ?>" class="position-absolute top-0 end-0 m-1"
              onsubmit="return confirm('Delete this screenshot?')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <input type="hidden" name="scope" value="<?= esc((string) ($_GET['scope'] ?? '')) ?>">
          <input type="hidden" name="from" value="<?= esc($startDay) ?>">
          <input type="hidden" name="to" value="<?= esc($endDay) ?>">
          <?php if ($selected !== null): ?><input type="hidden" name="email" value="<?= esc($selected) ?>"><?php endif; ?>
          <button class="btn btn-sm btn-dark border border-light-subtle p-0 px-1" style="opacity:.85" title="Delete screenshot" aria-label="Delete screenshot"><i class="bi bi-x-lg"></i></button>
        </form>
      </div>
      <div class="small text-muted mt-1 text-truncate"><?= esc(dt_fmt($s['captured_at'] ?? null, 'd M, h:i a')) ?> &middot; <?= esc($s['trigger'] ?? '') ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (!$shots): ?><div class="col-12 text-muted small py-2">No screenshots captured in this range.</div><?php endif; ?>
  </div>
</div>
    <?php
    layout_close();
}

/**
 * Removes one capture. The row, the JPEG on disk and the day's counter all go
 * together - shots_delete() owns the first two, the recount fixes the report.
 */
function page_usage_delete_shot(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $shot = $id > 0 ? shot_entity($id) : null;

    if ($shot === null) {
        flash('warning', 'That screenshot has already been deleted.');
    } elseif (!access_can_view_email($u, (string) $shot['email'])) {
        flash('danger', 'That screenshot belongs to someone outside your permitted scope.');
    } else {
        $owner = (string) $shot['email'];
        $day = substr((string) $shot['captured_at'], 0, 10);
        shots_delete($shot);
        track_recount_screenshots($owner, $day);
        activity_log($u, 'DELETE_SHOT', 'Deleted a screenshot of ' . $owner . ' from ' . $day, 'web');
        flash('success', 'Screenshot deleted.');
    }
    redirect(page_query_back($_POST));
}

/**
 * Clears a person's captures for one day, or the whole visible range. Confined
 * by access_can_view_email() so HR can only ever wipe EMPLOYEE-role people.
 */
function page_usage_delete_shots(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $day = trim((string) ($_POST['day'] ?? ''));

    if ($email === '') {
        flash('warning', 'Pick an employee first, then delete their screenshots.');
        redirect(page_query_back($_POST));
    }
    if (!access_can_view_email($u, $email)) {
        flash('danger', 'That employee is outside your permitted scope.');
        redirect(page_query_back($_POST));
    }

    $sql = 'SELECT * FROM screenshots WHERE email=? AND live=0';
    $args = [$email];
    if ($day !== '') { $sql .= ' AND DATE(captured_at)=?'; $args[] = $day; }
    $targets = rows($sql . ' ORDER BY captured_at DESC, id DESC', $args);

    $removed = 0;
    $days = [];
    foreach ($targets as $s) {
        shots_delete($s);
        $days[substr((string) $s['captured_at'], 0, 10)] = true;
        $removed++;
    }
    foreach (array_keys($days) as $d) track_recount_screenshots($email, (string) $d);

    activity_log($u, 'PURGE_SHOTS', 'Deleted ' . $removed . ' screenshot(s) of ' . $email
        . ($day !== '' ? ' for ' . $day : ''), 'web');
    flash('success', $removed === 0
        ? 'There were no screenshots to delete for ' . $email . '.'
        : 'Deleted ' . $removed . ' screenshot(s) of ' . $email . ($day !== '' ? ' from ' . $day : '') . '.');
    redirect(page_query_back($_POST));
}

function page_usage_csv(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $selected = access_resolve_selection($u, $_GET['email'] ?? null);
    $endDay = page_parse_day($_GET['to'] ?? null, today());
    $startDay = page_parse_day($_GET['from'] ?? null, date('Y-m-d', strtotime($endDay . ' -6 days')));
    if ($startDay > $endDay) { $t = $startDay; $startDay = $endDay; $endDay = $t; }

    $rows = [];
    for ($d = $startDay; $d <= $endDay;) {
        foreach (access_visible_employees($u) as $e) {
            if ($selected !== null && strcasecmp($selected, $e['email']) !== 0) continue;
            $r = track_record_for($e['email'], $d);
            $appSec = usage_app_seconds($e['email'], $d);
            $siteSec = usage_site_seconds($e['email'], $d);
            if (track_worked_seconds($r) === 0 && $appSec === 0 && $siteSec === 0) continue;
            $rows[] = [
                $d, $e['first_name'] . ' ' . $e['last_name'], $e['email'], $e['department'] ?? '',
                empty($r['check_in']) ? '' : hhmm_a($r['check_in']),
                empty($r['check_out']) ? '' : hhmm_a($r['check_out']),
                fmt_dur(track_worked_seconds($r)),
                track_target_label(track_target_status(track_worked_seconds($r), $e['email'] ?? null)),
                fmt_dur((int) $r['active_seconds']),
                fmt_dur((int) $r['idle_seconds']), fmt_dur((int) $r['break_seconds']),
                usage_app_count($e['email'], $d), fmt_dur($appSec), usage_app_count($e['email'], $d),
                usage_site_count($e['email'], $d), fmt_dur($siteSec), usage_site_count($e['email'], $d),
                (int) $r['screenshot_count'], (int) $r['keystrokes'], (int) $r['mouse_clicks'],
                track_productivity_percent($r),
            ];
        }
        $d = date('Y-m-d', strtotime($d . ' +1 day'));
    }
    $header = ["Date", "Employee", "Email", "Department", "Clock In", "Clock Out", "Worked", "Target", "Active", "Idle",
               "Break", "Apps Used", "App Time", "Apps", "Sites Used", "Site Time", "Sites", "Screenshots",
               "Keystrokes", "Mouse Clicks", "Productivity %"];
    $csv = "\xEF\xBB\xBF" . csv_build($header, $rows);
    $name = 'workfolio-report-' . $startDay . '-to-' . $endDay . '.csv';
    csv_send($csv, $name);
}

function page_usage_targets_csv(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $email = trim((string) ($_GET['email'] ?? ''));
    if ($email === '' || !access_can_view_email($u, $email)) {
        http_response_code(403);
        exit;
    }
    $day = page_parse_day($_GET['date'] ?? null, today());
    $rows = [];
    foreach (usage_for($email, $day) as $urow) {
        $rows[] = [
            $urow['work_date'], $email, $urow['target_type'], $urow['display_name'],
            $urow['host'] !== null ? $urow['host'] : $urow['process_name'],
            $urow['category'], (int) $urow['seconds'], usage_time_text((int) $urow['seconds']),
            (int) $urow['sample_count'], $urow['window_title'],
        ];
    }
    $header = ["Date", "Employee", "Type", "Name", "Host / Process", "Category", "Seconds", "Time", "Samples", "Window Title"];
    $csv = "\xEF\xBB\xBF" . csv_build($header, $rows);
    $name = 'workfolio-usage-' . $email . '-' . $day . '.csv';
    csv_send($csv, $name);
}

function csv_build(array $header, array $rows): string {
    $out = implode(',', array_map('csv_cell', $header)) . "\n";
    foreach ($rows as $r) {
        $line = [];
        foreach ($r as $cell) $line[] = csv_cell((string) ($cell ?? ''));
        $out .= implode(',', $line) . "\n";
    }
    return $out;
}

function csv_cell(string $v): string {
    if (strpos($v, ',') !== false || strpos($v, '"') !== false || strpos($v, "\n") !== false) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

function csv_send(string $csv, string $name): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

/* ------------------------------------------------------------- agent-conf ---- */

function page_agent_config(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $employees = access_visible_employees($u);
    $selected = access_resolve_selection($u, $_GET['email'] ?? null);
    $config = $selected !== null ? config_for($selected) : null;
    $allConfigs = all_configs();
    $denied = !empty($_GET['denied']);
    $purged = $_GET['purged'] ?? null;

    layout_open('Agent Settings', $u, '/admin/agent-config');
    ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-sliders me-2 text-primary"></i>Agent Settings</h4>
  <form method="post" action="<?= esc(base_url('/admin/agent-config/purge')) ?>" class="d-flex gap-2 align-items-center">
    <?= csrf_field() ?>
    <label class="small text-muted">Keep</label>
    <input type="number" name="keepDays" class="form-control form-control-sm" style="width:80px" value="30" min="1">
    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1"></i>Purge screenshots</button>
  </form>
</div>
<?php if ($denied): ?><div class="alert alert-warning py-2">That employee is outside your permitted scope.</div><?php endif; ?>
<?php if ($purged !== null): ?><div class="alert alert-success py-2">Purged <?= (int) $purged ?> screenshot(s) older than the retention window.</div><?php endif; ?>

<div class="card p-3 mb-3">
  <form method="get" action="<?= esc(base_url('/admin/agent-config')) ?>" class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small text-muted">Employee</label>
      <select name="email" class="form-select" onchange="this.form.submit()">
        <option value="">Select employee...</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= esc($e['email']) ?>" <?= $selected === $e['email'] ? 'selected' : '' ?>><?= esc(employee_full_name($e)) ?> (<?= esc($e['email']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
  </form>
</div>

<?php if ($config !== null): ?>
<form method="post" action="<?= esc(base_url('/admin/agent-config/save')) ?>" class="row g-3">
  <?= csrf_field() ?>
  <input type="hidden" name="email" value="<?= esc($config['email']) ?>">
  <div class="col-md-4"><label class="form-label">Screenshot interval (seconds)</label>
    <input type="number" class="form-control" name="screenshotIntervalSeconds" min="10" max="7200" value="<?= (int) $config['screenshot_interval_seconds'] ?>"></div>
  <div class="col-md-4"><label class="form-label">Live frame interval (ms, 0 = off)</label>
    <input type="number" class="form-control" name="liveStreamIntervalMs" min="0" max="30000" step="10" value="<?= (int) live_interval_ms($config) ?>"></div>
  <div class="col-md-4"><label class="form-label">Idle threshold (seconds)</label>
    <input type="number" class="form-control" name="idleThresholdSeconds" min="10" max="3600" value="<?= (int) $config['idle_threshold_seconds'] ?>"></div>
  <div class="col-md-4"><label class="form-label">Image quality (1-100)</label>
    <input type="number" class="form-control" name="imageQuality" min="10" max="100" value="<?= (int) $config['image_quality'] ?>"></div>
  <div class="col-md-4"><label class="form-label">Image max width (px)</label>
    <input type="number" class="form-control" name="imageMaxWidth" min="320" max="3840" value="<?= (int) $config['image_max_width'] ?>"></div>
  <div class="col-md-4"><label class="form-label">Sample interval (seconds)</label>
    <input type="number" class="form-control" name="sampleIntervalSeconds" min="2" max="300" value="<?= (int) $config['sample_interval_seconds'] ?>"></div>
  <div class="col-md-12">
    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="captureScreenshots" value="1" id="c1" <?= (bool) $config['capture_screenshots'] ? 'checked' : '' ?>><label class="form-check-label" for="c1">Capture screenshots</label></div>
    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="trackMouseKeyboard" value="1" id="c2" <?= (bool) $config['track_mouse_keyboard'] ? 'checked' : '' ?>><label class="form-check-label" for="c2">Track mouse &amp; keyboard</label></div>
    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="trackAppUsage" value="1" id="c3" <?= (bool) $config['track_app_usage'] ? 'checked' : '' ?>><label class="form-check-label" for="c3">Track app usage</label></div>
    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="trackWebsites" value="1" id="c4" <?= (bool) $config['track_websites'] ? 'checked' : '' ?>><label class="form-check-label" for="c4">Track websites</label></div>
  </div>
  <div class="col-md-6"><label class="form-label">Excluded processes (comma separated)</label>
    <textarea class="form-control" name="excludedProcesses" rows="3"><?= esc($config['excluded_processes'] ?? '') ?></textarea></div>
  <div class="col-md-6"><label class="form-label">Blocked sites (comma separated, e.g. facebook.com)</label>
    <textarea class="form-control" name="blockedSites" rows="3"><?= esc($config['blocked_sites'] ?? '') ?></textarea></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save for <?= esc(employee_full_name(employee_find_by_email($config['email']))) ?></button></div>
</form>
<?php else: ?>
<div class="card p-4 text-muted">Select an employee to edit their monitoring policy.</div>
<?php endif; ?>

<div class="card p-3 mt-4">
  <h5>All policies</h5>
  <div class="table-responsive">
  <table class="table table-sm">
    <thead class="table-light"><tr><th>Employee</th><th>Shots</th><th>Live</th><th>Idle</th><th>Sample</th><th>Shots on</th><th>Apps</th><th>Sites</th></tr></thead>
    <tbody>
    <?php foreach ($allConfigs as $c): $empC = employee_find_by_email($c['email']); if ($empC === null) continue; ?>
      <tr>
        <td><a href="<?= esc(base_url('/admin/agent-config') . '?email=' . rawurlencode($c['email'])) ?>"><?= esc(employee_full_name($empC)) ?></a></td>
        <td><?= (int) $c['screenshot_interval_seconds'] ?>s</td>
        <td><?= (int) live_interval_ms($c) ?>ms</td>
        <td><?= (int) $c['idle_threshold_seconds'] ?>s</td>
        <td><?= (int) $c['sample_interval_seconds'] ?>s</td>
        <td><?= (bool) $c['capture_screenshots'] ? 'Yes' : 'No' ?></td>
        <td><?= (bool) $c['track_app_usage'] ? 'Yes' : 'No' ?></td>
        <td><?= (bool) $c['track_websites'] ? 'Yes' : 'No' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function page_agent_config_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    // Without this an HR could post the admin's email and switch off his
    // screenshots, or widen excludedProcesses enough to hide a whole day.
    if ($email === '' || !access_can_view_email($u, $email)) {
        redirect(base_url('/admin/agent-config?denied=1'));
    }
    $c = config_for($email);
    $c['screenshot_interval_seconds'] = clamp_cfg((int) ($_POST['screenshotIntervalSeconds'] ?? 0), 10, 7200, 300);
    /* Milliseconds is what the form posts now; an old bookmarked form posting
       whole seconds is still understood rather than read as 0 = off. */
    if (isset($_POST['liveStreamIntervalMs'])) {
        $liveMs = (int) $_POST['liveStreamIntervalMs'];
    } elseif (isset($_POST['liveStreamIntervalSeconds'])) {
        $liveMs = (int) $_POST['liveStreamIntervalSeconds'] * 1000;
    } else {
        $liveMs = 0;
    }
    $c['live_stream_interval_ms'] = max(0, min(30000, $liveMs));
    $c['live_stream_interval_seconds'] = live_interval_seconds((int) $c['live_stream_interval_ms']);
    $c['idle_threshold_seconds'] = clamp_cfg((int) ($_POST['idleThresholdSeconds'] ?? 0), 10, 3600, 180);
    $c['image_quality'] = clamp_cfg((int) ($_POST['imageQuality'] ?? 0), 10, 100, 55);
    $c['image_max_width'] = clamp_cfg((int) ($_POST['imageMaxWidth'] ?? 0), 320, 3840, 1280);
    $c['sample_interval_seconds'] = clamp_cfg((int) ($_POST['sampleIntervalSeconds'] ?? 0), 2, 300, 10);
    $c['capture_screenshots'] = isset($_POST['captureScreenshots']);
    $c['track_mouse_keyboard'] = isset($_POST['trackMouseKeyboard']);
    $c['track_app_usage'] = isset($_POST['trackAppUsage']);
    $c['track_websites'] = isset($_POST['trackWebsites']);
    if (isset($_POST['excludedProcesses'])) $c['excluded_processes'] = trim((string) $_POST['excludedProcesses']);
    if (isset($_POST['blockedSites'])) $c['blocked_sites'] = trim((string) $_POST['blockedSites']);
    save_config($c);
    activity_log($u, 'CONFIG_UPDATE', 'Updated agent policy for ' . $email, 'web');
    redirect(base_url('/admin/agent-config?email=' . rawurlencode($email)));
}

function page_agent_config_purge(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $keepDays = max(1, (int) ($_POST['keepDays'] ?? 30));
    $removed = shots_purge_older_than($keepDays);
    activity_log($u, 'PURGE_SHOTS', 'Purged ' . $removed . ' screenshot(s) older than ' . $keepDays . ' days', 'web');
    redirect(base_url('/admin/agent-config?purged=' . $removed));
}

/* ------------------------------------------------------------------ users ---- */

function page_users(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    // Same rule as the employee roster: the owner is not a managed login for
    // anyone else, so it must not surface in the credentials list either.
    $employees = array_values(array_filter(employee_all(), function ($e) use ($u) {
        return !is_super_admin($e) || is_super_admin($u);
    }));
    $created = !empty($_GET['created']);
    layout_open('Credentials', $u, '/portal/users');
    ?>
<?php if ($created): ?>
  <div class="alert alert-success py-2" id="wfCreated"><i class="bi bi-check-circle me-1"></i>User created. They sign in at <code><?= esc(base_url('/login')) ?></code> with their email and the password you set.</div>
<?php endif; ?>
<div class="page-head">
  <div>
    <h4>Credentials</h4>
    <div class="page-sub">Create employee logins for the desktop agent and reset passwords.</div>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-3 mb-3 card-hover">
      <h5><i class="bi bi-person-plus me-2" style="color:var(--wf-primary-2)"></i>Create user / employee</h5>
      <p class="text-soft small mb-2">The new user signs in at <code><?= esc(base_url('/login')) ?></code> with this email and password.</p>
      <form method="post" action="<?= esc(base_url('/portal/users/save')) ?>" id="wfCreateForm">
        <?= csrf_field() ?>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">First name</label><input class="form-control" name="firstName"></div>
          <div class="col-6"><label class="form-label small">Last name</label><input class="form-control" name="lastName"></div>
          <div class="col-12"><label class="form-label small">Email</label><input type="email" class="form-control" name="email" required></div>
          <div class="col-6"><label class="form-label small">Password</label>
            <div class="input-group">
              <input type="text" class="form-control" name="password" id="wfNewPass" minlength="6" required>
              <button class="btn btn-outline-secondary" type="button" id="wfGenPass" title="Generate password"><i class="bi bi-shuffle"></i></button>
            </div>
          </div>
          <div class="col-6"><label class="form-label small">Role</label>
            <select class="form-select" name="role"><option value="EMPLOYEE">EMPLOYEE</option><option value="HR">HR</option><option value="ADMIN">ADMIN</option><?php if (is_super_admin($u)): ?><option value="SUPER_ADMIN">SUPER_ADMIN</option><?php endif; ?></select></div>
          <div class="col-6"><label class="form-label small">Department</label><input class="form-control" name="department"></div>
          <div class="col-6"><label class="form-label small">Designation</label><input class="form-control" name="designation"></div>
          <div class="col-12"><button class="btn btn-primary mt-2">Create user</button></div>
        </div>
      </form>
    </div>
    <div class="card p-3">
      <h5>Reset password</h5>
      <form method="post" action="<?= esc(base_url('/portal/users/reset')) ?>">
        <?= csrf_field() ?>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">User</label>
            <select class="form-select" name="id">
              <?php foreach ($employees as $e): ?><option value="<?= (int) $e['id'] ?>"><?= esc(employee_full_name($e)) ?> (<?= esc($e['email']) ?>)</option><?php endforeach; ?>
            </select></div>
          <div class="col-6"><label class="form-label small">New password</label><input type="text" class="form-control" name="password" required></div>
          <div class="col-12"><button class="btn btn-outline-warning mt-2">Reset password</button></div>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">All users (<?= count($employees) ?>)</h5>
        <span class="text-muted small">Delete removes the login, tracking records and screenshots.</span>
      </div>
      <div class="table-responsive">
      <table class="table table-sm">
        <thead class="table-light"><tr><th>Name</th><th>Email</th><th>Role</th><th>Dept</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
          <?php $isSelf = (int) $e['id'] === (int) $u['id']; ?>
          <tr>
            <td><?= esc(employee_full_name($e)) ?><?= $isSelf ? ' <span class="badge bg-secondary">you</span>' : '' ?></td>
            <td><?= esc($e['email']) ?></td>
            <td><span class="badge bg-light text-dark"><?= esc($e['role']) ?></span></td>
            <td><?= esc($e['department'] ?? '-') ?></td>
            <td class="text-end">
              <?php if (!$isSelf): ?>
                <form method="post" action="<?= esc(base_url('/admin/employees/delete/' . (int) $e['id'])) ?>" class="d-inline"
                      onsubmit="return confirm('Delete <?= esc(addslashes(employee_full_name($e))) ?> (<?= esc($e['email']) ?>)? Their login, shift history and screenshots will be removed. This cannot be undone.')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="back" value="/portal/users">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">current account</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
    <?php
    layout_close();
}

function page_users_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if ($email === '') {
        flash('danger', 'Email address is required.');
        redirect(base_url('/portal/users'));
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', '"' . $email . '" is not a valid email address, so no user was created.');
        redirect(base_url('/portal/users'));
    }
    if (strlen(trim($password)) < 6) {
        flash('danger', 'A password must be at least 6 characters. No user was created.');
        redirect(base_url('/portal/users'));
    }
    $dupe = employee_find_by_email($email);
    if ($dupe !== null) {
        flash('danger', 'No user was created: ' . $email . ' already belongs to ' . employee_full_name($dupe)
            . '. Use Reset password below to give them a working password.');
        redirect(base_url('/portal/users'));
    }
    $role = in_array($_POST['role'] ?? '', access_assignable_roles($u), true) ? (string) $_POST['role'] : 'EMPLOYEE';
    $firstName = trim((string) ($_POST['firstName'] ?? ''));
    $lastName = trim((string) ($_POST['lastName'] ?? ''));
    q('INSERT INTO employees (first_name,last_name,email,`role`,department,designation,password) VALUES (?,?,?,?,?,?,?)', [
        $firstName, $lastName, $email, $role,
        trim((string) ($_POST['department'] ?? '')), trim((string) ($_POST['designation'] ?? '')),
        password_hash($password, PASSWORD_BCRYPT),
    ]);
    config_for($email);
    activity_log($u, 'CREATE_USER', 'Created user ' . $email, 'web');
    $sent = mail_credentials([
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $email,
        'role'       => $role,
    ], $password, 'created');
    if ($sent === WF_MAIL_SENT) {
        activity_log($u, 'SEND_CREDENTIALS', 'Emailed sign-in details to ' . $email, 'web');
    }
    flash('success', $email . ' created as ' . $role . '. They sign in at ' . base_url('/login')
        . ' with that email and the password you just set.' . mail_flash_suffix($sent, $email));
    redirect(base_url('/portal/users'));
}

function page_users_reset(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $password = (string) ($_POST['password'] ?? '');
    $emp = employee_find($id);
    if ($emp === null) {
        flash('warning', 'That user no longer exists.');
        redirect(base_url('/portal/users'));
    }
    if (strlen(trim($password)) < 6) {
        flash('danger', 'A password must be at least 6 characters. Nothing was changed for ' . $emp['email'] . '.');
        redirect(base_url('/portal/users'));
    }
    q('UPDATE employees SET password=? WHERE id=?', [password_hash($password, PASSWORD_BCRYPT), $id]);
    activity_log($u, 'RESET_PASSWORD', 'Reset password for ' . $emp['email'], 'web');
    $sent = mail_credentials($emp, $password, 'reset');
    if ($sent === WF_MAIL_SENT) {
        activity_log($u, 'SEND_CREDENTIALS', 'Emailed the new password to ' . $emp['email'], 'web');
    }
    flash('success', 'Password reset for ' . $emp['email'] . '. They sign in with it now.'
        . mail_flash_suffix($sent, (string) $emp['email']));
    redirect(base_url('/portal/users'));
}

/* -------------------------------------------------------------- employees ---- */

function page_employees(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    // The owner is not listed to anyone else: they are not a tracked user, so
    // showing them in the roster would imply a record that does not exist.
    $employees = array_values(array_filter(employee_all(), function ($e) use ($u) {
        return !is_super_admin($e) || is_super_admin($u);
    }));
    layout_open('Employees', $u, '/employees');
    ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Employees</h4>
  <a class="btn btn-primary" href="<?= esc(base_url('/employees/new')) ?>"><i class="bi bi-plus-lg me-1"></i>Add Employee</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
  <table class="table table-sm table-hover">
    <thead class="table-light"><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Designation</th><th>Salary</th><th>Join Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($employees as $e): ?>
      <tr>
        <td><?= esc(employee_full_name($e)) ?></td>
        <td><?= esc($e['email']) ?></td>
        <td><span class="badge <?= $e['role'] === 'SUPER_ADMIN' ? 'bg-dark' : ($e['role'] === 'ADMIN' ? 'bg-danger' : ($e['role'] === 'HR' ? 'bg-warning text-dark' : 'bg-secondary')) ?>"><?= esc($e['role']) ?></span></td>
        <td><?= esc($e['department'] ?? '-') ?></td>
        <td><?= esc($e['designation'] ?? '-') ?></td>
        <td><?= $e['salary'] !== null ? number_format((float) $e['salary'], 0) : '-' ?></td>
        <td><?= esc($e['join_date'] ?? '-') ?></td>
        <td>
          <?php
            /* The owner and an ADMIN may touch anyone. HR only ever sees action buttons on
               EMPLOYEE-role rows - the same scope page_employee_update() and
               page_employee_delete() enforce server side. */
            $canManage = is_super_admin($u) || $u['role'] === 'ADMIN' || ($e['role'] ?? '') === 'EMPLOYEE';
          ?>
          <?php if ($canManage): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= esc(base_url('/admin/employees/edit/' . (int) $e['id'])) ?>">Edit</a>
          <?php endif; ?>
          <?php if ($canManage && (int) $e['id'] !== (int) $u['id']): ?>
            <form method="post" action="<?= esc(base_url('/admin/employees/password/' . (int) $e['id'])) ?>" class="d-inline"
                  onsubmit="var p=prompt('New password for <?= esc(addslashes($e['email'])) ?> (at least 6 characters). Leave the employee their new password.');
                            if(p===null){return false} this.querySelector('input[name=password]').value=p; return true;">
              <?= csrf_field() ?>
              <input type="hidden" name="password" value="">
              <button class="btn btn-sm btn-outline-warning" title="Set a password this employee can sign in with">Set password</button>
            </form>
          <?php endif; ?>
          <?php if ($canManage && (int) $e['id'] !== (int) $u['id']): ?>
            <form method="post" action="<?= esc(base_url('/admin/employees/delete/' . (int) $e['id'])) ?>" class="d-inline"
                  onsubmit="return confirm('Delete <?= esc(addslashes(employee_full_name($e))) ?>? Their shift history, screenshots and login will be removed. This cannot be undone.')">
              <?= csrf_field() ?>
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= esc(base_url('/time?email=' . rawurlencode($e['email']))) ?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
    <?php
    layout_close();
}

function page_employee_form_new(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    layout_open('Add Employee', $u, '/employees');
    ?>
<div class="card p-4" style="max-width:720px">
  <h4>Add Employee</h4>
  <form method="post" action="<?= esc(base_url('/employees/save')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">First name</label><input class="form-control" name="firstName" required></div>
      <div class="col-md-6"><label class="form-label">Last name</label><input class="form-control" name="lastName" required></div>
      <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
      <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
      <div class="col-md-6"><label class="form-label">Department</label><input class="form-control" name="department"></div>
      <div class="col-md-6"><label class="form-label">Designation</label><input class="form-control" name="designation"></div>
      <div class="col-md-4"><label class="form-label">Role</label>
        <select class="form-select" name="role">
<?php if (is_super_admin($u)): ?><option value="EMPLOYEE">EMPLOYEE</option><option value="HR">HR</option><option value="ADMIN">ADMIN</option><option value="SUPER_ADMIN">SUPER_ADMIN</option>
  <?php elseif ($u['role'] === 'ADMIN'): ?><option value="EMPLOYEE">EMPLOYEE</option><option value="HR">HR</option><option value="ADMIN">ADMIN</option>
  <?php else: ?><option value="EMPLOYEE">EMPLOYEE</option><?php endif; ?>
        </select>
        <?php if (!is_super_admin($u) && $u['role'] !== 'ADMIN'): ?><div class="form-text">HR can only create EMPLOYEE accounts.</div><?php endif; ?>
      </div>
      <div class="col-md-4"><label class="form-label">Salary</label><input type="number" step="0.01" class="form-control" name="salary"></div>
      <div class="col-md-4"><label class="form-label">Join date</label><input type="date" class="form-control" name="joinDate"></div>
      <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" name="password" required></div>
      <div class="col-md-6"><label class="form-label">Address</label><input class="form-control" name="address"></div>
      <div class="col-12"><button class="btn btn-primary">Save</button> <a class="btn btn-outline-secondary" href="<?= esc(base_url('/employees')) ?>">Cancel</a></div>
    </div>
  </form>
</div>
    <?php
    layout_close();
}

function page_employee_form_edit(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $e = employee_find($id);
    if ($e === null) redirect(base_url('/employees'));
    // HR may edit EMPLOYEE-role people only, and never change a role.
    $hrLocked = $u['role'] === 'HR';
    if ($hrLocked && ($e['role'] ?? '') !== 'EMPLOYEE') {
        flash('danger', 'HR can only edit EMPLOYEE accounts.');
        redirect(base_url('/employees'));
    }
    layout_open('Edit Employee', $u, '/employees');
    ?>
<div class="card p-4" style="max-width:720px">
  <h4>Edit - <?= esc(employee_full_name($e)) ?></h4>
  <form method="post" action="<?= esc(base_url('/employees/update')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">First name</label><input class="form-control" name="firstName" value="<?= esc($e['first_name']) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Last name</label><input class="form-control" name="lastName" value="<?= esc($e['last_name']) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="wfEditEmail" value="<?= esc($e['email']) ?>" required>
        <div class="form-text" id="wfEmailWarn" style="display:none;color:#b02a37">You are changing the login address. A new password is required, otherwise this person will not know the password to sign in on the desktop agent.</div>
      </div>
      <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= esc($e['phone']) ?>"></div>
      <div class="col-md-6"><label class="form-label">Department</label><input class="form-control" name="department" value="<?= esc($e['department']) ?>"></div>
      <div class="col-md-6"><label class="form-label">Designation</label><input class="form-control" name="designation" value="<?= esc($e['designation']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Role</label>
        <?php if ($hrLocked): ?>
          <input type="hidden" name="role" value="EMPLOYEE">
          <input class="form-control" value="EMPLOYEE" disabled>
          <div class="form-text">HR cannot change anyone's role.</div>
        <?php else: ?>
        <select class="form-select" name="role"><option value="EMPLOYEE" <?= $e['role'] === 'EMPLOYEE' ? 'selected' : '' ?>>EMPLOYEE</option><option value="HR" <?= $e['role'] === 'HR' ? 'selected' : '' ?>>HR</option><option value="ADMIN" <?= $e['role'] === 'ADMIN' ? 'selected' : '' ?>>ADMIN</option><?php if (is_super_admin($u)): ?><option value="SUPER_ADMIN" <?= $e['role'] === 'SUPER_ADMIN' ? 'selected' : '' ?>>SUPER_ADMIN</option><?php endif; ?></select>
        <?php endif; ?>
      </div>
      <div class="col-md-4"><label class="form-label">Salary</label><input type="number" step="0.01" class="form-control" name="salary" value="<?= esc((string) $e['salary']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Join date</label><input type="date" class="form-control" name="joinDate" value="<?= esc($e['join_date']) ?>"></div>
      <div class="col-md-6"><label class="form-label">New password</label>
        <div class="input-group">
          <input class="form-control" name="password" id="wfEditPass" minlength="6" autocomplete="new-password">
          <button class="btn btn-outline-secondary" type="button" id="wfGenEditPass" title="Generate password"><i class="bi bi-shuffle"></i></button>
        </div>
        <div class="form-text" id="wfPassHint">Leave blank to keep the current password. Fill this in to give them a new one to sign in with on the desktop agent.</div>
      </div>
      <div class="col-md-6"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= esc($e['address']) ?>"></div>
      <div class="col-12"><button class="btn btn-primary">Update</button> <a class="btn btn-outline-secondary" href="<?= esc(base_url('/employees')) ?>">Cancel</a></div>
    </div>
  </form>
</div>
<script>
(function () {
  var email = document.getElementById('wfEditEmail');
  var warn = document.getElementById('wfEmailWarn');
  var pass = document.getElementById('wfEditPass');
  var hint = document.getElementById('wfPassHint');
  var original = <?= json_encode((string) $e['email']) ?>;
  var gen = document.getElementById('wfGenEditPass');

  function refresh() {
    var changed = email.value.trim().toLowerCase() !== original;
    warn.style.display = changed ? '' : 'none';
    pass.required = changed;
    hint.innerHTML = changed
      ? '<strong style="color:#b02a37">Required</strong> - this is a new login address, so set the password the employee will use on the desktop agent.'
      : 'Leave blank to keep the current password. Fill this in to give them a new one to sign in with on the desktop agent.';
  }

  email.addEventListener('input', refresh);
  gen.addEventListener('click', function () {
    pass.value = WorkfolioGeneratePassword();
    refresh();
  });
  refresh();
})();
</script>
    <?php
    layout_close();
}

function page_employee_save(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $role = $_POST['role'] ?? 'EMPLOYEE';
    $allowed = access_assignable_roles($u);
    // HR must never mint an HR/ADMIN/SUPER_ADMIN account, and only the owner may
    // grant the owner role.
    if (!in_array($role, $allowed, true)) {
        flash('danger', is_super_admin($u)
            ? 'That is not a valid role.'
            : 'You cannot assign that role. Only the super admin can create HR, ADMIN or SUPER_ADMIN logins.');
        redirect(base_url('/employees'));
    }
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($email === '') {
        flash('danger', 'Email address is required.');
        redirect(base_url('/employees'));
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', '"' . $email . '" is not a valid email address, so the account was not created.');
        redirect(base_url('/employees'));
    }
    $existing = employee_find_by_email($email);
    if ($existing !== null) {
        // This used to redirect silently, which looked like a save: the old
        // password stayed in place and the new one never worked.
        flash('danger', 'No account was created: ' . $email . ' already belongs to '
            . employee_full_name($existing) . '. Open Edit on that person to change their details or set a new password.');
        redirect(base_url('/employees'));
    }
    $password = (string) ($_POST['password'] ?? '');
    if (strlen(trim($password)) < 6) {
        flash('danger', 'Set a password of at least 6 characters for ' . $email . ', otherwise nobody can sign in with it.');
        redirect(base_url('/employees'));
    }
    $firstName = trim((string) ($_POST['firstName'] ?? ''));
    $lastName = trim((string) ($_POST['lastName'] ?? ''));
    q('INSERT INTO employees (first_name,last_name,email,phone,department,designation,`role`,salary,join_date,address,password) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        $firstName, $lastName,
        $email, trim((string) ($_POST['phone'] ?? '')), trim((string) ($_POST['department'] ?? '')),
        trim((string) ($_POST['designation'] ?? '')), $role,
        ($_POST['salary'] ?? '') === '' ? null : (float) $_POST['salary'],
        $_POST['joinDate'] ?? '', trim((string) ($_POST['address'] ?? '')),
        password_hash($password, PASSWORD_BCRYPT),
    ]);
    config_for($email);
    activity_log($u, 'ADD_EMPLOYEE', 'Added new employee ' . $email, 'web');
    $sent = mail_credentials([
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $email,
        'role'       => $role,
    ], $password, 'created');
    if ($sent === WF_MAIL_SENT) {
        activity_log($u, 'SEND_CREDENTIALS', 'Emailed sign-in details to ' . $email, 'web');
    }
    flash('success', ($firstName . ' ' . $lastName !== '' ? $firstName . ' ' . $lastName : $email)
        . ' added as ' . $role . '. They sign in at ' . base_url('/login')
        . ' with ' . $email . ' and the password you just set.' . mail_flash_suffix($sent, $email));
    redirect(base_url('/employees'));
}

function page_employee_update(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $target = employee_find($id);
    if ($target === null) redirect(base_url('/employees'));
    // access_can_edit_account() also encodes "HR only reaches EMPLOYEE rows".
    if (!access_can_edit_account($u, $target)) {
        flash('danger', $u['role'] === 'HR'
            ? 'HR can only edit EMPLOYEE accounts.'
            : 'Only a SUPER_ADMIN can edit another SUPER_ADMIN login.');
        redirect(base_url('/employees'));
    }
    // HR may never raise anyone's role; every other role change must be one the
    // actor is allowed to assign in the first place.
    if ($u['role'] === 'HR') {
        $role = 'EMPLOYEE';
    } else {
        $assignable = access_assignable_roles($u);
        $posted = $_POST['role'] ?? '';
        // An ADMIN may not promote anyone to, or demote, the owner role.
        $role = in_array($posted, $assignable, true) ? $posted : $target['role'];
    }
    // Never let the last owner be demoted: that would strip the one role that
    // can repair the system, leaving an admin who cannot grant it again.
    if (!access_role_change_allowed($target, $role)) {
        flash('danger', 'This is the only SUPER_ADMIN login. Create a second one first, otherwise nobody could restore owner access.');
        redirect(base_url('/employees'));
    }
    $email = strtolower(trim((string) ($_POST['email'] ?? $target['email'])));
    $dupe = employee_find_by_email($email);
    if ($dupe !== null && (int) $dupe['id'] !== $id) {
        flash('danger', 'No changes were saved: ' . $email . ' already belongs to ' . employee_full_name($dupe) . '.');
        redirect(base_url('/employees'));
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', '"' . $email . '" is not a valid email address, so no changes were saved.');
        redirect(base_url('/employees'));
    }
    $newPass = trim((string) ($_POST['password'] ?? ''));
    if ($newPass !== '' && strlen($newPass) < 6) {
        flash('danger', 'A password must be at least 6 characters. No changes were saved.');
        redirect(base_url('/employees'));
    }
    /* Changing the email turns this row into a different person's login. The old
       password belongs to the old address, so keeping it left the new employee
       unable to sign in on the desktop agent with no way to know why. Refuse the
       save until a password for the new address is supplied. */
    if ($email !== (string) $target['email'] && $newPass === '') {
        flash('danger', 'No changes were saved: you changed the email from '
            . $target['email'] . ' to ' . $email . '. Set a password of at least 6 '
            . 'characters so ' . $email . ' can sign in on the desktop agent.');
        redirect(base_url('/admin/employees/edit/' . $id));
    }
    q('UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, department=?, designation=?, `role`=?, salary=?, join_date=?, address=?' . ($newPass !== '' ? ', password=?' : '') . ' WHERE id=?', [
        trim((string) ($_POST['firstName'] ?? '')), trim((string) ($_POST['lastName'] ?? '')),
        $email, trim((string) ($_POST['phone'] ?? '')), trim((string) ($_POST['department'] ?? '')),
        trim((string) ($_POST['designation'] ?? '')), $role,
        ($_POST['salary'] ?? '') === '' ? null : (float) $_POST['salary'],
        $_POST['joinDate'] ?? '', trim((string) ($_POST['address'] ?? '')),
        ...($newPass !== '' ? [password_hash($newPass, PASSWORD_BCRYPT)] : []),
        $id,
    ]);
    if ($email !== (string) $target['email']) config_for($email);
    activity_log($u, 'UPDATE_EMPLOYEE', 'Updated details of ' . $email, 'web');
    $sentNote = '';
    if ($newPass !== '') {
        $sent = mail_credentials(array_merge($target, ['email' => $email, 'role' => $role]), $newPass, 'reset');
        if ($sent === WF_MAIL_SENT) {
            activity_log($u, 'SEND_CREDENTIALS', 'Emailed the new password to ' . $email, 'web');
        }
        $sentNote = ' - sign-in details for the employee: ' . $email . ' / ' . $newPass;
    }
    flash('success', employee_full_name($target) . ' updated' . ($newPass !== '' ? $sentNote : '.'));
    redirect(base_url('/employees'));
}

/**
 * Sets a known password for one person, so the employee can always sign in on
 * the desktop agent.
 *
 * This exists because HR can create employees from /employees but could not reach
 * the Credentials page (ADMIN only), so an HR-created account whose password was
 * lost or left blank had no way back in. HR is limited to EMPLOYEE accounts, which
 * mirrors page_employee_update() and page_employee_delete().
 */
function page_employee_password(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    $target = employee_find($id);
    if ($target === null) {
        flash('warning', 'That employee no longer exists.');
        redirect(base_url('/employees'));
    }
    // Resetting the owner's password would hand the owner account to whoever
    // asked for it, so only another owner may do this.
    if (!access_can_reset_password($u, $target)) {
        flash('danger', $u['role'] === 'HR'
            ? 'HR can only set passwords for EMPLOYEE accounts.'
            : 'Only a SUPER_ADMIN can reset a SUPER_ADMIN password.');
        redirect(base_url('/employees'));
    }
    if ((int) $target['id'] === (int) $u['id']) {
        flash('danger', 'Change your own password from your profile page.');
        redirect(base_url('/profile'));
    }
    $password = trim((string) ($_POST['password'] ?? ''));
    if (strlen($password) < 6) {
        flash('danger', 'Set a password of at least 6 characters for ' . $target['email']
            . ' - nothing was changed.');
        redirect(base_url('/employees'));
    }
    q('UPDATE employees SET password=? WHERE id=?', [password_hash($password, PASSWORD_BCRYPT), $id]);
    // A stale token would let the old password keep working until it expires.
    q('DELETE FROM agent_tokens WHERE email=?', [$target['email']]);
    activity_log($u, 'RESET_PASSWORD', 'Set a new password for ' . $target['email'], 'web');
    $sent = mail_credentials($target, $password, 'reset');
    if ($sent === WF_MAIL_SENT) {
        activity_log($u, 'SEND_CREDENTIALS', 'Emailed the new password to ' . $target['email'], 'web');
    }
    flash('success', 'Password set for ' . $target['email']
        . '. Give these to the employee - they sign in on the desktop agent with '
        . $target['email'] . ' / ' . $password
        . '. Any session signed in with the old password has been ended.');
    redirect(base_url('/employees'));
}

/**
 * Removes a person and every record that belongs to them.
 *
 * POST + CSRF on purpose: this used to be a plain GET link, which meant any link
 * prefetcher or crawler could wipe an employee record.
 *
 * The JPEGs are removed with the rows. Deleting only the rows left the files on
 * disk forever, because the retention sweep works off the database and no row
 * pointed at them any more.
 */
function page_employee_delete(int $id): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    require_csrf();
    /* The button lives on two pages (Employees and Credentials). Whitelist the
     * return path so the admin lands back where they clicked from, and never
     * follow a caller-supplied URL. */
    $back = ($_POST['back'] ?? '') === '/portal/users' && (is_super_admin($u) || $u['role'] === 'ADMIN')
        ? '/portal/users'
        : '/employees';
    $victim = employee_find($id);
    if ($victim === null) {
        flash('warning', 'That employee has already been removed.');
        redirect(base_url($back));
    }
    if ((int) $id === (int) $u['id']) {
        // Never delete yourself.
        flash('danger', 'You cannot delete the account you are signed in with.');
        redirect(base_url($back));
    }
    // access_can_delete_account() covers the HR scope limit and the owner takeover
    // rules; the last owner additionally cannot be removed at all.
    if (!access_can_delete_account($u, $victim)) {
        flash('danger', $u['role'] === 'HR'
            ? 'HR can only delete EMPLOYEE accounts. Ask an administrator to remove an HR or ADMIN login.'
            : (is_super_admin($victim)
                ? 'This is the only SUPER_ADMIN login and cannot be deleted.'
                : 'Only a SUPER_ADMIN can remove another SUPER_ADMIN login.'));
        redirect(base_url($back));
    }

    $email = (string) $victim['email'];
    $name = employee_full_name($victim);

    foreach (rows('SELECT * FROM screenshots WHERE email=?', [$email]) as $s) shots_delete($s);
    q('DELETE FROM live_frames WHERE email=?', [$email]);
    $liveFile = live_frame_path($email);
    if (is_file($liveFile)) @unlink($liveFile);
    q('DELETE FROM track_records WHERE email=?', [$email]);
    q('DELETE FROM app_usages WHERE email=?', [$email]);
    q('DELETE FROM agent_tokens WHERE email=?', [$email]);
    q('DELETE FROM agent_configs WHERE email=?', [$email]);
    q('DELETE FROM employees WHERE id=?', [$id]);

    activity_log($u, 'DELETE_EMPLOYEE', 'Deleted ' . $email . ' (' . $name . ') and all their records', 'web');
    flash('success', $name . ' (' . $email . ') and all their tracking records, screenshots and login were deleted.');
    redirect(base_url($back));
}

/* ------------------------------------------------------------------ misc ---- */

function page_login_as(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN', 'HR']);
    $email = trim((string) ($_GET['email'] ?? ''));
    if ($email !== '' && access_can_view_email($u, $email)) {
        redirect(base_url('/time?email=' . rawurlencode($email)));
    }
    redirect(base_url('/time'));
}

function page_reports(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    $date = today();
    $topApps = usage_top_apps_company($date, 10);
    $topSites = usage_top_sites_company($date, 10);
    $byEmp = usage_seconds_by_employee($date);
    layout_open('Reports', $u, '');
    ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-bar-chart me-2 text-primary"></i>Company Reports</h4>
  <span class="text-muted"><?= esc(date('d M Y', strtotime($date))) ?></span>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= (int) employee_count() ?></div><div class="text-soft small">Total Employees</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= (int) employee_count_role('ADMIN') ?></div><div class="text-soft small">Admins</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold"><?= (int) employee_count_role('HR') ?></div><div class="text-soft small">HR</div></div></div>
  <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="fs-4 fw-bold">₹<?= number_format(round(employee_avg_salary() * 100) / 100, 2) ?></div><div class="text-soft small">Avg Salary</div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card p-3">
    <h5>Top apps today (company)</h5>
    <?php $maxA = $topApps ? max($topApps) : 1; foreach ($topApps as $k => $v): ?>
    <div class="mb-2"><div class="d-flex justify-content-between small"><span><?= esc($k) ?></span><span class="text-muted"><?= esc(time_text((int) $v)) ?></span></div>
    <div class="progress" style="height:6px"><div class="progress-bar bg-primary" style="width:<?= (int) round($v * 100 / $maxA) ?>%"></div></div></div>
    <?php endforeach; ?><?php if (!$topApps): ?><div class="text-muted small">No app usage today.</div><?php endif; ?>
  </div></div>
  <div class="col-lg-6"><div class="card p-3">
    <h5>Top sites today (company)</h5>
    <?php $maxS = $topSites ? max($topSites) : 1; foreach ($topSites as $k => $v): ?>
    <div class="mb-2"><div class="d-flex justify-content-between small"><span><?= esc($k) ?></span><span class="text-muted"><?= esc(time_text((int) $v)) ?></span></div>
    <div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:<?= (int) round($v * 100 / $maxS) ?>%"></div></div></div>
    <?php endforeach; ?><?php if (!$topSites): ?><div class="text-muted small">No website usage today.</div><?php endif; ?>
  </div></div>
</div>
<div class="card p-3 mt-3">
  <h5>Working time per employee today</h5>
  <div class="text-soft small mb-2">Worked is classified against the <?= esc(track_target_hours_text()) ?>-hour target. Tracked is app + website time.</div>
  <div class="table-responsive"><table class="table table-sm">
    <thead class="table-light"><tr><th>Employee</th><th>Worked</th><th>Target</th><th>Tracked</th></tr></thead>
    <tbody>
    <?php $anyRow = false; foreach (employee_all() as $emp): $em = $emp['email'];
      $worked = track_worked_seconds(track_record_for($em, $date));
      $secs = (int) ($byEmp[$em] ?? 0);
      if ($worked === 0 && $secs === 0) continue;
      $anyRow = true; ?>
      <tr><td><?= esc(employee_full_name($emp)) ?></td><td><?= esc(fmt_dur($worked)) ?></td><td><?= track_target_chip($worked, $em) ?></td><td><?= esc(time_text($secs)) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$anyRow): ?><tr><td colspan="4" class="text-muted text-center py-3">No tracking today.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
    <?php
    layout_close();
}

function page_activity(): void {
    $u = require_role(['SUPER_ADMIN', 'ADMIN']);
    $role = trim((string) ($_GET['role'] ?? ''));
    $email = trim((string) ($_GET['email'] ?? ''));
    $logs = [];
    if ($email !== '') {
        $logs = activity_recent(500, $email);
    } elseif ($role !== '') {
        foreach (rows('SELECT * FROM activity_log WHERE role=? ORDER BY id DESC LIMIT 500', [$role]) as $l) $logs[] = $l;
    } else {
        $logs = activity_recent(500);
    }
    $emails = [];
    foreach (employee_all() as $e) $emails[] = $e['email'];
    layout_open('Activity Tracker', $u, '/admin/activity');
    ?>
<div class="page-head">
  <div>
    <h4>Activity Tracker</h4>
    <div class="page-sub">Every sign-in, clock action and setting change the system recorded.</div>
  </div>
</div>
<div class="card p-3 mb-3">
  <form method="get" action="<?= esc(base_url('/admin/activity')) ?>" class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Role</label>
      <select name="role" class="form-select"><option value="">All roles</option><option value="SUPER_ADMIN" <?= $role === 'SUPER_ADMIN' ? 'selected' : '' ?>>SUPER_ADMIN</option><option value="ADMIN" <?= $role === 'ADMIN' ? 'selected' : '' ?>>ADMIN</option><option value="HR" <?= $role === 'HR' ? 'selected' : '' ?>>HR</option><option value="EMPLOYEE" <?= $role === 'EMPLOYEE' ? 'selected' : '' ?>>EMPLOYEE</option></select></div>
    <div class="col-md-4"><label class="form-label">Employee</label>
      <select name="email" class="form-select"><option value="">All employees</option><?php foreach ($emails as $em): ?><option value="<?= esc($em) ?>" <?= $email === $em ? 'selected' : '' ?>><?= esc($em) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button></div>
  </form>
</div>
<div class="card p-3">
  <h5><i class="bi bi-eye-fill me-2" style="color:var(--wf-primary-2)"></i>Activity log (<?= count($logs) ?>)</h5>
  <div class="table-responsive"><table class="table table-sm table-hover">
    <thead class="table-light"><tr><th>When</th><th>Employee</th><th>Role</th><th>Action</th><th>Details</th><th>Source</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr><td><?= esc(dt_fmt($l['created_at'] ?? null, 'd M Y, h:i:s a')) ?></td><td><?= esc($l['full_name'] ?? $l['email']) ?></td><td><?= esc($l['role'] ?? '') ?></td><td><span class="badge bg-light text-dark"><?= esc($l['action']) ?></span></td><td class="small"><?= esc($l['details']) ?></td><td><?= esc($l['source']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="6" class="text-center text-muted py-3">No activity recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
    <?php
    layout_close();
}
