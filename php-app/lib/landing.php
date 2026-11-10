<?php
/**
 * WorkFolio PHP - the public landing page.
 *
 * The marketing front door: what the product is, how it works, what it does and
 * not do about privacy, and the sign-in form itself. Reachable at "/" for anyone
 * who is not signed in, and at "/landing" as an explicit address.
 *
 * The sign-in form is the same POST /login the standalone /login screen uses, on
 * purpose. There is one credential check in the app (do_login in lib/auth.php);
 * a landing page with its own copy of the auth path is how the two screens drift
 * apart and one of them ends up accepting a password the other rejects.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/views.php';

/** One reusable "capability" card: [icon, title, body]. */
function landing_features(): array {
    return [
        ['bi-stopwatch-fill', 'Exact attendance, not estimates',
            'Time is counted on a monotonic clock on the employee\'s own machine, so a folded '
            . 'laptop, a changed system clock or a dropped network cannot inflate a shift. '
            . 'Active, idle, break and paused are kept apart and reported separately.'],
        ['bi-broadcast-pin', 'Live floor view',
            'See who is on duty, who is idle, who is on break and which app or site each person '
            . 'is working in, refreshing as the agent reports. Click any tile for that person\'s '
            . 'app and website breakdown.'],
        ['bi-bar-chart-fill', 'Apps and websites, ranked',
            'Every sample is attributed to the window that was in the foreground, with a bounded '
            . 'amount of time per sample, so alt-tabbing mid-interval is not misattributed and a '
            . 'flapping window cannot inflate a total.'],
        ['bi-file-earmark-spreadsheet-fill', 'Reports you can send',
            'Working, active, idle and break totals, productivity, per-employee and day-by-day '
            . 'breakdowns, screenshot gallery, plus CSV and print export. One file per screen.'],
        ['bi-sliders', 'Policy pushed, not deployed',
            'Screenshot interval, live-stream rate, idle threshold, capture quality, tracking '
            . 'switches, excluded processes and blocked sites are per employee and change the '
            . 'running agent immediately. No reinstall, no redeploy.'],
        ['bi-hdd-network-fill', 'Nothing is lost offline',
            'Usage samples and screenshots are queued to disk while the server is unreachable and '
            . 'replayed on reconnect, so a twenty-minute dropout does not cost an afternoon.'],
    ];
}

/** The four-step "how it works" column. */
function landing_steps(): array {
    return [
        ['bi-download', 'Install the agent',
            'One setup file per employee. It registers to start with Windows and pulls its whole '
            . 'policy from the server, so nothing else is configured on the PC.'],
        ['bi-box-arrow-in-right', 'Clock in and work',
            'The employee signs in, clocks in, and takes breaks from the agent or the tray menu. '
            . 'Time is bucketed automatically from there.'],
        ['bi-activity', 'Everything is recorded',
            'Foreground windows, websites, input event counts and periodic screenshots flow to '
            . 'the server as they happen. No key text is ever read.'],
        ['bi-graph-up', 'Review and export',
            'Dashboards, the activity report and per-day history, with CSV and print export for '
            . 'whoever needs the numbers outside the app.'],
    ];
}

/**
 * Renders the landing page, or bounces an already-signed-in visitor straight to
 * the dashboard. GET only - the form posts to /login.
 */
