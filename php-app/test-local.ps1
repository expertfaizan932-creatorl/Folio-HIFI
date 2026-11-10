# Folio PHP - local end-to-end test against the SQLite port.
#
# Verify the SAME contract the Spring server had: all 13 agent endpoints, the
# role rules (ADMIN all / HR employees+self / EMPLOYEE self), screenshot IDOR
# closure, ?email= bypass closure and the CSV exports.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File test-local.ps1 [-Php <php.exe>]
# It starts its own `php -S` on port 8899 and cleans up afterwards.

param(
    [string]$Php = 'php'
)

$ErrorActionPreference = 'Stop'
$port = 8899
$base = "http://127.0.0.1:$port"
$phpApp = [System.IO.Path]::GetFullPath($PSScriptRoot)

# ------------------------------------------------------------------ helpers
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
function JsonPost([string]$path, $body, $session = $null) {
    $params = @{
        Uri = $base + $path
        Method = 'Post'
        ContentType = 'application/json'
        Body = ($body | ConvertTo-Json -Compress)
        UseBasicParsing = $true
    }
    if ($session) { $params.WebSession = $session }
    return Invoke-RestMethod @params
}
function JsonGet([string]$path, $session = $null) {
    $params = @{ Uri = $base + $path; UseBasicParsing = $true }
    if ($session) { $params.WebSession = $session }
    return Invoke-RestMethod @params
}
# Follows redirects safely and returns the FINAL status the current caller got,
# or the redirect status when -NoFollow is set (no exceptions thrown).
function HttpStatus([string]$path, $session = $null, [switch]$NoFollow) {
    try {
        $params = @{ Uri = $base + $path; UseBasicParsing = $true }
        if ($session) { $params.WebSession = $session }
        if ($NoFollow) { $params.MaximumRedirection = 0 }
        $r = Invoke-WebRequest @params
        return [int]$r.StatusCode
    } catch {
        $resp = $_.Exception.Response
        if ($resp) { return [int]$resp.StatusCode }
        return 0
    }
}

# ------------------------------------------------------------------ boot PHP
Write-Host "Locating PHP..."
$phpExe = $null
$candidates = @()
if (Get-Command $Php -ErrorAction SilentlyContinue) { $candidates += (Get-Command $Php).Source }
$candidates += @(
    "$env:ProgramFiles\php\php.exe",
    "$env:LOCALAPPDATA\Programs\php\php.exe",
    "$env:USERPROFILE\.php\php.exe",
    "C:\php\php.exe"
)
foreach ($c in $candidates) {
    if ($c -and (Test-Path $c)) { $phpExe = $c; break }
}
if (-not $phpExe) {
    Write-Host "php.exe not found. Pass -Php C:\path\to\php.exe (winget installs to C:\Program Files\php)." -ForegroundColor Red
    exit 1
}
$phpExe = (Resolve-Path $phpExe).Path
Write-Host "Using $phpExe $(& $phpExe -r 'echo PHP_VERSION;')" -ForegroundColor Cyan
if (-not (& $phpExe -m | Select-String 'pdo_sqlite')) {
    Write-Host "pdo_sqlite is not enabled in this PHP build." -ForegroundColor Red
    exit 1
}

