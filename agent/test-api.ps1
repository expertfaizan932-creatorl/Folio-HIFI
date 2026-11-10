<#
    End-to-end check of the agent API without needing the desktop app.

    Signs in, clocks in, replays a realistic day's telemetry (app usage, website
    usage, mouse/keyboard counters, screenshots) and then verifies that the report
    endpoints return the data back.

        powershell -ExecutionPolicy Bypass -File agent\test-api.ps1
        powershell -ExecutionPolicy Bypass -File agent\test-api.ps1 -BaseUrl http://server:8080 -Email rahul@workfolio.com -Password emp123
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost:8080',
    [string]$Email = 'rahul@workfolio.com',
    [string]$Password = 'emp123'
)

$ErrorActionPreference = 'Stop'
$script:passed = 0
$script:failed = 0

function Invoke-Json {
    param([string]$Method, [string]$Path, $Body)
    $params = @{
        Method      = $Method
        Uri         = ($BaseUrl.TrimEnd('/') + $Path)
        ContentType = 'application/json'
        TimeoutSec  = 30
    }
    if ($null -ne $Body) { $params.Body = ($Body | ConvertTo-Json -Depth 6 -Compress) }
    try {
        $response = Invoke-RestMethod @params
        return $response
    } catch {
        Write-Host "  ! $($_.Exception.Message)" -ForegroundColor Red
        return $null
    }
}

function Test-Case {
    param([string]$Name, [scriptblock]$Body)
    try {
        $result = & $Body
        if ($result -eq $true) {
            Write-Host "  PASS  $Name" -ForegroundColor Green
            $script:passed++
        } else {
            Write-Host "  FAIL  $Name" -ForegroundColor Red
            $script:failed++
        }
    } catch {
        Write-Host "  FAIL  $Name -- $($_.Exception.Message)" -ForegroundColor Red
        $script:failed++
    }
}

<#
    The admin endpoints (live feed, screenshot stream) sit behind Spring Security,
    so they need a browser-style session: fetch /login for the CSRF token, post
    the credentials, and keep the JSESSIONID cookie.
#>
function Connect-AdminSession {
    param([string]$User = 'admin@workfolio.com', [string]$Pass = 'admin123')
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    try {
        $page = Invoke-WebRequest -Uri ($BaseUrl.TrimEnd('/') + '/login') -WebSession $session -UseBasicParsing
        $csrf = [regex]::Match($page.Content, 'name="_csrf"\s+value="([^"]+)"').Groups[1].Value
        if (-not $csrf) { return $null }
        $null = Invoke-WebRequest -Uri ($BaseUrl.TrimEnd('/') + '/login') -Method Post `
            -Body @{ username = $User; password = $Pass; _csrf = $csrf } `
            -WebSession $session -UseBasicParsing -MaximumRedirection 5
        return $session
    } catch {
        Write-Host "  ! admin sign-in failed: $($_.Exception.Message)" -ForegroundColor Red
        return $null
    }
}

Write-Host ''
Write-Host '=== Folio agent API check ===' -ForegroundColor Cyan
Write-Host "Target: $BaseUrl"
Write-Host ''

Write-Host '[1] Connectivity and sign-in'
$login = Invoke-Json -Method POST -Path '/api/agent/login' -Body @{ email = $Email; password = $Password }
Test-Case 'login returns ok' { $null -ne $login -and $login.ok -eq $true }
if (-not $login -or -not $login.ok) {
    Write-Host "  Cannot continue: $((($login | Select-Object -ExpandProperty error -ErrorAction SilentlyContinue)))" -ForegroundColor Red
    Write-Host '  Start the server first: mvnw.cmd spring-boot:run' -ForegroundColor Yellow
    exit 1
}
$token = $login.token
Write-Host "  token acquired for $($login.email)" -ForegroundColor DarkGray

Test-Case 'login returns a monitoring config' { $null -ne $login.config }
Test-Case 'config has a screenshot interval' { $login.config.screenshotIntervalSeconds -gt 0 }
Test-Case 'login returns today summary' { $null -ne $login.today }

Write-Host ''
Write-Host '[2] Attendance'
$checkIn = Invoke-Json -Method POST -Path '/api/agent/check-in' -Body @{ token = $token }
Test-Case 'check-in succeeds' { $null -ne $checkIn -and $checkIn.ok -eq $true }
Test-Case 'check-in records a clock-in time' { $checkIn.today.checkIn -and $checkIn.today.checkIn -ne '-' }
Test-Case 'status becomes WORKING' { $checkIn.today.status -eq 'WORKING' }

