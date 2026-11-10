<#
    Builds a ready-to-hand-out USB package for the Folio agent.

        powershell -ExecutionPolicy Bypass -File build-usb.ps1
        powershell -ExecutionPolicy Bypass -File build-usb.ps1 -ServerUrl "https://workfolio.mycompany.com"

    What it produces in .\usb:

      WorkFolioAgent.exe   the agent, freshly compiled
      agent.config         pre-filled with the server URL so employees do not
                           have to type it (only their email and password)
      INSTALL.bat          copy + register autostart + launch
      INSTALL-SILENT.bat   same, but without the confirmation prompt
      RUN.bat              try it without installing (from the USB stick)
      UNINSTALL.bat        remove the agent and its autostart entry
      SETUP-GUIDE.html     short bilingual guide
      WorkFolioAgent-USB.zip   all of the above, ready to send

    Everything is rebuilt from source on every run - the icon set first (so a
    branding change reaches the tray icon), then the agent, then the package.
    Use -SkipBuild only when you deliberately want the previous binaries.

    The URL in agent.config is the ONLY thing you should have to change per
    company; rebuild the package for each one.

    Requirements: runs on any Windows machine (the compile step uses the
    .NET Framework's built-in C# compiler - see agent\build-agent.ps1).
#>

[CmdletBinding()]
param(
    [string]$ServerUrl = 'https://folio.biztrack.uk',
    [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }

Write-Host '=== Folio USB package build ===' -ForegroundColor Cyan
Write-Host "Server URL : $ServerUrl"

# ---------------------------------------------------------------- 1. assets + agent ----
$agentBin   = Join-Path $scriptDir 'agent\bin\WorkFolioAgent.exe'
$buildAgent = Join-Path $scriptDir 'agent\build-agent.ps1'
$buildIcons = Join-Path $scriptDir 'build-icons.ps1'

if ($SkipBuild) {
    Write-Host '  -SkipBuild: reusing the existing EXE and icon set.' -ForegroundColor DarkGray
} else {
    # Icons first: build-agent.ps1 embeds app.ico and logo-512.png, so a branding
    # change has to land before the compile or the old logo ships in the EXE.
    & powershell -ExecutionPolicy Bypass -File $buildIcons
    if ($LASTEXITCODE -ne 0) { throw 'build-icons.ps1 failed.' }
    & powershell -ExecutionPolicy Bypass -File $buildAgent
    if ($LASTEXITCODE -ne 0) { throw 'Agent build failed.' }
}
if (-not (Test-Path $agentBin)) { throw "Agent EXE not found: $agentBin" }

# ---------------------------------------------------------------- 2. folder ----
$usb = Join-Path $scriptDir 'usb'
if (Test-Path $usb) { Remove-Item $usb -Recurse -Force }
New-Item -ItemType Directory -Path $usb -Force | Out-Null

Copy-Item $agentBin (Join-Path $usb 'WorkFolioAgent.exe')

# The agent window is HTML rendered by WebView2, so these runtime files must sit
# beside WorkFolioAgent.exe wherever it runs - including straight off the stick.
foreach ($dll in @('Microsoft.Web.WebView2.Core.dll', 'Microsoft.Web.WebView2.WinForms.dll', 'WebView2Loader.dll')) {
    $src = Join-Path $scriptDir ('agent\bin\' + $dll)
    if (-not (Test-Path $src)) { throw "WebView2 runtime file missing: $src (run agent\build-agent.ps1)" }
    Copy-Item $src (Join-Path $usb $dll)
}

# agent.config must match the shape the agent writes on Save(). The JSON is
# hand-built because ConvertTo-Json lowercases the keys and the agent's
# serializer matches them exactly. LastEmail stays empty so each employee types
# their own address, and the file is written without a BOM - Encoding.UTF8
# emits one, which some JSON readers reject. The derived lists are [ScriptIgnore]
# properties on the agent, so they are deliberately absent here too.
$body = '{"ServerUrl":"' + $ServerUrl + '","LastEmail":"","StartWithWindows":false,"StartMinimized":true,"ScreenshotIntervalSeconds":300,"LiveStreamIntervalSeconds":2,"IdleThresholdSeconds":180,"ImageQuality":55,"ImageMaxWidth":1280,"SampleIntervalSeconds":10,"BreakAllowanceMinutes":30,"CaptureScreenshots":true,"TrackMouseKeyboard":true,"TrackAppUsage":true,"TrackWebsites":true,"ExcludedProcesses":"explorer.exe,dwm.exe,ShellExperienceHost,StartMenuExperienceHost,SearchHost,TextInputHost,LockApp.exe,sihost.exe,RuntimeBroker,SystemSettings.exe","BlockedSites":""}'
[System.IO.File]::WriteAllText((Join-Path $usb 'agent.config'), $body, (New-Object System.Text.UTF8Encoding($false)))

# ---------------------------------------------------------------- 3. scripts ----
$dest = '%LOCALAPPDATA%\WorkFolioAgent'

@"
@echo off
setlocal
title Folio - Install on this PC
echo.
echo  Installing Folio on this computer...
echo.
set "DEST=$dest"
if not exist "%DEST%" mkdir "%DEST%"
copy /Y "%~dp0WorkFolioAgent.exe" "%DEST%\WorkFolioAgent.exe" >nul
copy /Y "%~dp0Microsoft.Web.WebView2.Core.dll" "%DEST%\Microsoft.Web.WebView2.Core.dll" >nul
copy /Y "%~dp0Microsoft.Web.WebView2.WinForms.dll" "%DEST%\Microsoft.Web.WebView2.WinForms.dll" >nul
copy /Y "%~dp0WebView2Loader.dll" "%DEST%\WebView2Loader.dll" >nul
if exist "%~dp0agent.config" copy /Y "%~dp0agent.config" "%DEST%\agent.config" >nul
start "" "%DEST%\WorkFolioAgent.exe" --install-autostart
echo  Done. Folio is starting now - look for the clock icon
echo  near the bottom-right corner of the screen.
echo.
echo  If a login window appears, enter your work email and password.
echo  The server address is already set by your company - nothing to change.
echo.
pause
"@ | Set-Content -Path (Join-Path $usb 'INSTALL.bat') -Encoding Ascii

@"
@echo off
setlocal
set "DEST=$dest"
if not exist "%DEST%" mkdir "%DEST%"
copy /Y "%~dp0WorkFolioAgent.exe" "%DEST%\WorkFolioAgent.exe" >nul
copy /Y "%~dp0Microsoft.Web.WebView2.Core.dll" "%DEST%\Microsoft.Web.WebView2.Core.dll" >nul
copy /Y "%~dp0Microsoft.Web.WebView2.WinForms.dll" "%DEST%\Microsoft.Web.WebView2.WinForms.dll" >nul
copy /Y "%~dp0WebView2Loader.dll" "%DEST%\WebView2Loader.dll" >nul
if exist "%~dp0agent.config" copy /Y "%~dp0agent.config" "%DEST%\agent.config" >nul
start "" "%DEST%\WorkFolioAgent.exe" --install-autostart
exit /b 0
"@ | Set-Content -Path (Join-Path $usb 'INSTALL-SILENT.bat') -Encoding Ascii

@"
@echo off
title Folio - Try without installing
start "" "%~dp0WorkFolioAgent.exe"
echo.
echo  Folio is running from this USB stick.
echo  To have it start automatically with Windows, run INSTALL.bat instead.
echo.
pause
"@ | Set-Content -Path (Join-Path $usb 'RUN.bat') -Encoding Ascii

@"
@echo off
title Folio - Remove from this PC
setlocal

rem Stop the agent first, otherwise its own executable cannot be deleted.
taskkill /IM WorkFolioAgent.exe /F >nul 2>&1

reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v WorkFolioAgent /f >nul 2>&1

del /q "$dest\WorkFolioAgent.exe" >nul 2>&1
del /q "$dest\agent.config" >nul 2>&1
del /q "$dest\Microsoft.Web.WebView2.Core.dll" >nul 2>&1
del /q "$dest\Microsoft.Web.WebView2.WinForms.dll" >nul 2>&1
del /q "$dest\WebView2Loader.dll" >nul 2>&1

echo  Folio has been removed from this computer.
echo.
echo  Your shift data has been KEPT in this folder:
echo    $dest
echo.
echo  shift-clock.txt and pending-queue.json can hold hours that have not
echo  reached the server yet, so they are not deleted for you. Delete that
echo  folder by hand once you are sure nothing is pending.
echo.
pause
"@ | Set-Content -Path (Join-Path $usb 'UNINSTALL.bat') -Encoding Ascii

# ---------------------------------------------------------------- 4. guide ----
$guide = Join-Path $usb 'SETUP-GUIDE.html'
$guideBody = @"
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Folio - Setup Guide</title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; max-width: 720px; margin: 0 auto;
         padding: 24px; color: #1e2248; line-height: 1.55; }
  h1 { color: #667eea; }
  h2 { margin-top: 28px; font-size: 1.1rem; }
  .step { border: 1px solid #e2e6f0; border-radius: 12px; padding: 14px 18px; margin: 10px 0;
          background: #fafbff; }
  .step b { color: #764ba2; }
  code { background: #eef0f7; padding: 2px 7px; border-radius: 6px; }
  .warn { background: #fff7e6; border: 1px solid #f5d28a; border-radius: 12px; padding: 12px 16px; }
  .ok   { background: #e8f7ee; border: 1px solid #a7dcb8; border-radius: 12px; padding: 12px 16px; }
</style>
</head>
<body>
<h1>&#128187; Folio agent - install from this USB</h1>
<p>This USB stick contains the Folio employee agent. Installing it takes under a minute.</p>

<h2>For every employee (Urdu / Hindi)</h2>
<div class="step"><b>1.</b> USB ko apne system mein laga kar <code>INSTALL.bat</code> par <b>double-click</b> karein.</div>
<div class="step"><b>2.</b> Yellow "Windows protected your PC" popup aaye to <b>More info</b> &rarr; <b>Run anyway</b> par click karein.</div>
<div class="step"><b>3.</b> Login window kholne par apni <b>work email</b> aur <b>password</b> daalein. (Server address pehle se set hai - sirf email/password daalna hai.)</div>
<div class="step"><b>4.</b> Agent ab taskbar ke paas notification area mein clock logo ke saath chalta hai. Window ko chhupane ke liye title bar par <b>minimize (&minus;)</b> button dabaiye, aur poori screen par lene ke liye title bar par <b>double-click</b> karein. Close karne par agent tray mein chala jaata hai aur wahan se wapas khul jaata hai.</div>
<div class="ok"><b>Ho gaya.</b> Agent Windows ki shuruat par khud chalenge, aur aapki activity server ko report hogi.</div>

<h2>For HR and Admin (report viewing)</h2>
<div class="step">1. Browser mein server address kholiye (<code>$ServerUrl</code>).</div>
<div class="step">2. Edge ya Chrome mein login karke <b>Install app</b> button (neeche right) dabaiye &rarr; reports apni window mein khul jayengi.</div>
<div class="step">3. <b>HR</b> ko employees ki reports dikhti hain; <b>Admin</b> ko HR ki bhi.</div>

<div class="warn"><b>Note:</b> Keystrokes sirf gine jaate hain, save ya bheje nahi jaate. Monitoring ke baare mein staff ko bataana zaroori hai aur local labour law ke mutabiq hona chahiye.</div>
</body>
</html>
"@
[System.IO.File]::WriteAllText($guide, $guideBody, [System.Text.Encoding]::UTF8)

# ---------------------------------------------------------------- 5. zip ----
$zipPath = Join-Path $usb 'WorkFolioAgent-USB.zip'
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipFiles = @('agent.config','INSTALL-SILENT.bat','INSTALL.bat','RUN.bat','SETUP-GUIDE.html','UNINSTALL.bat','WorkFolioAgent.exe','Microsoft.Web.WebView2.Core.dll','Microsoft.Web.WebView2.WinForms.dll','WebView2Loader.dll')
$archive = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
    foreach ($f in $zipFiles) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive, (Join-Path $usb $f), $f, 'Optimal') | Out-Null
    }
} finally {
    $archive.Dispose()
}

# ---------------------------------------------------------------- 6. report ----
Write-Host ''
Write-Host 'USB package ready:' -ForegroundColor Green
Get-ChildItem $usb -File | ForEach-Object {
    Write-Host ("  {0,-24} {1,8} KB" -f $_.Name, [math]::Round($_.Length / 1KB, 1))
}
Write-Host ''
Write-Host "Copy the whole $usb folder onto a USB stick and hand it out." -ForegroundColor Cyan
Write-Host "Or send WorkFolioAgent-USB.zip - it holds the same files." -ForegroundColor Cyan
Write-Host "For a different server, rebuild with:  .\build-usb.ps1 -ServerUrl `"https://your-domain`"" -ForegroundColor DarkGray