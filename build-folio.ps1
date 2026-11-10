<#
    WS6 - builds the FZ FOLIO distribution package.

    Produces one clean folder, "FZ FOLIO", with a fixed subfolder layout, and
    zips it for handing out. The admin Remote Control console is deliberately
    NOT part of it - it is copied to dist\admin and delivered to the owner
    separately.

        powershell -ExecutionPolicy Bypass -File build-folio.ps1
        powershell -ExecutionPolicy Bypass -File build-folio.ps1 -SkipTests
        powershell -ExecutionPolicy Bypass -File build-folio.ps1 -SkipSetup   (reuse dist\FolioSetup.exe)

    Layout of "FZ FOLIO":

        Application\      the employee app (WorkFolioAgent.exe) and FolioSetup.exe
        BackgroundAgent\  scripts that register/unregister the background agent
        Updater\          update policy the agent reads
        Config\           agent.config with the server address pre-filled
        Scripts\          Install / Install-Silent / Start / Stop / Uninstall
        Resources\        icon, logo, setup guide
        Logs\             runtime log folder (empty until first run)
        README.txt  VERSION.txt  LICENSES.txt

    Output:
        dist\FZ FOLIO\FZ FOLIO\      the package (also the source for the zip)
        usb\FZ FOLIO\FZ FOLIO\       the same package, ready for a USB stick
        usb\FZ FOLIO\FZ FOLIO.zip    single file to send
        dist\FZ FOLIO-BUILD-REPORT.txt
#>
[CmdletBinding()]
param(
    [string]$ServerUrl = 'https://folio.biztrack.uk',
    [switch]$SkipTests,
    [switch]$SkipSetup
)

$ErrorActionPreference = 'Stop'
$root = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
Set-Location $root

function Say($m) { Write-Host "  $m" -ForegroundColor Cyan }
function Ok($m)  { Write-Host "  [ok] $m" -ForegroundColor Green }
function Warn($m){ Write-Host "  [!] $m"  -ForegroundColor Yellow }

# ---------------------------------------------------------------- version ----
$versionFile = Join-Path $root 'version.json'
if (-not (Test-Path $versionFile)) { throw "version file missing: $versionFile" }
$ver = Get-Content $versionFile -Raw | ConvertFrom-Json
$appVersion = $ver.version
$product    = $ver.product
$buildStamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
Say "$product agent $appVersion"

$agentExe = Join-Path $root 'agent\bin\WorkFolioAgent.exe'
$setupExe = Join-Path $root 'dist\FolioSetup.exe'

# ---------------------------------------------------------------- 1. build ---
if (-not $SkipSetup) {
    Write-Host ''
    Write-Host '=== 1/3  tests + agent + installer ===' -ForegroundColor Cyan
    $setupArgs = @()
    if ($SkipTests) { $setupArgs += '-SkipTests' }
    & powershell -ExecutionPolicy Bypass -File (Join-Path $root 'build-setup.ps1') @setupArgs
    if ($LASTEXITCODE -ne 0) { throw "build-setup.ps1 failed with exit code $LASTEXITCODE" }
} else {
    Write-Host ''
    Write-Host '=== 1/3  build (skipped, reusing existing binaries) ===' -ForegroundColor DarkGray
}

if (-not (Test-Path $agentExe)) { throw "agent EXE not found: $agentExe" }
if (-not (Test-Path $setupExe)) { Warn "dist\FolioSetup.exe not found - the package will ship without the one-click installer." }

$installExeVer = (Get-Item $agentExe).VersionInfo.FileVersion
if ($installExeVer -and ($installExeVer -notlike "$appVersion*")) {
    Warn "agent EXE FileVersion is $installExeVer but version.json says $appVersion. Rebuild the agent."
}