$breakStart = Invoke-Json -Method POST -Path '/api/agent/break/start' -Body @{ token = $token }
Test-Case 'break start succeeds' { $null -ne $breakStart -and $breakStart.ok -eq $true }
Test-Case 'status becomes ON_BREAK' { $breakStart.today.status -eq 'ON_BREAK' }

$breakEnd = Invoke-Json -Method POST -Path '/api/agent/break/end' -Body @{ token = $token }
Test-Case 'break end succeeds' { $null -ne $breakEnd -and $breakEnd.ok -eq $true }
Test-Case 'status back to WORKING' { $breakEnd.today.status -eq 'WORKING' }

Write-Host ''
Write-Host '[3] Mouse and keyboard telemetry'
$tel = Invoke-Json -Method POST -Path '/api/agent/telemetry' -Body @{
    token           = $token
    status          = 'ACTIVE'
    keystrokes      = 1543
    mouseClicks     = 287
    mouseScrolls    = 94
    mouseDistancePx = 88210
    app             = 'VS Code'
    title           = 'TrackingService.java - WorkFolio'
    category        = 'APP'
    hostName        = 'WS-042'
    agentVersion    = '1.0.0'
    ip              = '192.168.1.42'
}
Test-Case 'telemetry accepted' { $null -ne $tel -and $tel.ok -eq $true }
Test-Case 'keystrokes stored' { $tel.today.keystrokes -ge 1543 }
Test-Case 'clicks stored' { $tel.today.mouseClicks -ge 287 }
Test-Case 'scrolls stored' { $tel.today.mouseScrolls -ge 94 }
Test-Case 'current app stored' { $tel.today.currentApp -eq 'VS Code' }
Test-Case 'telemetry returns refreshed config' { $null -ne $tel.config }

$idleTel = Invoke-Json -Method POST -Path '/api/agent/telemetry' -Body @{
    token = $token; status = 'IDLE'; app = 'Idle'; category = 'IDLE'
}
Test-Case 'idle status accepted' { $null -ne $idleTel -and $idleTel.ok -eq $true }

Write-Host ''
Write-Host '[4] Application and website usage'
$usage1 = Invoke-Json -Method POST -Path '/api/agent/usage' -Body @{
    token = $token; targetKey = 'app:code.exe'; targetType = 'APP'
    displayName = 'VS Code'; process = 'code.exe'
    title = 'TrackingService.java'; seconds = 3600
}
Test-Case 'app usage sample accepted' { $null -ne $usage1 -and $usage1.ok -eq $true }

$usage2 = Invoke-Json -Method POST -Path '/api/agent/usage' -Body @{
    token = $token; targetKey = 'web:github.com'; targetType = 'WEB'
    displayName = 'github.com'; process = 'chrome.exe'
    host = 'github.com'; title = 'Pull requests'; seconds = 1800
}
Test-Case 'website usage sample accepted' { $null -ne $usage2 -and $usage2.ok -eq $true }

$usage3 = Invoke-Json -Method POST -Path '/api/agent/usage' -Body @{
    token = $token; targetKey = 'web:stackoverflow.com'; targetType = 'WEB'
    displayName = 'stackoverflow.com'; process = 'chrome.exe'
    host = 'stackoverflow.com'; seconds = 900
}
Test-Case 'second website sample accepted' { $null -ne $usage3 -and $usage3.ok -eq $true }

$usageExcluded = Invoke-Json -Method POST -Path '/api/agent/usage' -Body @{
    token = $token; targetKey = 'app:explorer.exe'; targetType = 'APP'
    process = 'explorer.exe'; displayName = 'File Explorer'; seconds = 120
}
Test-Case 'excluded process is skipped' { $usageExcluded.skipped -eq 'excluded' }

Write-Host ''
Write-Host '[5] Screenshots'
# 1x1 black JPEG - enough to prove the pipeline without a desktop capture.
$tinyJpeg = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAAIAAgBAREA/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+iiigD//Z'
$shot = Invoke-Json -Method POST -Path '/api/agent/screenshot' -Body @{
    token = $token; image = $tinyJpeg; trigger = 'AUTO'
    app = 'VS Code'; title = 'TrackingService.java'
}
Test-Case 'scheduled screenshot stored' { $null -ne $shot -and $shot.saved -eq $true }
Test-Case 'screenshot id returned' { $null -ne $shot.id }

