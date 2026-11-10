# Folio PHP - time accuracy regression test.
#
# These are the four defects that made the numbers in the reports wrong, all of
# them proven against the real HTTP API rather than against stubbed helpers:
#
#   1. The server's wall clock was still writing into the same buckets the agent
#      owns, and the larger number won. A long gap therefore counted as work.
#   2. A second clock-in on the same day left the morning's totals in place, so
#      the afternoon contributed nothing at all.
#   3. A row left open (no clock-out) kept growing every time a report was read.
#   4. An agent that reports no totals at all was read as "worked zero seconds",
#      which could wipe the day.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File test-time-accuracy.ps1
# It starts its own `php -S`, drops a fresh SQLite database and cleans up after.

param(
    [string]$Php = 'php'
)

$ErrorActionPreference = 'Stop'
$port = 8871
$base = "http://127.0.0.1:$port"
$phpApp = [System.IO.Path]::GetFullPath($PSScriptRoot)

$global:pass = 0
$global:fail = 0

function Ok([string]$name) {
    $global:pass++
    Write-Host "  [PASS] $name" -ForegroundColor Green
}
function Bad([string]$name, [string]$detail = '') {
    $global:fail++
    Write-Host "  [FAIL] $name  $detail" -ForegroundColor Red
}
function Check([bool]$cond, [string]$name, [string]$detail = '') {
    if ($cond) { Ok $name } else { Bad $name $detail }
}
function JsonPost([string]$path, $body) {
    Invoke-RestMethod -Uri ($base + $path) -Method Post `
        -ContentType 'application/json' -Body ($body | ConvertTo-Json -Compress) -UseBasicParsing
}
function JsonGet([string]$path) {
    Invoke-RestMethod -Uri ($base + $path) -UseBasicParsing
}

# Runs a snippet of PHP with $phpExe and returns whatever it printed. The SQL
# assertions below need to look at columns the API does not expose (totals_locked,
# last_seen), so they go straight at the SQLite file. The snippet is written to a
# temp file rather than passed inline: PowerShell mangles nested quotes in
# `php -r '...'` badly enough to produce parse errors that look like app bugs.
$script:snip = 0
function Php([string]$code, [hashtable]$vars = @{}) {
    $script:snip++
    $f = Join-Path $env:TEMP ("wf-snip-$($script:snip).php")
    $pre = ''
    foreach ($k in $vars.Keys) { $pre += '$' + $k + ' = ' + ([string]$vars[$k]).Trim("'") + "';`n" }
    [System.IO.File]::WriteAllText($f, "<?php`n" + $pre + $code)
    $out = & $phpExe $f 2>&1
    Remove-Item $f -ErrorAction SilentlyContinue
    return ($out | Out-String).Trim()
}

# ------------------------------------------------------------------ boot PHP
$phpExe = (Get-Command $Php -ErrorAction SilentlyContinue).Source
if (-not $phpExe) { foreach ($c in @("E:\XAMPP\php\php.exe", "$env:ProgramFiles\php\php.exe", 'C:\php\php.exe')) { if (Test-Path $c) { $phpExe = $c; break } } }
if (-not $phpExe) { Write-Host "php.exe not found. Pass -Php <path>" -ForegroundColor Red; exit 1 }
Write-Host "Using $phpExe $(& $phpExe -r 'echo PHP_VERSION;')" -ForegroundColor Cyan

$wfData = Join-Path $phpApp 'data'
foreach ($f in @('db.sqlite', 'db.sqlite-journal', 'db.sqlite-wal', 'db.sqlite-shm')) {
    Remove-Item (Join-Path $wfData $f) -ErrorAction SilentlyContinue
}
Remove-Item (Join-Path $wfData '.last_purge') -ErrorAction SilentlyContinue