# ------------------------------------------------------- 2. assemble folder --
function Build-FolioPackage {
    param([string]$PackageRoot, [string]$AgentExe, [string]$SetupExe, [string]$ServerUrl)

    if (Test-Path $PackageRoot) { Remove-Item $PackageRoot -Recurse -Force }
    $dirs = @('Application','BackgroundAgent','Updater','Config','Scripts','Resources','Logs')
    foreach ($d in $dirs) { New-Item -ItemType Directory -Path (Join-Path $PackageRoot $d) -Force | Out-Null }

    # Application: the app and the one-click installer.
    Copy-Item $AgentExe (Join-Path $PackageRoot 'Application\WorkFolioAgent.exe') -Force
    # WebView2 runtime files the agent window needs, shipped beside the EXE.
    foreach ($dll in @('Microsoft.Web.WebView2.Core.dll', 'Microsoft.Web.WebView2.WinForms.dll', 'WebView2Loader.dll')) {
        $src = Join-Path $root ('agent\bin\' + $dll)
        if (-not (Test-Path $src)) { throw "WebView2 runtime file missing: $src (run agent\build-agent.ps1)" }
        Copy-Item $src (Join-Path $PackageRoot ('Application\' + $dll)) -Force
    }
    if (Test-Path $SetupExe) { Copy-Item $SetupExe (Join-Path $PackageRoot 'Application\FolioSetup.exe') -Force }

    # Config: server pre-filled, email blank so nobody else's address appears.
    $body = '{"ServerUrl":"' + $ServerUrl + '","LastEmail":"","StartWithWindows":false,"StartMinimized":true,"ScreenshotIntervalSeconds":300,"LiveStreamIntervalSeconds":2,"IdleThresholdSeconds":180,"ImageQuality":55,"ImageMaxWidth":1280,"SampleIntervalSeconds":10,"BreakAllowanceMinutes":30,"CaptureScreenshots":true,"TrackMouseKeyboard":true,"TrackAppUsage":true,"TrackWebsites":true,"ExcludedProcesses":"explorer.exe,dwm.exe,ShellExperienceHost,StartMenuExperienceHost,SearchHost,TextInputHost,LockApp.exe,sihost.exe,RuntimeBroker,SystemSettings.exe","BlockedSites":""}'
    [System.IO.File]::WriteAllText((Join-Path $PackageRoot 'Config\agent.config'), $body, (New-Object System.Text.UTF8Encoding($false)))

    # Resources.
    Copy-Item (Join-Path $root 'agent\assets\app.ico') (Join-Path $PackageRoot 'Resources\WorkFolio.ico') -Force
    if (Test-Path (Join-Path $root 'agent\assets\logo-512.png')) {
        Copy-Item (Join-Path $root 'agent\assets\logo-512.png') (Join-Path $PackageRoot 'Resources\logo-512.png') -Force
    }

    $installDir = '%LOCALAPPDATA%\Programs\Folio Agent'

    # Scripts. Install runs the wizard when present, otherwise copies by hand.
    @"
@echo off
title FZ FOLIO - Install
echo.
echo  Installing FZ FOLIO on this computer...
echo.
if exist "%~dp0..\Application\FolioSetup.exe" (
    start "" /wait "%~dp0..\Application\FolioSetup.exe"
    goto :done
)
set "DEST=$installDir"
if not exist "%DEST%" mkdir "%DEST%"
copy /Y "%~dp0..\Application\WorkFolioAgent.exe" "%DEST%\WorkFolioAgent.exe" >nul
copy /Y "%~dp0..\Application\Microsoft.Web.WebView2.Core.dll" "%DEST%\Microsoft.Web.WebView2.Core.dll" >nul
copy /Y "%~dp0..\Application\Microsoft.Web.WebView2.WinForms.dll" "%DEST%\Microsoft.Web.WebView2.WinForms.dll" >nul
copy /Y "%~dp0..\Application\WebView2Loader.dll" "%DEST%\WebView2Loader.dll" >nul
if exist "%~dp0..\Config\agent.config" copy /Y "%~dp0..\Config\agent.config" "%DEST%\agent.config" >nul
start "" "%DEST%\WorkFolioAgent.exe" --install-autostart
:done
echo.
echo  Done. FZ FOLIO is starting - look for the clock icon near the clock.
echo.
pause
"@ | Set-Content -Path (Join-Path $PackageRoot 'Scripts\Install.bat') -Encoding Ascii

    @"
@echo off
setlocal
if exist "%~dp0..\Application\FolioSetup.exe" (
    "%~dp0..\Application\FolioSetup.exe" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART /TASKS="autostart,desktopicon"
    exit /b 0
)
set "DEST=$installDir"
if not exist "%DEST%" mkdir "%DEST%"
copy /Y "%~dp0..\Application\WorkFolioAgent.exe" "%DEST%\WorkFolioAgent.exe" >nul
copy /Y "%~dp0..\Application\Microsoft.Web.WebView2.Core.dll" "%DEST%\Microsoft.Web.WebView2.Core.dll" >nul
copy /Y "%~dp0..\Application\Microsoft.Web.WebView2.WinForms.dll" "%DEST%\Microsoft.Web.WebView2.WinForms.dll" >nul
copy /Y "%~dp0..\Application\WebView2Loader.dll" "%DEST%\WebView2Loader.dll" >nul
if exist "%~dp0..\Config\agent.config" copy /Y "%~dp0..\Config\agent.config" "%DEST%\agent.config" >nul
start "" "%DEST%\WorkFolioAgent.exe" --install-autostart
exit /b 0
"@ | Set-Content -Path (Join-Path $PackageRoot 'Scripts\Install-Silent.bat') -Encoding Ascii

    @"
@echo off
start "" "$installDir\WorkFolioAgent.exe"
"@ | Set-Content -Path (Join-Path $PackageRoot 'Scripts\Start.bat') -Encoding Ascii

    @"
@echo off
taskkill /IM WorkFolioAgent.exe /F >nul 2>&1
echo  FZ FOLIO agent stopped.
"@ | Set-Content -Path (Join-Path $PackageRoot 'Scripts\Stop.bat') -Encoding Ascii

    @"
@echo off
title FZ FOLIO - Remove from this PC
sc query FolioAgentService >nul 2>&1 && (
    echo  Stopping background service...
    sc stop FolioAgentService >nul 2>&1
    sc delete FolioAgentService >nul 2>&1
)
if exist "$installDir\unins000.exe" (
    "$installDir\unins000.exe" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART
) else (
    taskkill /IM WorkFolioAgent.exe /F >nul 2>&1
    reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v WorkFolioAgent /f >nul 2>&1
    del /q "$installDir\WorkFolioAgent.exe" >nul 2>&1
    del /q "$installDir\agent.config" >nul 2>&1
    del /q "$installDir\Microsoft.Web.WebView2.Core.dll" >nul 2>&1
    del /q "$installDir\Microsoft.Web.WebView2.WinForms.dll" >nul 2>&1
    del /q "$installDir\WebView2Loader.dll" >nul 2>&1
)
echo.
echo  FZ FOLIO removed. Your shift data was KEPT in %LOCALAPPDATA%\WorkFolioAgent.
echo.
pause
"@ | Set-Content -Path (Join-Path $PackageRoot 'Scripts\Uninstall.bat') -Encoding Ascii

    # BackgroundAgent: register / unregister the background agent.
    @"
# Registers the FZ FOLIO agent to start with Windows (HKCU Run via the agent's
# own flag, so the entry cannot drift from what the app itself writes).
param([string]`$InstallDir = "`$env:LOCALAPPDATA\Programs\Folio Agent")
`$exe = Join-Path `$InstallDir 'WorkFolioAgent.exe'
if (-not (Test-Path `$exe)) { Write-Host "Agent not installed at `$InstallDir" -ForegroundColor Red; exit 1 }
Start-Process `$exe -ArgumentList '--install-autostart' -Wait
Write-Host 'Background agent registered to start with Windows.' -ForegroundColor Green
"@ | Set-Content -Path (Join-Path $PackageRoot 'BackgroundAgent\register-autostart.ps1') -Encoding UTF8

    @"
# Removes the FZ FOLIO agent from Windows startup.
param([string]`$InstallDir = "`$env:LOCALAPPDATA\Programs\Folio Agent")
`$exe = Join-Path `$InstallDir 'WorkFolioAgent.exe'
if (Test-Path `$exe) { Start-Process `$exe -ArgumentList '--uninstall-autostart' -Wait }
reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v WorkFolioAgent /f >`$null 2>&1
Write-Host 'Background agent removed from startup.' -ForegroundColor Green
"@ | Set-Content -Path (Join-Path $PackageRoot 'BackgroundAgent\unregister-autostart.ps1') -Encoding UTF8

    @"
FZ FOLIO - Background Agent
===========================

The monitoring process is ..\Application\WorkFolioAgent.exe. It runs in the
notification area and keeps recording whether or not its window is open. It
starts at sign-in through the per-user Run entry the installer sets.

There are two ways to keep it always-on:

  1. Per-user (default, no admin needed)
     register-autostart.ps1 / unregister-autostart.ps1
     The agent starts when the user signs in.

  2. Machine-wide Windows service (optional, needs admin)
     install-service.ps1 / uninstall-service.ps1
     Installs FolioAgentService, which itself launches the agent into whichever
     user is signed in and relaunches it if it is closed. Use this when you want
     the agent to survive the employee closing it.

This is the same binary in both cases; the service just supervises it and talks
to it over a local control pipe. Install order does not matter.
"@ | Set-Content -Path (Join-Path $PackageRoot 'BackgroundAgent\README.txt') -Encoding UTF8

    @"
# Installs the machine-wide supervisor service. Needs an elevated prompt.
# The agent binary must already be installed (run Scripts\Install.bat first).
$ErrorActionPreference = 'Stop'
`$candidates = @(
    (Join-Path `$PSScriptRoot '..\Application\WorkFolioAgent.exe'),
    (Join-Path `$env:LOCALAPPDATA 'Programs\Folio Agent\WorkFolioAgent.exe')
)
`$exe = `$candidates | Where-Object { Test-Path `$_ } | Select-Object -First 1
if (-not `$exe) { Write-Host 'WorkFolioAgent.exe not found. Install the app first.' -ForegroundColor Red; exit 1 }

`$id = [Security.Principal.WindowsIdentity]::GetCurrent()
`$admin = (New-Object Security.Principal.WindowsPrincipal(`$id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not `$admin) { Write-Host 'Run this script as Administrator.' -ForegroundColor Red; exit 1 }

& `$exe --install-service
Write-Host 'FolioAgentService installed.' -ForegroundColor Green
"@ | Set-Content -Path (Join-Path $PackageRoot 'BackgroundAgent\install-service.ps1') -Encoding UTF8

    @"
# Removes the machine-wide supervisor service. Needs an elevated prompt. Keeps
# the agent itself and all recorded data.
$ErrorActionPreference = 'Stop'
`$id = [Security.Principal.WindowsIdentity]::GetCurrent()
`$admin = (New-Object Security.Principal.WindowsPrincipal(`$id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not `$admin) { Write-Host 'Run this script as Administrator.' -ForegroundColor Red; exit 1 }

`$exe = Join-Path `$env:LOCALAPPDATA 'Programs\Folio Agent\WorkFolioAgent.exe'
if (Test-Path `$exe) { & `$exe --uninstall-service } else { sc.exe stop FolioAgentService; sc.exe delete FolioAgentService }
Write-Host 'FolioAgentService removed.' -ForegroundColor Green
"@ | Set-Content -Path (Join-Path $PackageRoot 'BackgroundAgent\uninstall-service.ps1') -Encoding UTF8

    # Updater policy the agent reads.
    @"
{
  "channel": "$($ver.channel)",
  "checkUrl": "$ServerUrl/api/agent/update",
  "checkOnStart": true,
  "requireSignature": false,
  "allowDowngrade": false
}
"@ | Set-Content -Path (Join-Path $PackageRoot 'Updater\update.config') -Encoding UTF8

    @"
FZ FOLIO - Updater
==================

update.config is the policy the agent reads when it checks for a new version:

  channel            stable
  checkUrl           where the agent asks for the latest version
  checkOnStart       check once at launch
  requireSignature   set true once the build is code-signed
  allowDowngrade     never install an older version

The checkUrl uses the same server the agent reports to. Publishing an update means
putting the new EXE and its version on the server; the agent pulls it, verifies it
and swaps itself in.
"@ | Set-Content -Path (Join-Path $PackageRoot 'Updater\README.txt') -Encoding UTF8

    # Setup guide.
    $guide = @"
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FZ FOLIO - Setup</title>
<style>
 body{font-family:'Segoe UI',Arial,sans-serif;max-width:720px;margin:0 auto;padding:24px;color:#1e2248;line-height:1.55}
 h1{color:#667eea}h2{margin-top:28px;font-size:1.1rem}
 .step{border:1px solid #e2e6f0;border-radius:12px;padding:14px 18px;margin:10px 0;background:#fafbff}
 .step b{color:#764ba2}code{background:#eef0f7;padding:2px 7px;border-radius:6px}
 .warn{background:#fff7e6;border:1px solid #f5d28a;border-radius:12px;padding:12px 16px}
 .ok{background:#e8f7ee;border:1px solid #a7dcb8;border-radius:12px;padding:12px 16px}
</style></head><body>
<h1>FZ FOLIO - install</h1>
<p>This folder contains the FZ FOLIO employee agent. Installing it takes under a minute.</p>
<h2>Install (Urdu / Hindi)</h2>
<div class="step"><b>1.</b> <code>Scripts\Install.bat</code> par <b>double-click</b> karein (ya <code>Application\FolioSetup.exe</code>).</div>
<div class="step"><b>2.</b> Yellow "Windows protected your PC" popup aaye to <b>More info</b> &rarr; <b>Run anyway</b>.</div>
<div class="step"><b>3.</b> Login window par apni <b>work email</b> aur <b>password</b> daalein. Server address pehle se set hai.</div>
<div class="step"><b>4.</b> Agent notification area mein clock logo ke saath chalta hai. Band karne par tray mein chala jata hai.</div>
<div class="ok"><b>Ho gaya.</b> Agent Windows shuru hone par khud chalega aur activity server ko report karega.</div>
<h2>For HR and Admin</h2>
<div class="step">Browser mein <code>$ServerUrl</code> kholiye aur login karein. HR ko employees ki reports, Admin ko HR ki bhi.</div>
<div class="warn"><b>Note:</b> Keystrokes sirf gine jaate hain, save nahi. Monitoring ke baare mein staff ko batana zaroori hai.</div>
</body></html>
"@
    [System.IO.File]::WriteAllText((Join-Path $PackageRoot 'Resources\SETUP-GUIDE.html'), $guide, [System.Text.Encoding]::UTF8)

    # Logs folder placeholder.
    [System.IO.File]::WriteAllText((Join-Path $PackageRoot 'Logs\.keep'), 'Runtime logs are written here.', (New-Object System.Text.UTF8Encoding($false)))

    # Root README.
    @"
$product - employee package   v$appVersion
$('=' * 52)

WHAT THIS IS
    The FZ FOLIO desktop agent that records working time, plus a one-click
    installer. Everything an employee needs is inside this folder.

FOLDER LAYOUT
    Application\      WorkFolioAgent.exe (the app) and FolioSetup.exe (installer)
    BackgroundAgent\  autostart scripts, plus an optional Windows service
    Updater\          update policy the agent reads
    Config\           agent.config - server address already filled in
    Scripts\          Install, Install-Silent, Start, Stop, Uninstall
    Resources\        icon, logo and this guide
    Logs\             runtime log folder

INSTALL (one click)
    Double-click Scripts\Install.bat, or run Application\FolioSetup.exe.
    For a silent rollout: Scripts\Install-Silent.bat

FIRST SIGN-IN
    Enter the work email and password from your manager. The server address is
    already set - there is nothing else to type.

REQUIREMENTS
    Windows 10 (1809) or Windows 11, 64-bit. .NET Framework 4.8 ships with these
    versions of Windows, so nothing else needs installing.

SUPPORT
    Reports: open $ServerUrl in a browser.
    Company: Hifi Marketing.

This package does NOT contain the owner-side Remote Control console.
"@ | Set-Content -Path (Join-Path $PackageRoot 'README.txt') -Encoding UTF8

    # VERSION.
    $exeHash = (Get-FileHash (Join-Path $PackageRoot 'Application\WorkFolioAgent.exe') -Algorithm SHA256).Hash
    @"
$product
Component : FolioAgent
Version   : $appVersion
Channel   : $($ver.channel)
Built     : $buildStamp
Server    : $ServerUrl

WorkFolioAgent.exe SHA-256:
$exeHash
"@ | Set-Content -Path (Join-Path $PackageRoot 'VERSION.txt') -Encoding UTF8

    # LICENSES.
    $eula = Get-Content (Join-Path $root 'installer\LICENSE.txt') -Raw
    @"
$eula

------------------------------------------------------------
THIRD-PARTY COMPONENTS
------------------------------------------------------------
Microsoft Visual C++ 2015-2022 Redistributable (x64)
    Bundled inside Application\FolioSetup.exe and installed only when a machine
    is missing it. (c) Microsoft Corporation. Distributed under Microsoft's
    redistribution terms.

Microsoft .NET Framework 4.8
    An operating-system component of Windows 10 1809+ and Windows 11. Not
    bundled; the installer points at Microsoft's official download if absent.

Microsoft Edge WebView2 SDK (Microsoft.Web.WebView2.Core,
Microsoft.Web.WebView2.WinForms, WebView2Loader)
    Shipped in Application\ beside WorkFolioAgent.exe and used to render the
    agent's window. (c) Microsoft Corporation. Distributed under Microsoft's
    redistribution terms. The WebView2 Runtime itself is part of Microsoft Edge
    and is present on current Windows 10 and Windows 11 machines.
"@ | Set-Content -Path (Join-Path $PackageRoot 'LICENSES.txt') -Encoding UTF8
}

# ------------------------------------------------------- 3. dist + usb -------
$distFolio = Join-Path $root 'dist\FZ FOLIO'
if (Test-Path $distFolio) { Remove-Item $distFolio -Recurse -Force }
Build-FolioPackage -PackageRoot $distFolio -AgentExe $agentExe -SetupExe $setupExe -ServerUrl $ServerUrl
Ok "assembled $distFolio"

# Admin tool kept out of the employee package.
$manager = Join-Path $root 'dist\WorkFolioManager.exe'
if (Test-Path $manager) {
    $adminDir = Join-Path $root 'dist\admin'
    if (-not (Test-Path $adminDir)) { New-Item -ItemType Directory -Path $adminDir -Force | Out-Null }
    Copy-Item $manager (Join-Path $adminDir 'WorkFolioManager.exe') -Force
    Ok "admin console kept separate at dist\admin\WorkFolioManager.exe"
}

# USB copy plus a single zip to send.
$usbParent = Join-Path $root 'usb'
if (Test-Path $usbParent) { Remove-Item $usbParent -Recurse -Force }
$usbFolio = Join-Path $usbParent 'FZ FOLIO'
Build-FolioPackage -PackageRoot $usbFolio -AgentExe $agentExe -SetupExe $setupExe -ServerUrl $ServerUrl

$zip = Join-Path $usbParent 'FZ FOLIO.zip'
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($usbFolio, $zip, [System.IO.Compression.CompressionLevel]::Optimal, $true)
Ok "zipped $zip"

# ---------------------------------------------------------------- report -----
$report = Join-Path $root 'dist\FZ FOLIO-BUILD-REPORT.txt'
$lines = @()
$lines += "$product build report"
$lines += "Generated : $buildStamp"
$lines += "Version   : $appVersion"
$lines += "Server    : $ServerUrl"
$lines += ""
$lines += "Package tree (dist\FZ FOLIO):"
Get-ChildItem $distFolio -Recurse | Sort-Object FullName | ForEach-Object {
    $rel = $_.FullName.Substring($distFolio.Length).TrimStart('\')
    if ($_.PSIsContainer) { $lines += "  [dir ] $rel" }
    else { $lines += ("  [file] {0,-46} {1,9:N0} KB" -f $rel, ($_.Length / 1KB)) }
}
$lines += ""
$lines += "Zip : usb\FZ FOLIO.zip  ($([math]::Round((Get-Item $zip).Length / 1MB, 2)) MB)"
$lines += "Admin console (NOT in package): dist\admin\WorkFolioManager.exe"
$lines | Set-Content -Path $report -Encoding UTF8

Write-Host ''
Write-Host "FZ FOLIO package ready:  dist\FZ FOLIO" -ForegroundColor Green
Write-Host "USB / share this          usb\FZ FOLIO.zip" -ForegroundColor Green
Write-Host "Build report              $report" -ForegroundColor Green