if ($shot -and $shot.id) {
    # This endpoint is admin-only, so an authenticated browser session is required.
    $adminSession = Connect-AdminSession
    Test-Case 'admin can sign in to the portal' { $null -ne $adminSession }
    if ($adminSession) {
        try {
            $response = Invoke-WebRequest -Uri "$BaseUrl/api/monitor/shot/$($shot.id)?cache=false" `
                -WebSession $adminSession -UseBasicParsing
            # Windows PowerShell hands back .Content as a string, so read the raw stream.
            $stream = $response.RawContentStream
            $buffer = New-Object byte[] 2
            $null = $stream.Read($buffer, 0, 2)
            $isJpeg = ($buffer[0] -eq 0xFF -and $buffer[1] -eq 0xD8)
            Test-Case 'screenshot streams back as image/jpeg' {
                $isJpeg -and $response.Headers['Content-Type'] -like 'image/*'
            }
        } catch {
            Write-Host "  ! image stream failed: $($_.Exception.Message)" -ForegroundColor Red
            $script:failed++
        }
    }

    try {
        $liveFeed = Invoke-RestMethod -Uri "$BaseUrl/api/monitor/live" -WebSession $adminSession
        $me = $liveFeed | Where-Object { $_.email -eq $Email } | Select-Object -First 1
        Test-Case 'live feed lists this employee' { $null -ne $me }
        Test-Case 'live feed exposes the latest screenshot id' { $me.shotId -ne $null }
        Test-Case 'live feed reports current app' { -not [string]::IsNullOrEmpty($me.currentApp) }
        Test-Case 'live feed reports keyboard activity' { $me.keystrokes -gt 0 }
    } catch {
        Write-Host "  ! live feed failed: $($_.Exception.Message)" -ForegroundColor Red
        $script:failed++
    }
}

$live = Invoke-Json -Method POST -Path '/api/agent/screenshot' -Body @{
    token = $token; image = $tinyJpeg; trigger = 'LIVE'; app = 'Google Chrome'; host = 'github.com'
}
Test-Case 'live frame stored' { $null -ne $live -and $live.saved -eq $true }

Write-Host ''
Write-Host '[6] Reports'
$today = Invoke-Json -Method GET -Path "/api/agent/today?token=$([uri]::EscapeDataString($token))"
Test-Case 'today summary returns apps' { $null -ne $today.apps -and $today.apps.Count -ge 1 }
Test-Case 'today summary returns sites' { $null -ne $today.sites -and $today.sites.Count -ge 1 }
Test-Case 'app count is above zero' { $today.today.distinctApps -ge 1 }
Test-Case 'site count is above zero' { $today.today.distinctSites -ge 1 }
Test-Case 'screenshot count recorded' { $today.today.screenshots -ge 2 }

Write-Host ''
Write-Host '[7] Authorisation'
$bad = Invoke-Json -Method POST -Path '/api/agent/heartbeat' -Body @{ token = 'not-a-real-token'; status = 'ACTIVE' }
Test-Case 'invalid token is rejected' { $null -ne $bad -and $bad.ok -eq $false }

Write-Host ''
Write-Host '[8] Clock out'
$checkOut = Invoke-Json -Method POST -Path '/api/agent/check-out' -Body @{ token = $token }
Test-Case 'check-out succeeds' { $null -ne $checkOut -and $checkOut.ok -eq $true }
Test-Case 'clock-out time recorded' { $checkOut.today.checkOut -and $checkOut.today.checkOut -ne '-' }
Test-Case 'status becomes OFF' { $checkOut.today.status -eq 'OFF' }

Write-Host ''
Write-Host "=== $script:passed passed, $script:failed failed ===" -ForegroundColor $(if ($script:failed -eq 0) { 'Green' } else { 'Red' })
Write-Host ''
Write-Host 'Sign in to the web portal to see this data:'
Write-Host "  $BaseUrl/login  (admin@workfolio.com / admin123)"
Write-Host '  then open Activity Report to see apps, sites, totals and screenshots.'
Write-Host ''
exit $(if ($script:failed -eq 0) { 0 } else { 1 })