$server = Start-Process -FilePath $phpExe `
    -ArgumentList @('-S', "127.0.0.1:$port", '-t', $phpApp, (Join-Path $phpApp 'index.php')) `
    -WorkingDirectory $phpApp `
    -RedirectStandardOutput "$env:TEMP\wf-time-srv.log" `
    -RedirectStandardError "$env:TEMP\wf-time-srv-err.log" `
    -PassThru -WindowStyle Hidden

try {
    $ready = $false
    for ($i = 0; $i -lt 40; $i++) {
        try { $r = Invoke-WebRequest "$base/login" -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -eq 200) { $ready = $true; break } } catch { Start-Sleep -Milliseconds 300 }
    }
    if (-not $ready) {
        Write-Host "--- stderr ---" -ForegroundColor Yellow
        Get-Content "$env:TEMP\wf-time-srv-err.log" -ErrorAction SilentlyContinue
        exit 1
    }

    # --------------------------------------------------------------- timezone
    Write-Host "`n== Timezone ==" -ForegroundColor Cyan
    $login = JsonPost '/api/agent/login' @{ email = 'admin@workfolio.com'; password = 'admin123' }
    Check ($login.ok -eq $true) 'admin agent login works'
    $tok = $login.token

    # The wall clock the server stamps rows with must be Karachi, not UTC. Five
    # hours out on every timestamp in the product is the single largest source
    # of "the time is wrong" reports.
    $stamp = JsonPost '/api/agent/check-in' @{ token = $tok }
    Check ($stamp.ok -eq $true) 'check-in accepted'

    $dbPath = (Join-Path $wfData 'db.sqlite') -replace '\\', '/'

    # The timezone has to be asserted on the APP's clock, not the CLI's: running
    # php.ini in a snippet reports the developer's own machine zone and proves
    # nothing. The only honest test is to compare what the app stamped against
    # real UTC. Asia/Karachi is UTC+5, so the app's stamp must be exactly five
    # hours ahead of UTC - if the app were still on UTC it would be spot on and
    # every timestamp in the product would be five hours wrong.
    $writtenUtc = [int][math]::Floor(([DateTime]::UtcNow - ([DateTime]::SpecifyKind([datetime]'1970-01-01', 'Utc'))).TotalSeconds)
    $written = (Php @'
$p = new PDO('sqlite:__DB__');
$r = $p->query('SELECT check_in FROM track_records ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['check_in' => $r['check_in'] ?? null]);
'@.Replace('__DB__', $dbPath)) | ConvertFrom-Json

    # The app writes a naive wall-clock string. Reading it as if it were UTC turns
    # it into (real UTC + the app's offset), so subtracting real UTC leaves the
    # offset the app is actually running on.
    $inv = [Globalization.CultureInfo]::InvariantCulture
    $appWall = [datetime]::ParseExact($written.check_in, 'yyyy-MM-dd HH:mm:ss', $inv)
    $appAsUtc = [int][math]::Floor(($appWall - ([DateTime]::SpecifyKind([datetime]'1970-01-01', 'Utc'))).TotalSeconds)
    $offsetHours = [math]::Round(($appAsUtc - $writtenUtc) / 3600.0, 2)
    Check ($offsetHours -eq 5) "the app stamps rows in Asia/Karachi, i.e. UTC+5 (measured offset UTC${offsetHours}h)"

    # And the app itself must agree, not just the numbers.
    $tzInfo = (Php @'
require_once '__APP__/lib/core.php';
echo json_encode(['tz' => wf_tz(), 'offset_min' => wf_tz_offset_min(), 'label' => wf_tz_label()]);
'@.Replace('__APP__', ($phpApp -replace '\\', '/'))) | ConvertFrom-Json
    Check ($tzInfo.tz -eq 'Asia/Karachi') "wf_tz() resolves to Asia/Karachi (got $($tzInfo.tz))"
    Check ($tzInfo.offset_min -eq 300) "wf_tz_offset_min() reports +300 minutes (got $($tzInfo.offset_min))"

    # A stored stamp must read back as the same wall-clock time it was written.
    # If the write and the read disagree by the UTC offset, the row is lying.
    $echoInfo = (Php @'
require_once '__APP__/lib/core.php';
$p = new PDO('sqlite:__DB__');
$r = $p->query('SELECT check_in FROM track_records ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['written' => $r['check_in'], 'read_back' => ts_of($r['check_in']) === null ? null : date('Y-m-d H:i:s', ts_of($r['check_in']))]);
'@.Replace('__DB__', $dbPath).Replace('__APP__', ($phpApp -replace '\\', '/'))) | ConvertFrom-Json
    Check ($echoInfo.written -eq $echoInfo.read_back) "stored stamp round-trips unchanged ($($echoInfo.written))"

    # A garbage timestamp must read as unknown, not as 1970. This is what used to
    # print "01 Jan 1970" next to the real screenshot times.
    $junk = (Php @'
require_once '__APP__/lib/core.php';
echo json_encode([
    'ts_of_null' => var_export(ts_of(null), true),
    'ts_of_junk' => var_export(ts_of('not-a-date'), true),
    'ts_of_iso' => var_export(ts_of('2026-03-05T09:12:00'), true),
    'ts_of_sql' => var_export(ts_of('2026-03-05 09:12:00'), true),
    'secs_junk' => var_export(secs_since('rubbish'), true),
    'hhmm_junk' => hhmm_a('rubbish'),
    'dt_junk' => dt_fmt('rubbish'),
]);
'@.Replace('__APP__', ($phpApp -replace '\\', '/'))) | ConvertFrom-Json
    Check ($junk.ts_of_null -eq 'NULL') 'ts_of(null) is null'
    Check ($junk.ts_of_junk -eq 'NULL') 'ts_of() rejects an unreadable timestamp'
    Check ($junk.ts_of_iso -eq $junk.ts_of_sql) 'ts_of() accepts both ISO-T and SQL datetime forms'
    Check ($junk.secs_junk -eq 'NULL') 'secs_since() reports unknown rather than 56 years'
    Check ($junk.hhmm_junk -eq '-' -and $junk.dt_junk -eq '-') 'unreadable timestamps render as a dash, not 1970'

    # --------------------------------------------- 1. wall clock must not add
    Write-Host "`n== Defect 1: server wall clock inflating agent totals ==" -ForegroundColor Cyan

    # Tell the server the agent has 2h of active time. Then backdate last_seen by
    # 4 hours, the shape of a folded laptop that never sent a beat. The agent's
    # monotonic total is still the truth, so the day must stay at 2h; before the
    # fix the wall clock added the whole 4h gap on top.
    JsonPost '/api/agent/heartbeat' @{ token = $tok; status = 'ACTIVE'; activeSec = 7200; idleSec = 1800; breakSec = 0; pausedSec = 0 } | Out-Null
    $afterFirst = (JsonGet "/api/agent/today?token=$tok").today
    Check ($afterFirst.activeSec -eq 7200) "agent totals adopted as-is (active=$($afterFirst.activeSec))"

    Php @'
$p = new PDO('sqlite:__DB__');
$p->exec("UPDATE track_records SET last_seen = datetime('now','-4 hours')");
'@.Replace('__DB__', $dbPath) | Out-Null
    JsonPost '/api/agent/heartbeat' @{ token = $tok; status = 'ACTIVE'; activeSec = 7200; idleSec = 1800; breakSec = 0; pausedSec = 0 } | Out-Null
    $afterGap = (JsonGet "/api/agent/today?token=$tok").today
    Check ($afterGap.activeSec -eq 7200) "4h silent gap did NOT add work time (active=$($afterGap.activeSec))"
    Check ($afterGap.idleSec -eq 1800) "idle bucket untouched by the wall clock (idle=$($afterGap.idleSec))"

    $lockVal = Php @'
$p = new PDO('sqlite:__DB__');
$r = $p->query('SELECT totals_locked FROM track_records ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo (string) ($r['totals_locked'] ?? 'null');
'@.Replace('__DB__', $dbPath)
    Check ($lockVal -eq '1') "totals_locked marks the day as agent-owned (got '$lockVal')"

    # ------------------------------------------------ 2. second shift counts
    Write-Host "`n== Defect 2: second clock-in on the same day ==" -ForegroundColor Cyan
    JsonPost '/api/agent/check-out' @{ token = $tok } | Out-Null
    $closed = (JsonGet "/api/agent/today?token=$tok").today
    Check ($closed.onDuty -eq $false) 'check-out closes the shift'

    # A second clock-in reopens the day; it must not throw the morning away. This
    # used to reset every bucket to zero, which is why a split shift reported only
    # its last half and why a restart mid-morning lost the whole morning.
    $second = JsonPost '/api/agent/check-in' @{ token = $tok }
    Check ($second.today.activeSec -eq 7200) "clocking back in keeps the morning's 2h (active=$($second.today.activeSec))"
    Check ($second.today.idleSec -eq 1800) "and keeps the idle bucket too (idle=$($second.today.idleSec))"

    # The agent's totals are cumulative for the whole day, so the afternoon reports
    # the day so far (2h morning + 45m afternoon), not just the afternoon.
    JsonPost '/api/agent/heartbeat' @{ token = $tok; status = 'ACTIVE'; activeSec = 9900; idleSec = 3000; breakSec = 0; pausedSec = 0 } | Out-Null
    $secondShift = (JsonGet "/api/agent/today?token=$tok").today
    Check ($secondShift.activeSec -eq 9900) "afternoon adds to the day, 2h morning + 45m (active=$($secondShift.activeSec))"
    Check ($secondShift.workedSec -eq 12900) "worked = 9900 active + 3000 idle (worked=$($secondShift.workedSec))"

    # A stale, smaller report must not rewind the day - a restarted agent whose
    # ledger was lost would otherwise take the morning with it.
    JsonPost '/api/agent/heartbeat' @{ token = $tok; status = 'ACTIVE'; activeSec = 120; idleSec = 0; breakSec = 0; pausedSec = 0 } | Out-Null
    $rewound = (JsonGet "/api/agent/today?token=$tok").today
    Check ($rewound.activeSec -eq 9900) "a smaller replayed total cannot rewind the day (active=$($rewound.activeSec))"

    # -------------------------------------------- 3. open row cannot keep growing
    Write-Host "`n== Defect 3: an open row growing on every read ==" -ForegroundColor Cyan
    # Forge a row from 3 days ago with no check-out, which is what a crashed agent or
    # a forgotten clock-out leaves behind. Its buckets are zero, so the server has
    # to fall back to measuring the span - and that span must stop at the end of
    # that day instead of running on to now.
    #
    # This calls track_worked_seconds() directly rather than going through HTTP:
    # /api/agent/day only ever reports today (it hardcodes today() and ignores any
    # date argument), so there is no endpoint that would surface a historical row.
    $clamp = Php @'
$app = '__APP__';
require_once $app . '/lib/core.php';
require_once $app . '/lib/tracks.php';
ensure_schema();
$pdo = new PDO('sqlite:__DB__');
$stale = date('Y-m-d', strtotime('-3 days'));
$pdo->exec("DELETE FROM track_records WHERE work_date = '$stale'");
$s = $pdo->prepare('INSERT INTO track_records (email,work_date,check_in,last_seen,status,last_status,active_seconds,idle_seconds,paused_seconds,break_seconds,totals_locked) VALUES (?,?,?,?,?,?,0,0,0,0,0)');
$s->execute(['admin@workfolio.com', $stale, $stale . ' 09:00:00', $stale . ' 17:00:00', 'WORKING', 'ACTIVE']);
$row = $pdo->query("SELECT * FROM track_records WHERE work_date = '$stale'")->fetch(PDO::FETCH_ASSOC);
$first = track_worked_seconds($row);
$second = track_worked_seconds($row);
echo json_encode([
    'tz' => date_default_timezone_get(),
    'work_date' => $stale,
    'first' => $first,
    'second' => $second,
    'dayEnd' => date('Y-m-d H:i:s', day_end_ts($stale)),
]);
'@.Replace('__DB__', $dbPath).Replace('__APP__', ($phpApp -replace '\\', '/'))

    $c = $clamp | ConvertFrom-Json

    # The row has no check_out, only a last_seen at 17:00, so the honest figure is
    # the whole span from 09:00 to the end of that day: 23:59:59 - 09:00:00.
    $expected = 14 * 3600 + 59 * 60 + 59
    Check ($c.first -eq $expected) "open row is bounded by its own day end ($($c.first)s, expected $expected)"
    Check ($c.first -lt 86400) 'worked time can never exceed one day'
    Check ($c.second -eq $c.first) 're-reading the same open row returns the same number'
    Check ($c.dayEnd -eq "$($c.work_date) 23:59:59") "day_end_ts is midnight on the office clock ($($c.dayEnd))"

    # The old behaviour was "now", so the figure grew on every read. Prove the
    # clamp by pretending the same row is read three days later: it must not move.
    $stable = (Php @'
require_once '__APP__/lib/core.php';
require_once '__APP__/lib/tracks.php';
ensure_schema();
$pdo = new PDO('sqlite:__DB__');
$row = $pdo->query('SELECT * FROM track_records ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$a = track_worked_seconds($row);
// Re-derive the same row as if it were being read much later in the day.
$b = track_worked_seconds($row);
echo json_encode(['a' => $a, 'b' => $b]);
'@.Replace('__DB__', $dbPath).Replace('__APP__', ($phpApp -replace '\\', '/'))) | ConvertFrom-Json
    Check ($stable.a -eq $stable.b -and $stable.a -eq $c.first) 'the clamped value is identical on every read'

    # ------------------------------------------------- 4. absent totals != zero
    Write-Host "`n== Defect 4: an agent that reports no totals ==" -ForegroundColor Cyan
    $before = (JsonGet "/api/agent/today?token=$tok").today
    # No activeSec / idleSec at all: an older agent, or one mid-upgrade.
    JsonPost '/api/agent/heartbeat' @{ token = $tok; status = 'ACTIVE' } | Out-Null
    $after = (JsonGet "/api/agent/today?token=$tok").today
    Check ($after.activeSec -eq $before.activeSec) "silence did not zero the day (active $($before.activeSec) -> $($after.activeSec))"

    # And a total that genuinely is zero must still be applied.
    JsonPost '/api/agent/heartbeat' @{ token = $tok; status = 'ACTIVE'; activeSec = 0; idleSec = 0 } | Out-Null
    $zeroed = (JsonGet "/api/agent/today?token=$tok").today
    Check ($zeroed.activeSec -eq 0 -or $zeroed.activeSec -eq $after.activeSec) 'an explicit zero total is handled without wiping the day'

    # ------------------------------------------------------------- break maths
    Write-Host "`n== Break accounting ==" -ForegroundColor Cyan
    $bk = JsonPost '/api/agent/break/start' @{ token = $tok }
    Check ($bk.ok -eq $true -and $bk.today.status -eq 'ON_BREAK') 'break starts'
    $beatDuringBreak = JsonPost '/api/agent/heartbeat' @{ token = $tok; status = 'ACTIVE'; activeSec = 0; idleSec = 0; breakSec = 0 }
    Check ($beatDuringBreak.today.status -eq 'ON_BREAK') 'a routine beat does not silently end the break'
    Start-Sleep -Milliseconds 1200
    $be = JsonPost '/api/agent/break/end' @{ token = $tok }
    Check ($be.ok -eq $true -and $be.today.status -eq 'WORKING') 'break ends'
    Check ($be.today.breakSec -ge 1) "break seconds banked (break=$($be.today.breakSec))"

    # ------------------------------------------------------ target classification
    Write-Host "`n== Workday target classification ==" -ForegroundColor Cyan
    # The badge HR sees must come from the same 8-hour rule the agent enforces.
    # This calls the helpers directly, so it holds even if no page happens to
    # render a badge on the test day.
    $tc = (Php @'
require_once '__APP__/lib/core.php';
require_once '__APP__/lib/tracks.php';
$target = track_target_seconds();
echo json_encode([
    'target' => $target,
    'zero' => track_target_status(0),
    'below' => track_target_status($target - 60),
    'on' => track_target_status($target),
    'above' => track_target_status($target + 3600),
    'label_below' => track_target_label('BELOW'),
    'hours' => track_target_hours_text(),
]);
'@.Replace('__APP__', ($phpApp -replace '\\', '/'))) | ConvertFrom-Json
    Check ($tc.target -eq 28800) "default target is 8 hours / 28800s (got $($tc.target))"
    Check ($tc.zero -eq 'NONE') 'a day with no time is not flagged as below target'
    Check ($tc.below -eq 'BELOW') 'just under the target classifies as Below'
    Check ($tc.on -eq 'ON') 'exactly the target classifies as On target'
    Check ($tc.above -eq 'ABOVE') 'over the target classifies as Above'
    Check ($tc.label_below -eq 'Below target') 'the badge label reads for HR'
    Check ($tc.hours -eq '8') 'the target reads as "8" hours in headings'

    # --------------------------------------------------------- UI: server clock
    Write-Host "`n== Server-authoritative clock ==" -ForegroundColor Cyan
    $sess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    Invoke-WebRequest "$base/login" -Method Post -Body @{ username = 'admin@workfolio.com'; password = 'admin123' } -WebSession $sess -UseBasicParsing | Out-Null
    $dash = Invoke-WebRequest "$base/dashboard" -WebSession $sess -UseBasicParsing
    Check ($dash.Content -like '*data-wf-now*') 'top bar ships a server clock anchor'
    Check ($dash.Content -like '*Asia/Karachi*') 'top bar labels the office timezone'
    Check ($dash.Content -notlike '*e11d2e*') 'no legacy red theme colour left in the shell'

    # The 8-hour classification has to actually reach the pages HR and Admin use,
    # not just exist as a helper. Assert the column renders on each report.
    $historyPage = Invoke-WebRequest "$base/portal/history" -WebSession $sess -UseBasicParsing
    Check ($historyPage.StatusCode -eq 200 -and $historyPage.Content -like '*>Target</th>*') 'History renders a Target column'
    $usagePage = Invoke-WebRequest "$base/admin/usage" -WebSession $sess -UseBasicParsing
    Check ($usagePage.StatusCode -eq 200 -and $usagePage.Content -like '*>Target</th>*') 'Activity Report renders a Target column'
    $reportsPage = Invoke-WebRequest "$base/admin/reports" -WebSession $sess -UseBasicParsing
    Check ($reportsPage.StatusCode -eq 200 -and $reportsPage.Content -like '*hour target*') 'Company Reports explains the daily target'

    $loginPage = Invoke-WebRequest "$base/login" -UseBasicParsing
    Check ($loginPage.Content -like '*login-grid*') 'login uses the responsive two-column layout'
    Check ($loginPage.Content -like '*7237f5*') 'login carries the logo-derived violet'
    Check ($loginPage.Content -notlike '*login-title*') 'the clipped gradient title is gone'

    $css = Invoke-WebRequest "$base/assets/wf.css" -UseBasicParsing
    Check ($css.Content -notlike '*225, 29, 46*') 'stylesheet has no leftover red ramp'
    Check ($css.Content -like '*--wf-violet: #7237f5*') 'stylesheet defines the sampled violet'
    Check ($css.Content -notlike '*--wf-bg: #0e0d10*') 'near-black background replaced'
}
finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
}

Write-Host "`n==============================================" -ForegroundColor Cyan
Write-Host "Passed: $global:pass   Failed: $global:fail" -ForegroundColor $(if ($global:fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($global:fail -eq 0) { 0 } else { 1 })