function page_landing(): void {
    if (cur_user() !== null) redirect(base_url('/dashboard'));

    $error  = !empty($_GET['error']);
    $logout = !empty($_GET['logout']);

    global $WF;
    $showDemo = !empty($WF['show_demo']);

    $features = landing_features();
    $steps    = landing_steps();
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Folio - Employee Activity, Time Tracking &amp; Live Monitoring</title>
<meta name="description" content="Folio records employee attendance, app and website usage and live screen activity, then turns it into reports an admin can actually send. Exact time, honest totals, privacy-first.">
<link rel="canonical" href="<?= esc(app_origin() . base_url('/')) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Folio - Employee Activity &amp; Time Tracking">
<meta property="og:description" content="Exact attendance, live floor view, app and website insights, and reports with CSV export.">
<meta property="og:image" content="<?= esc(app_origin() . base_url('/icons/icon-512.png')) ?>">
<meta name="twitter:card" content="summary_large_image">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= esc(base_url('/assets/wf.css')) ?>?v=20261005b" rel="stylesheet">
<script>
/* pick the theme before first paint so the page never flashes the wrong colours */
(function () { try { var t = localStorage.getItem('wf-theme');
  if (!t) t = (window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t); } catch (e) {} })();
</script>
<?= pwa_head() ?>
</head>
<body class="lp-body wf-in">

<a class="lp-skip" href="#lp-signin">Skip to sign in</a>

<!-- ============================================================== top bar -->
<header class="lp-top">
  <div class="lp-wrap lp-top-in">
    <a class="lp-brand" href="<?= esc(base_url('/')) ?>">
      <img src="<?= esc(base_url('/icons/icon-192.png')) ?>?v=20261005a" alt="" width="38" height="38">
      <span>
        <span class="lp-brand-name">Folio</span>
        <span class="lp-brand-sub">Monitoring Suite</span>
      </span>
    </a>
    <nav class="lp-links" aria-label="Sections">
      <a href="#lp-features">Features</a>
      <a href="#lp-how">How it works</a>
      <a href="#lp-privacy">Privacy</a>
      <a href="#lp-faq">FAQ</a>
    </nav>
    <div class="lp-top-right">
      <button type="button" class="wf-icon-btn" data-theme-toggle title="Switch theme" aria-label="Switch theme"><i class="bi bi-sun"></i></button>
      <a class="btn btn-primary lp-btn-sm" href="#lp-signin"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
    </div>
  </div>
</header>

<main>

<!-- ================================================================= hero -->
<section class="lp-hero">
  <div class="lp-hero-glow" aria-hidden="true"></div>
  <div class="lp-wrap lp-hero-in">

    <div class="lp-hero-copy">
      <span class="lp-pill"><i class="bi bi-shield-check"></i> Event counters only - never typed text</span>
      <h1 class="lp-h1">Know the hours.<br>See the work.<br><span class="lp-h1-grad">Report it honestly.</span></h1>
      <p class="lp-lead">
        Folio turns a working day into numbers you can defend: exact attendance down to the
        minute, live visibility of the floor, and a ranked view of every app and site your
        team actually used. No estimates, no double counting, no inflated totals.
      </p>
      <div class="lp-hero-cta">
        <a class="btn btn-primary" href="#lp-signin"><i class="bi bi-box-arrow-in-right"></i> Sign in to your workspace</a>
        <a class="btn btn-outline-secondary" href="#lp-how"><i class="bi bi-play-circle"></i> See how it works</a>
      </div>
      <ul class="lp-trust">
        <li><i class="bi bi-check2-circle"></i> Monotonic clock - sleep and clock changes cannot distort a shift</li>
        <li><i class="bi bi-check2-circle"></i> Break and paused time excluded from worked hours</li>
        <li><i class="bi bi-check2-circle"></i> Offline queue - a network drop never costs an afternoon</li>
      </ul>
    </div>

    <!-- sign-in card: the same POST /login the standalone screen uses -->
    <div class="lp-signin-col" id="lp-signin">
      <div class="card lp-card">
        <div class="lp-card-top">
          <h2 class="lp-card-h">Sign in</h2>
          <p class="lp-card-sub">Use your work email to continue.</p>
        </div>
        <div class="lp-card-body">
          <?php if ($error): ?>
          <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Invalid email or password.</div>
          <?php endif; ?>
          <?php if ($logout): ?>
          <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>You have been logged out.</div>
          <?php endif; ?>
          <form method="post" action="<?= esc(base_url('/login')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label" for="lpEmail">Work email</label>
              <div class="input-group">
                <span class="input-group-text lp-ig"><i class="bi bi-envelope"></i></span>
                <input type="email" id="lpEmail" name="username" class="form-control" placeholder="you@company.com" required autocomplete="username">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="lpPass">Password</label>
              <div class="input-group">
                <span class="input-group-text lp-ig"><i class="bi bi-lock"></i></span>
                <input type="password" id="lpPass" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                <button class="btn btn-outline-secondary lp-eye" type="button" id="lpEye" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>
              </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 lp-submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
          </form>
          <?php if ($showDemo): ?>
          <div class="lp-demo">
            <div class="fw-bold mb-2"><i class="bi bi-info-circle me-1"></i>Demo accounts</div>
            <div class="lp-demo-row"><code>admin@workfolio.com</code><code>admin123</code></div>
          </div>
          <?php endif; ?>
          <div class="lp-card-foot">
            <i class="bi bi-shield-lock"></i> Protected workspace &middot; v<?= esc(app_version()) ?>
          </div>
        </div>
      </div>
      <p class="lp-card-note">
        <i class="bi bi-key"></i> Sessions use a server-side session cookie and regenerate on every sign-in.
      </p>
    </div>

  </div>
</section>

<!-- ============================================================ proof strip -->
<section class="lp-strip">
  <div class="lp-wrap lp-strip-in">
    <div class="lp-strip-item"><b>4</b><span>separate time buckets<br>active &middot; idle &middot; break &middot; paused</span></div>
    <div class="lp-strip-item"><b>30s</b><span>agent heartbeat<br>and 30s ledger save</span></div>
    <div class="lp-strip-item"><b>120s</b><span>maximum time one<br>sample may claim</span></div>
    <div class="lp-strip-item"><b>0</b><span>keystrokes stored<br>counters only, never text</span></div>
  </div>
</section>

<!-- ============================================================== features -->
<section class="lp-sec" id="lp-features">
  <div class="lp-wrap">
    <div class="lp-sec-head">
      <span class="lp-eyebrow">What you get</span>
      <h2 class="lp-h2">Everything the day produced, in one place</h2>
      <p class="lp-sub">Each of these is a screen in the app, not a promise on a slide.</p>
    </div>
    <div class="lp-grid">
      <?php foreach ($features as $i => $f): ?>
      <article class="card card-hover lp-tile wf-reveal" style="--d: <?= $i * 60 ?>ms">
        <div class="stat-ico lp-tile-ico"><i class="bi <?= esc($f[0]) ?>"></i></div>
        <h3 class="lp-tile-h"><?= esc($f[1]) ?></h3>
        <p class="lp-tile-p"><?= esc($f[2]) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ how it works -->
<section class="lp-sec lp-sec-alt" id="lp-how">
  <div class="lp-wrap">
    <div class="lp-sec-head">
      <span class="lp-eyebrow">How it works</span>
      <h2 class="lp-h2">Four steps, then it runs itself</h2>
      <p class="lp-sub">Nothing here needs a technical background to set up or to use.</p>
    </div>
    <ol class="lp-steps">
      <?php foreach ($steps as $i => $s): ?>
      <li class="lp-step wf-reveal" style="--d: <?= $i * 70 ?>ms">
        <span class="lp-step-n"><?= $i + 1 ?></span>
        <div class="lp-step-ico"><i class="bi <?= esc($s[0]) ?>"></i></div>
        <h3 class="lp-step-h"><?= esc($s[1]) ?></h3>
        <p class="lp-step-p"><?= esc($s[2]) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- =============================================================== privacy -->
<section class="lp-sec" id="lp-privacy">
  <div class="lp-wrap lp-privacy">
    <div class="lp-privacy-copy">
      <span class="lp-eyebrow">Privacy</span>
      <h2 class="lp-h2">Monitoring that does not read your people</h2>
      <p>
        The keyboard and mouse hooks count events. They do not read a key code, a character or a
        typed word - none of that is logged, stored or transmitted, and there is no code path that
        could send it.
      </p>
      <p>
        Administrators can switch off screenshots, input tracking, app tracking or website tracking
        per employee, and can exclude processes or block hosts so those windows are never recorded
        at all.
      </p>
      <p class="lp-fine">
        Monitoring of this kind should be disclosed to your staff and run in line with local
        labour law. Folio gives you the record; the conversation is yours to have.
      </p>
      <div class="lp-privacy-badges">
        <span><i class="bi bi-shield-check"></i> Counters only</span>
        <span><i class="bi bi-toggle-on"></i> Per-employee switches</span>
        <span><i class="bi bi-slash-circle"></i> Blocked hosts</span>
        <span><i class="bi bi-trash3"></i> Retention purge</span>
      </div>
    </div>
    <aside class="card lp-privacy-card">
      <div class="lp-privacy-card-h"><i class="bi bi-eye-slash-fill"></i> What is never collected</div>
      <ul class="lp-never">
        <li><i class="bi bi-x-lg"></i> Typed characters or passwords</li>
        <li><i class="bi bi-x-lg"></i> Anything from a blocked site</li>
        <li><i class="bi bi-x-lg"></i> Windows from an excluded process</li>
        <li><i class="bi bi-x-lg"></i> Screenshots, when an admin turns them off</li>
        <li><i class="bi bi-x-lg"></i> Anything past the retention window</li>
      </ul>
      <div class="lp-privacy-card-f">
        <i class="bi bi-info-circle"></i> Role-scoped reporting: an HR cannot open another HR's or the
        admin's rows, in the page, the export, the live grid or by guessing a screenshot id.
      </div>
    </aside>
  </div>
</section>

<!-- ================================================================== faq -->
<section class="lp-sec lp-sec-alt" id="lp-faq">
  <div class="lp-wrap lp-faq-wrap">
    <div class="lp-sec-head">
      <span class="lp-eyebrow">FAQ</span>
      <h2 class="lp-h2">The questions worth asking first</h2>
    </div>
    <div class="lp-faq">
      <details class="lp-q wf-reveal">
        <summary><span>Can a five-hour shift be reported as two or three?</span><i class="bi bi-plus-lg"></i></summary>
        <p>No. Time is counted on a monotonic clock on the employee's machine, and the server stores
        those figures verbatim instead of re-deriving them from wall-clock gaps. The moment the agent
        reports its own buckets for the day, the server stops adding its own arithmetic - which used
        to be the thing that could only ever overstate a shift.</p>
      </details>
      <details class="lp-q wf-reveal" style="--d: 60ms">
        <summary><span>Does idle time count as work?</span><i class="bi bi-plus-lg"></i></summary>
        <p>Worked time is active plus idle, because a person sitting at their desk thinking or in a
        meeting has not stopped working. Break and paused time are excluded. If you need the stricter
        number, Active is reported separately on every screen and in the CSV.</p>
      </details>
      <details class="lp-q wf-reveal" style="--d: 120ms">
        <summary><span>What happens when the internet goes down?</span><i class="bi bi-plus-lg"></i></summary>
        <p>The agent queues usage samples, screenshots and its shift totals to disk and replays them on
        reconnect. Totals only ever grow within a day, so a late or retried batch cannot rewind the
        morning - and a network outage cannot silently cost somebody their afternoon.</p>
      </details>
      <details class="lp-q wf-reveal" style="--d: 180ms">
        <summary><span>Do I have to reinstall the agent to change a setting?</span><i class="bi bi-plus-lg"></i></summary>
        <p>No. The agent fetches its whole policy from the server every cycle, so screenshot interval,
        live-stream rate, idle threshold, capture quality and the tracking switches take effect on the
        running agent as soon as you save them.</p>
      </details>
      <details class="lp-q wf-reveal" style="--d: 240ms">
        <summary><span>Can an admin read an employee's password?</span><i class="bi bi-plus-lg"></i></summary>
        <p>Not from the app. Credentials are stored as bcrypt hashes and verified with
        password_verify. Anyone with the database file could copy a hash, but a hash is not a password
        - it cannot be turned back into one.</p>
      </details>
      <details class="lp-q wf-reveal" style="--d: 300ms">
        <summary><span>What does the employee see?</span><i class="bi bi-plus-lg"></i></summary>
        <p>Their own day only: clock in and out, breaks, the shift timeline, and their totals. An
        employee can see themselves and nobody else, and that rule is enforced on the server for
        every report, export and screenshot lookup.</p>
      </details>
    </div>
  </div>
</section>

<!-- ================================================================== cta -->
<section class="lp-cta">
  <div class="lp-wrap lp-cta-in">
    <div>
      <h2 class="lp-h2 mb-2">Sign in to your workspace</h2>
      <p class="lp-sub mb-0">Use the work email your administrator set up for you.</p>
    </div>
    <a class="btn btn-primary lp-cta-btn" href="#lp-signin"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
  </div>
</section>

</main>

<!-- ================================================================ footer -->
<footer class="lp-foot">
  <div class="lp-wrap lp-foot-in">
    <div class="lp-foot-brand">
      <img src="<?= esc(base_url('/icons/icon-192.png')) ?>?v=20261005a" alt="" width="30" height="30">
      <div>
        <div class="lp-brand-name">Folio</div>
        <div class="lp-foot-sub">Employee Activity &amp; Reports</div>
      </div>
    </div>
    <nav class="lp-foot-links" aria-label="Footer">
      <a href="#lp-features">Features</a>
      <a href="#lp-how">How it works</a>
      <a href="#lp-privacy">Privacy</a>
      <a href="#lp-faq">FAQ</a>
      <a href="<?= esc(base_url('/login')) ?>">Sign in</a>
    </nav>
    <div class="lp-foot-meta">
      <span><i class="bi bi-shield-check"></i> v<?= esc(app_version()) ?></span>
      <span class="wf-tz-hint">Times shown in <?= esc(wf_tz_label()) ?></span>
    </div>
  </div>
</footer>

<script src="<?= esc(base_url('/assets/wf.js')) ?>?v=20261005b"></script>
<script>
/* show/hide password. Kept inline and tiny rather than in wf.js because the
   landing page is the only screen with this control. */
(function () {
  var eye = document.getElementById('lpEye'), pass = document.getElementById('lpPass');
  if (!eye || !pass) return;
  eye.addEventListener('click', function () {
    var show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    eye.setAttribute('aria-pressed', show ? 'true' : 'false');
    eye.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    pass.focus();
  });
})();
</script>
</body>
</html>
<?php
    exit;
}