Write-Host "Starting PHP dev server on :$port ..."
# Fresh day: drop the SQLite database + screenshots so a run always starts clean.
$wfData = Join-Path $phpApp 'data'
foreach ($f in @('db.sqlite', 'db.sqlite-journal', 'db.sqlite-wal', 'db.sqlite-shm')) {
    Remove-Item (Join-Path $wfData $f) -ErrorAction SilentlyContinue
}
Remove-Item (Join-Path $wfData 'screenshots') -Recurse -ErrorAction SilentlyContinue
Remove-Item (Join-Path $wfData '.last_purge') -ErrorAction SilentlyContinue
$srvOut = Join-Path $env:TEMP 'wf-php-srv.log'
$srvErr = Join-Path $env:TEMP 'wf-php-srv-err.log'
Remove-Item $srvOut, $srvErr -ErrorAction SilentlyContinue
$server = Start-Process -FilePath $phpExe -ArgumentList @('-S', "127.0.0.1:$port", '-t', $phpApp, (Join-Path $phpApp 'index.php')) -WorkingDirectory $phpApp -RedirectStandardOutput $srvOut -RedirectStandardError $srvErr -PassThru -WindowStyle Hidden
try {
    # wait for the server to answer
    $ready = $false
    for ($i = 0; $i -lt 30; $i++) {
        try { $r = Invoke-WebRequest "$base/login" -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -eq 200) { $ready = $true; break } } catch { Start-Sleep -Milliseconds 300 }
    }
    Check $ready 'server up, /login answers 200'
    if (-not $ready) {
        Write-Host "--- php server stderr ---" -ForegroundColor Yellow
        Get-Content $srvErr -ErrorAction SilentlyContinue
        Write-Host "--- php server stdout ---" -ForegroundColor Yellow
        Get-Content $srvOut -ErrorAction SilentlyContinue
        exit 1
    }

    # ------------------------------------------------------------ web login
    Write-Host "`n== Web login ==" -ForegroundColor Cyan
    $adminSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $loginBody = @{ username = 'admin@workfolio.com'; password = 'admin123' }
    Invoke-WebRequest "$base/login" -Method Post -Body $loginBody -WebSession $adminSession -UseBasicParsing | Out-Null
    $dash = Invoke-WebRequest "$base/dashboard" -WebSession $adminSession -UseBasicParsing
    Check ($dash.Content -like '*Welcome*') 'admin can open /dashboard'

    $hrSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    Invoke-WebRequest "$base/login" -Method Post -Body @{ username = 'hr@workfolio.com'; password = 'hr123' } -WebSession $hrSession -UseBasicParsing | Out-Null
    $empSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    Invoke-WebRequest "$base/login" -Method Post -Body @{ username = 'rahul@workfolio.com'; password = 'emp123' } -WebSession $empSession -UseBasicParsing | Out-Null

    # ------------------------------------------------------- agent endpoints
    Write-Host "`n== Agent contract ==" -ForegroundColor Cyan
    $bad = JsonPost '/api/agent/login' @{ email = 'nobody@x.com'; password = 'x' }
    Check (-not $bad.ok -and $bad.error -eq 'Invalid email or password') 'login rejects bad credentials'

    $emp = JsonPost '/api/agent/login' @{ email = 'rahul@workfolio.com'; password = 'emp123' }
    Check ($emp.ok -and $emp.token -and $emp.role -eq 'EMPLOYEE' -and $emp.name) 'agent login returns ok/token/name/role'
    Check ($emp.config.screenshotIntervalSeconds -eq 300) 'login returns config payload'
    Check ($emp.today.ok -eq $null) 'login returns today summary object'
    $t = $emp.token

    $ci = JsonPost '/api/agent/check-in' @{ token = $t }
    Check ($ci.ok -and $ci.today.onDuty -eq $true -and $ci.today.status -eq 'WORKING') 'check-in marks on duty'

    $hb = JsonPost '/api/agent/heartbeat' @{ token = $t; status = 'ACTIVE' }
    Check ($hb.ok -and $hb.config.screenshotIntervalSeconds -eq 300) 'heartbeat returns today + config'

    $tel = JsonPost '/api/agent/telemetry' @{
        token = $t; status = 'ACTIVE'; keystrokes = 150; mouseClicks = 20; mouseScrolls = 5; mouseDistancePx = 1200;
        app = 'chrome.exe'; title = 'Folio - Reports'; host = 'github.com'; category = 'Development';
        hostName = 'PC-RAHUL'; agentVersion = '1.3.0'; ip = '10.0.0.5'
    }
    Check ($tel.ok -and $tel.today.keystrokes -eq 150 -and $tel.today.mouseClicks -eq 20) 'telemetry stores counters (max)'
    Check ($tel.today.currentApp -eq 'chrome.exe' -and $tel.today.agentVersion -eq '1.3.0') 'telemetry refreshes current window + version'

    $u1 = JsonPost '/api/agent/usage' @{ token = $t; process = 'chrome.exe'; displayName = 'Google Chrome'; title = 'Test'; targetKey = 'app:chrome.exe'; targetType = 'APP'; seconds = 65; idle = $false }
    Check ($u1.ok -and $u1.target -eq 'app:chrome.exe' -and -not $u1.skipped) 'usage records app sample'

    $u2 = JsonPost '/api/agent/usage' @{ token = $t; host = 'github.com'; process = 'chrome.exe'; seconds = 40; targetType = 'WEB'; idle = $false }
    Check ($u2.ok -and $u2.target -eq 'web:github.com') 'usage web sample default key from host'

    $ux = JsonPost '/api/agent/usage' @{ token = $t; process = 'explorer.exe'; seconds = 60 }
    Check ($ux.ok -and $ux.skipped -eq 'excluded') 'usage skips excluded processes'

    Start-Sleep -Milliseconds 600
    $us = JsonPost '/api/agent/usage' @{ token = $t; process = 'chrome.exe'; displayName = 'Google Chrome'; targetKey = 'app:chrome.exe'; targetType = 'APP'; seconds = 119 }
    Check ($us.ok) 'usage second sample merges into same row'

    $today = JsonGet "/api/agent/today?token=$t"
    Check ($today.ok -and $today.today.workedSec -ge 0) 'today returns summary + apps/sites'
    Check (@($today.apps).Count -ge 1 -and $today.apps[0].name -eq 'Google Chrome') 'today lists top apps'
    Check (@($today.sites).Count -ge 1 -and $today.sites[0].key -eq 'web:github.com') 'today lists top sites'

    $day = JsonGet "/api/agent/day?token=$t"
    Check ($day.ok -and $day.segments.Count -ge 1 -and $day.segments[0].checkIn -ne '-') 'day returns shift segments'

    $bs = JsonPost '/api/agent/break/start' @{ token = $t }
    Check ($bs.ok -and $bs.today.status -eq 'ON_BREAK') 'break/start flips status'
    Start-Sleep -Milliseconds 1100
    $be = JsonPost '/api/agent/break/end' @{ token = $t }
    Check ($be.ok -and $be.today.status -eq 'WORKING' -and $be.today.breakSec -ge 1) 'break/end banks break seconds'

    # tiny 1x1 JPEG for screenshot tests
    $jpegB64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0HyY7PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q=='
    $shot = JsonPost '/api/agent/screenshot' @{ token = $t; trigger = 'AUTO'; image = $jpegB64; app = 'chrome.exe'; title = 'Test'; host = 'github.com' }
    Check ($shot.ok -and $shot.saved -eq $true -and $shot.id -ge 1) 'screenshot stores capture and returns id'

    $co = JsonPost '/api/agent/check-out' @{ token = $t }
    Check ($co.ok -and $co.today.onDuty -eq $false -and $co.today.workedSec -gt 0) 'check-out closes the shift'

    $noTok = JsonPost '/api/agent/heartbeat' @{ }
    Check (-not $noTok.ok -and $noTok.error -like '*Unauthorized*') 'endpoints reject missing token'
    JsonPost '/api/agent/logout' @{ token = $t } | Out-Null
    $afterLogout = JsonPost '/api/agent/heartbeat' @{ token = $t }
    Check (-not $afterLogout.ok) 'logout revokes the token'

    # ------------------------------------------------------------- monitor API
    Write-Host "`n== Monitor API + roles ==" -ForegroundColor Cyan
    $live = JsonGet '/api/monitor/live' $adminSession
    Check ($live.Count -eq 4) "ADMIN live sees 4 people (got $($live.Count))"
    Check (@($live | Where-Object { $_.email -eq 'rahul@workfolio.com' }).Count -eq 1) 'ADMIN live includes rahul'

    # an admin-owned screenshot for IDOR tests
    $adminAgent = JsonPost '/api/agent/login' @{ email = 'admin@workfolio.com'; password = 'admin123' }
    $adminShot = JsonPost '/api/agent/screenshot' @{ token = $adminAgent.token; trigger = 'AUTO'; image = $jpegB64; app = 'office.exe' }
    $adminShotId = $adminShot.id
    Check ($adminShot.saved -eq $true) 'admin agent stores its own screenshot'

    $shotHead = Invoke-WebRequest "$base/api/monitor/shot/$adminShotId" -WebSession $adminSession -UseBasicParsing
    Check ($shotHead.StatusCode -eq 200 -and $shotHead.Headers.'Content-Type' -like '*image/jpeg*') 'ADMIN can fetch any shot (200 jpeg)'

    $hrLive = JsonGet '/api/monitor/live' $hrSession
    $hrEmails = @($hrLive | ForEach-Object { $_.email })
    Check ($hrLive.Count -eq 3 -and $hrEmails -contains 'rahul@workfolio.com' -and $hrEmails -contains 'hr@workfolio.com' -and -not ($hrEmails -contains 'admin@workfolio.com')) "HR sees employees + self only (got $($hrLive.Count))"

    $hrShot = HttpStatus "/api/monitor/shot/$adminShotId" $hrSession -NoFollow
    Check ($hrShot -eq 403) 'HR blocked from admin shot (IDOR closed)'

    $empShot = Invoke-WebRequest "$base/api/monitor/shot/$adminShotId" -WebSession $empSession -UseBasicParsing
    Check ($empShot.Headers.'Content-Type' -notlike '*image/jpeg*' -and $empShot.Content -like '*Welcome*') 'EMPLOYEE lands on dashboard, not the shot image'

    $hrUsageStatus = HttpStatus '/api/monitor/usage?email=admin@workfolio.com' $hrSession
    Check ($hrUsageStatus -eq 403) 'HR ?email=admin monitor usage blocked'

    $hrRahul = JsonGet '/api/monitor/usage?email=rahul@workfolio.com' $hrSession
    Check ($hrRahul.apps.Count -ge 1 -and $hrRahul.appSeconds -gt 0) 'HR can read rahul usage drill-down'

    # ------------------------------------------------------------- web pages
    Write-Host "`n== Web pages & exports ==" -ForegroundColor Cyan
    $usage = Invoke-WebRequest "$base/admin/usage" -WebSession $adminSession -UseBasicParsing
    Check ($usage.StatusCode -eq 200 -and $usage.Content -like '*Activity Report*') 'ADMIN opens activity report'

    $csv = Invoke-WebRequest "$base/admin/usage.csv" -WebSession $adminSession -UseBasicParsing
    Check ($csv.Content -like '*Date,Employee,Email*' -and $csv.Content -like '*rahul@workfolio.com*') 'admin usage CSV exports rows'

    $tgt = Invoke-WebRequest "$base/admin/usage-targets.csv?email=rahul@workfolio.com" -WebSession $adminSession -UseBasicParsing
    Check ($tgt.Content -like '*Date,Employee,Type,Name*' -and $tgt.Content -like '*Google Chrome*' -and $tgt.Content -like '*chrome.exe*') 'per-target CSV exports rows'
    $cfg = Invoke-WebRequest "$base/admin/agent-config?email=rahul@workfolio.com" -WebSession $adminSession -UseBasicParsing
    Check ($cfg.StatusCode -eq 200 -and $cfg.Content -like '*Screenshot interval*') 'ADMIN opens agent settings'

    $empUsageBlocked = $false
    try {
        $empUsage = Invoke-WebRequest "$base/admin/usage" -WebSession $empSession -UseBasicParsing
        $empUsageBlocked = ($empUsage.Content -notlike '*Activity Report*') -and ($empUsage.Content -like '*Welcome*')
    } catch {
        $empUsageBlocked = $true
    }
    Check $empUsageBlocked 'EMPLOYEE lands on dashboard, not /admin/usage'

    $hrUsersBlocked = $false
    try {
        $hrUsers = Invoke-WebRequest "$base/portal/users" -WebSession $hrSession -UseBasicParsing
        $hrUsersBlocked = ($hrUsers.Content -notlike '*Create user*') -and ($hrUsers.Content -like '*Welcome*')
    } catch {
        $hrUsersBlocked = $true
    }
    Check $hrUsersBlocked 'HR lands on dashboard, not /portal/users (ADMIN only)'

    # ?email= bypass closure on /time
    $hrTimeAdmin = Invoke-WebRequest "$base/time?email=admin@workfolio.com" -WebSession $hrSession -UseBasicParsing
    Check ($hrTimeAdmin.Content -notlike '*Admin Root*' -and $hrTimeAdmin.Content -like '*Riya Sharma*') 'HR /time?email=admin falls back to own sheet'

    $adminEmpTime = Invoke-WebRequest "$base/time?email=rahul@workfolio.com" -WebSession $adminSession -UseBasicParsing
    Check ($adminEmpTime.Content -like '*Rahul Verma*') 'ADMIN can view employee time sheet'

    # history + adjust (HR scoped)
    $hist = Invoke-WebRequest "$base/portal/history?email=rahul@workfolio.com" -WebSession $hrSession -UseBasicParsing
    Check ($hist.StatusCode -eq 200 -and $hist.Content -like '*Records*') 'HR opens history for employee'

    # ------------------------------------------------------------ employees CRUD
    Write-Host "`n== Employees (HR scoping) ==" -ForegroundColor Cyan
    $csrfPage = Invoke-WebRequest "$base/employees/new" -WebSession $hrSession -UseBasicParsing
    $m = [regex]::Match($csrfPage.Content, 'name="_csrf" value="([^"]+)"')
    Check ($m.Success) 'HR can open add-employee form (has CSRF token)'
    $csrf = $m.Groups[1].Value
    $saveBody = @{ _csrf = $csrf; firstName = 'Test'; lastName = 'Emp'; email = 'testemp@workfolio.com'; role = 'ADMIN'; password = 'x123456' }
    Invoke-WebRequest "$base/employees/save" -Method Post -Body $saveBody -WebSession $hrSession -UseBasicParsing -MaximumRedirection 0 -ErrorAction SilentlyContinue | Out-Null
    # HR tried to create an ADMIN - the page must refuse and create nothing.
    $usersPage = Invoke-WebRequest "$base/portal/users" -WebSession $adminSession -UseBasicParsing
    Check ($usersPage.Content -notlike '*testemp@workfolio.com*') 'HR cannot mint an ADMIN account'

    # ------------------------------------------- new user can log in with what was set
    # This is the whole point of the create screen: whatever HR or ADMIN types
    # must be a working login for the person it was created for, whether or not
    # the mail actually left the host.
    Write-Host "`n== Created user can sign in ==" -ForegroundColor Cyan
    $hrCsrf2 = [regex]::Match(
        (Invoke-WebRequest "$base/employees/new" -WebSession $hrSession -UseBasicParsing).Content,
        'name="_csrf" value="([^"]+)"').Groups[1].Value
    $newPw = 'Welcome-2026'
    Invoke-WebRequest "$base/employees/save" -Method Post -WebSession $hrSession -UseBasicParsing `
        -Body @{ _csrf = $hrCsrf2; firstName = 'Ali'; lastName = 'Khan'; email = 'ali@workfolio.com'; role = 'EMPLOYEE'; password = $newPw } | Out-Null

    $newEmpPage = Invoke-WebRequest "$base/employees" -WebSession $hrSession -UseBasicParsing
    Check ($newEmpPage.Content -like '*ali@workfolio.com*') 'HR-created EMPLOYEE appears in the employee list'

    # the flash must tell HR what happened to the mail, either way
    Check ($newEmpPage.Content -like '*No email was sent*' -or $newEmpPage.Content -like '*emailed to*' -or $newEmpPage.Content -like '*could NOT be sent*') `
        'create screen reports the mail outcome (sent / off / failed)'

    $aliSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $aliDash = $null
    try {
        $aliDash = Invoke-WebRequest "$base/login" -Method Post -Body @{ username = 'ali@workfolio.com'; password = $newPw } -WebSession $aliSession -UseBasicParsing
    } catch { }
    Check ($null -ne $aliDash -and $aliDash.Content -like '*Welcome*') 'the created user signs in with the email + password that were set'

    # and the same details work for the desktop agent
    $aliAgent = JsonPost '/api/agent/login' @{ email = 'ali@workfolio.com'; password = $newPw }
    Check ($aliAgent.ok -eq $true -and $aliAgent.role -eq 'EMPLOYEE') 'the agent accepts the same email + password'

    # a wrong password must still be refused
    $aliBad = JsonPost '/api/agent/login' @{ email = 'ali@workfolio.com'; password = 'wrong-one' }
    Check (-not $aliBad.ok) 'a wrong password is still rejected'

    # password reset on the credentials screen re-delivers the new password
    $admUsers = Invoke-WebRequest "$base/portal/users" -WebSession $adminSession -UseBasicParsing
    $admCsrf = [regex]::Match($admUsers.Content, 'name="_csrf" value="([^"]+)"').Groups[1].Value
    $aliId = [regex]::Match($admUsers.Content, 'option value="(\d+)">Ali Khan').Groups[1].Value
    $resetPw = 'Reset-Me-2026'
    Invoke-WebRequest "$base/portal/users/reset" -Method Post -WebSession $adminSession -UseBasicParsing `
        -Body @{ _csrf = $admCsrf; id = $aliId; password = $resetPw } | Out-Null
    $aliAfter = JsonPost '/api/agent/login' @{ email = 'ali@workfolio.com'; password = $resetPw }
    Check ($aliAfter.ok -eq $true) 'after a password reset the new password works'
    $oldPw = JsonPost '/api/agent/login' @{ email = 'ali@workfolio.com'; password = $newPw }
    Check (-not $oldPw.ok) 'the old password stops working after a reset'

    # ------------------------------------------------------------- delete options
    Write-Host "`n== Screenshot + employee delete ==" -ForegroundColor Cyan
    $useCsrf = [regex]::Match(
        (Invoke-WebRequest "$base/admin/usage" -WebSession $adminSession -UseBasicParsing).Content,
        'name="_csrf" value="([^"]+)"').Groups[1].Value
    Check ($useCsrf -ne '') 'activity report exposes a delete control (CSRF token present)'

    # a fresh capture for rahul, so there is something to delete
    $rahulAgent = JsonPost '/api/agent/login' @{ email = 'rahul@workfolio.com'; password = 'emp123' }
    $rahulShot = JsonPost '/api/agent/screenshot' @{ token = $rahulAgent.token; trigger = 'AUTO'; image = $jpegB64; app = 'excel.exe' }
    $rahulShotId = $rahulShot.id
    Check ($rahulShot.saved -eq $true) 'seeded a screenshot to delete'

    # single delete: POST + CSRF, and the row goes
    Invoke-WebRequest "$base/admin/usage/delete-shot" -Method Post -WebSession $adminSession -UseBasicParsing `
        -Body @{ _csrf = $useCsrf; id = $rahulShotId; email = 'rahul@workfolio.com' } | Out-Null
    $goneStatus = HttpStatus "/api/monitor/shot/$rahulShotId" $adminSession -NoFollow
    Check ($goneStatus -ne 200) 'ADMIN can delete a single screenshot'

    # CSRF is enforced: the same delete without a token must not happen
    $rahulShot2 = JsonPost '/api/agent/screenshot' @{ token = $rahulAgent.token; trigger = 'AUTO'; image = $jpegB64; app = 'excel.exe' }
    $noCsrf = HttpStatus "/api/monitor/shot/$($rahulShot2.id)" $adminSession
    $badCsrf = 0
    try {
        $badCsrf = [int](Invoke-WebRequest "$base/admin/usage/delete-shot" -Method Post -WebSession $adminSession -UseBasicParsing `
            -Body @{ id = $rahulShot2.id } -ErrorAction Stop).StatusCode
    } catch { $badCsrf = [int]$_.Exception.Response.StatusCode }
    Check ($badCsrf -eq 419) 'screenshot delete refuses a request with no CSRF token (419)'
    $stillThere = HttpStatus "/api/monitor/shot/$($rahulShot2.id)" $adminSession
    Check ($stillThere -eq 200) 'the screenshot survived the rejected delete'

    # bulk delete: everything of one person in the visible range
    for ($i = 0; $i -lt 3; $i++) {
        JsonPost '/api/agent/screenshot' @{ token = $rahulAgent.token; trigger = 'AUTO'; image = $jpegB64; app = 'excel.exe' } | Out-Null
    }
    $bulkList = Invoke-WebRequest "$base/admin/usage?email=rahul@workfolio.com" -WebSession $adminSession -UseBasicParsing
    $bulkCsrf = [regex]::Match($bulkList.Content, 'name="_csrf" value="([^"]+)"').Groups[1].Value
    Check ($bulkList.Content -like '*Delete all shown*') 'per-employee bulk delete control is rendered'
    Invoke-WebRequest "$base/admin/usage/delete-shots" -Method Post -WebSession $adminSession -UseBasicParsing `
        -Body @{ _csrf = $bulkCsrf; email = 'rahul@workfolio.com' } | Out-Null
    $afterBulk = Invoke-WebRequest "$base/admin/usage?email=rahul@workfolio.com" -WebSession $adminSession -UseBasicParsing
    Check ($afterBulk.Content -like '*No screenshots captured in this range*') 'bulk delete clears the range'

    # HR may not delete somebody outside their scope
    $hrCsrfU = [regex]::Match(
        (Invoke-WebRequest "$base/admin/usage" -WebSession $hrSession -UseBasicParsing).Content,
        'name="_csrf" value="([^"]+)"').Groups[1].Value
    $hrDelete = 0
    try {
        $hrDelete = [int](Invoke-WebRequest "$base/admin/usage/delete-shot" -Method Post -WebSession $hrSession -UseBasicParsing `
            -Body @{ _csrf = $hrCsrfU; id = $adminShotId } -ErrorAction Stop).StatusCode
    } catch { $hrDelete = [int]$_.Exception.Response.StatusCode }
    $adminShotStill = HttpStatus "/api/monitor/shot/$adminShotId" $adminSession
    Check ($adminShotStill -eq 200) 'HR cannot delete a screenshot outside their scope'

    # EMPLOYEE cannot reach the delete endpoint at all
    $empDelete = HttpStatus '/admin/usage/delete-shot' $empSession -NoFollow
    Check ($empDelete -ne 200) 'EMPLOYEE is refused the screenshot delete endpoint'

    # -------------------------------------------------- employee delete + cascade
    $empList = Invoke-WebRequest "$base/employees" -WebSession $adminSession -UseBasicParsing
    $empCsrf = [regex]::Match($empList.Content, 'name="_csrf" value="([^"]+)"').Groups[1].Value
    Check ($empList.Content -like '*ali@workfolio.com*') 'the employee list has the Delete control'

    # a GET must not delete anything - it used to be a plain link
    $aliRow = [regex]::Match($empList.Content, 'action="([^"]*employees/delete/' + $aliId + ')"').Groups[1].Value
    Check ($aliRow -ne '') 'delete is a POST form, not a link'
    $getStatus = HttpStatus "$aliRow" $adminSession -NoFollow
    $aliSurvivesGet = (Invoke-WebRequest "$base/employees" -WebSession $adminSession -UseBasicParsing).Content -like '*ali@workfolio.com*'
    Check ($aliSurvivesGet) 'a plain GET to the delete URL does not delete the employee'

    $delCsrf = [regex]::Match((Invoke-WebRequest "$base/employees" -WebSession $adminSession -UseBasicParsing).Content,
        'name="_csrf" value="([^"]+)"').Groups[1].Value
    Invoke-WebRequest "$base/admin/employees/delete/$aliId" -Method Post -WebSession $adminSession -UseBasicParsing `
        -Body @{ _csrf = $delCsrf } | Out-Null
    $afterDel = Invoke-WebRequest "$base/employees" -WebSession $adminSession -UseBasicParsing
    Check ($afterDel.Content -notlike '*ali@workfolio.com*') 'ADMIN deletes an employee with POST + CSRF'

    $aliLoginAfter = $null
    try { $aliLoginAfter = JsonPost '/api/agent/login' @{ email = 'ali@workfolio.com'; password = $resetPw } } catch { }
    Check ($null -eq $aliLoginAfter -or -not $aliLoginAfter.ok) 'the deleted account can no longer sign in (login row gone)'

    # HR must not be able to delete an ADMIN
    $hrList = Invoke-WebRequest "$base/employees" -WebSession $hrSession -UseBasicParsing
    Check ($hrList.Content -notlike '*admin@workfolio.com*Delete*') 'HR gets no delete control on an ADMIN row'
}
finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
}

Write-Host "`n==============================================" -ForegroundColor Cyan
Write-Host "Passed: $global:pass   Failed: $global:fail" -ForegroundColor $(if ($global:fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($global:fail -eq 0) { 0 } else { 1 })