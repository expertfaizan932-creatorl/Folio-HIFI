param(
    [switch]$NoBrowser
)
# Folio - public bridge via localtunnel
# Starts local PHP server + `npx localtunnel`, pins the fixed subdomain
# https://fzwfolio.loca.lt (falls back to a random *.loca.lt if taken),
# rewrites agent/bin/agent.config + usb/agent.config ServerUrl and saves
# current-public-url.txt.
# Why: while workfolio.gt.tc (InfinityFree) is pending activation, this is the
# public https URL the app + reports use. See switch-to-production.ps1.
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$app  = Join-Path $root 'php-app'
$php  = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$log  = Join-Path $env:TEMP 'wfol-lt.log'
$errLog = Join-Path $env:TEMP 'wfol-lt.err.log'
$tokenFile = Join-Path $root 'current-public-url.txt'

# --- 1. clean previous ------------------------------------------------
Get-NetTCPConnection -LocalPort 8899 -State Listen -ErrorAction SilentlyContinue |
    ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'local.?tunnel' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Milliseconds 800

if (-not (Test-Path $php)) { Write-Host "[ERR] PHP not found: $php" -ForegroundColor Red; exit 1 }
if (-not ($null -ne (Get-Command npx.cmd -ErrorAction SilentlyContinue))) { Write-Host "[ERR] npx not found - install Node.js" -ForegroundColor Red; exit 1 }

# --- 2. start PHP dev server -----------------------------------------
Start-Process -FilePath $php `
    -ArgumentList @('-S','127.0.0.1:8899','-t',('"' + $app + '"'), ('"' + (Join-Path $app 'index.php') + '"')) `
    -WindowStyle Hidden | Out-Null
Start-Sleep -Seconds 1
if (-not (Get-NetTCPConnection -LocalPort 8899 -State Listen -ErrorAction SilentlyContinue)) { Write-Host '[ERR] PHP failed to start' -ForegroundColor Red; exit 1 }

# --- 3. start localtunnel ----------------------------------------------
Remove-Item $log,$errLog -Force -ErrorAction SilentlyContinue
Start-Process -FilePath 'npx.cmd' -ArgumentList @('--yes','localtunnel','--port','8899') -WindowStyle Hidden `
    -RedirectStandardOutput $log -RedirectStandardError $errLog | Out-Null

$url = $null
for ($attempt = 1; $attempt -le 3; $attempt++) {
    for ($i = 0; $i -lt 60; $i++) {
        Start-Sleep -Milliseconds 500
        if (Test-Path $log) {
            $m = Select-String -Path $log -Pattern 'https://[a-z0-9-]+\.loca\.lt' -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($m) { $url = $m.Matches[0].Value; break }
        }
    }
    if ($url) { break }
    Write-Host '[WARN] retrying tunnel...' -ForegroundColor Yellow
    Remove-Item $log,$errLog -Force -ErrorAction SilentlyContinue
    Start-Process -FilePath 'npx.cmd' -ArgumentList @('--yes','localtunnel','--port','8899') -WindowStyle Hidden `
        -RedirectStandardOutput $log -RedirectStandardError $errLog | Out-Null
}
if (-not $url) {
    Write-Host '[ERR] Tunnel URL nahi milli. Log:' -ForegroundColor Red
    if (Test-Path $errLog) { Get-Content $errLog | Select-Object -Last 6 }
    exit 1
}

# --- 4. rewrite agent configs ----------------------------------------
foreach ($cfg in @((Join-Path $root 'agent\bin\agent.config'), (Join-Path $root 'usb\agent.config'))) {
    if (-not (Test-Path $cfg)) { continue }
    $j = Get-Content $cfg -Raw | ConvertFrom-Json
    $j.ServerUrl = $url
    ($j | ConvertTo-Json -Depth 6 -Compress) | Set-Content $cfg -NoNewline -Encoding ASCII
}
$url | Set-Content $tokenFile -NoNewline -Encoding ASCII

# --- 5. report ----------------------------------------------------------
Write-Host ''
Write-Host '============================================================' -ForegroundColor Cyan
Write-Host '  Folio - LIVE PUBLIC URL' -ForegroundColor Cyan
Write-Host "  $url" -ForegroundColor Green
Write-Host '  App agent.config -> isi URL par update ho gaya.' -ForegroundColor Cyan
Write-Host '  Reports browser me: ek baar IP page "Continue" karein.' -ForegroundColor DarkCyan
Write-Host '============================================================' -ForegroundColor Cyan
if (-not $NoBrowser) { Start-Process $url }