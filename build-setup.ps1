<#
    Builds dist\FolioSetup.exe.

    Runs the agent's own tests first, because shipping an installer for a build
    that fails its own checks would be worse than shipping nothing. Then it
    compiles the agent and hands the result to Inno Setup.

        powershell -ExecutionPolicy Bypass -File build-setup.ps1

    -SkipTests is for when the tests already ran in this session and you only
    want the installer rebuilt.
#>

[CmdletBinding()]
param(
    [switch]$SkipTests,
    [switch]$SkipAgent
)

$ErrorActionPreference = 'Stop'
$root = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
Set-Location $root

function Find-Iscc {
    $candidates = @(
        "$env:ProgramFiles(x86)\Inno Setup 6\ISCC.exe",
        "$env:ProgramFiles\Inno Setup 6\ISCC.exe",
        "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe"
    )
    foreach ($c in $candidates) { if (Test-Path $c) { return $c } }
    $cmd = Get-Command ISCC.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw 'Inno Setup (ISCC.exe) not found. Install it with: winget install JRSoftware.InnoSetup'
}

# ---------------------------------------------------------------- 1. tests
if (-not $SkipTests) {
    Write-Host ''
    Write-Host '=== 1/4  agent tests ===' -ForegroundColor Cyan
    & powershell -ExecutionPolicy Bypass -File (Join-Path $root 'agent\test-agent.ps1')
    if ($LASTEXITCODE -ne 0) {
        throw "Agent tests failed with exit code $LASTEXITCODE. Not building an installer."
    }
} else {
    Write-Host ''
    Write-Host '=== 1/4  agent tests (skipped) ===' -ForegroundColor DarkGray
}

# -------------------------------------------------------------- 2. agent
if (-not $SkipAgent) {
    Write-Host ''
    Write-Host '=== 2/4  build agent ===' -ForegroundColor Cyan
    & powershell -ExecutionPolicy Bypass -File (Join-Path $root 'agent\build-agent.ps1')
    if ($LASTEXITCODE -ne 0) { throw "Agent build failed with exit code $LASTEXITCODE" }
} else {
    Write-Host ''
    Write-Host '=== 2/4  agent build (skipped) ===' -ForegroundColor DarkGray
}

$exe = Join-Path $root 'agent\bin\WorkFolioAgent.exe'
if (-not (Test-Path $exe)) { throw "Agent executable missing: $exe" }

# The installer ships this file, so refuse to build if it is not a blank
# template. A colleague's email baked into a shipped package is the exact bug
# that stopped some employees signing in.
$template = Join-Path $root 'agent\assets\agent.config.template'
if (-not (Test-Path $template)) { throw "Config template missing: $template" }
$cfg = Get-Content $template -Raw | ConvertFrom-Json
if (-not [string]::IsNullOrWhiteSpace($cfg.LastEmail)) {
    throw 'agent.config.template has LastEmail filled in. It must ship blank.'
}
if ($cfg.ServerUrl -ne 'https://folio.biztrack.uk') {
    throw "Config template points at '$($cfg.ServerUrl)' instead of the live server."
}

# ------------------------------------------------------- 3. runtime payload
# Downloaded once and embedded in setup.exe, so the installer works offline and
# an employee never has to fetch anything themselves. Skipped when the build
# machine already has them, because the agent only needs them on machines that
# do not.
Write-Host ''
Write-Host '=== 3/4  runtime payload ===' -ForegroundColor Cyan
$payload = Join-Path $root 'installer\payload'
New-Item -ItemType Directory -Path $payload -Force | Out-Null

# Only the Visual C++ redistributable is bundled. The .NET Framework 4.8 runtime
# is an OS component of Windows 10 1809+ and Windows 11, so it is already present
# on every machine this ships to; carrying its ~140 MB offline installer on every
# USB stick would be waste. The installer detects a missing runtime and points at
# the official download instead.
$runtimes = @(
    @{
        Name = 'vc_redist.x64.exe'
        Url  = 'https://aka.ms/vs/17/release/vc_redist.x64.exe'
        Why  = 'Visual C++ 2015-2022 x64 redistributable'
    }
)

foreach ($rt in $runtimes) {
    $dest = Join-Path $payload $rt.Name
    if (Test-Path $dest) {
        $mb = [math]::Round((Get-Item $dest).Length / 1MB, 1)
        Write-Host ("  have    {0,-22} {1} MB" -f $rt.Name, $mb) -ForegroundColor DarkGray
        continue
    }
    Write-Host ("  fetch   {0,-22} {1}" -f $rt.Name, $rt.Why) -ForegroundColor DarkGray
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri $rt.Url -OutFile $dest -UseBasicParsing -TimeoutSec 300
        $mb = [math]::Round((Get-Item $dest).Length / 1MB, 1)
        Write-Host ("  ok      {0,-22} {1} MB" -f $rt.Name, $mb) -ForegroundColor Green
    }
    catch {
        Write-Host ("  SKIP    {0} could not be downloaded ({1})" -f $rt.Name, $_.Exception.Message) -ForegroundColor Yellow
        Write-Host '          The installer will only offer runtimes it actually carries.' -ForegroundColor Yellow
        Remove-Item $dest -Force -ErrorAction SilentlyContinue
    }
}

# Inno Setup fails on a missing [Files] source, and a [Run] entry pointing at a
# file that was never copied fails the install itself. So a payload that could
# not be downloaded is stripped out of *this build* - both its [Files] and its
# [Run] line, so the installer never tries to launch something it does not have.
#
# This edits a generated copy, never installer\FolioSetup.iss. Rewriting the
# real source here would delete the payload lines for good the first time a
# download failed, and every later build would quietly ship without the
# runtime even once the file was back in installer\payload.
$issPath = Join-Path $root 'installer\FolioSetup.iss'
$buildIss = Join-Path $root 'installer\FolioSetup.build.iss'

$iss = Get-Content $issPath -Raw
foreach ($rt in $runtimes) {
    if (Test-Path (Join-Path $payload $rt.Name)) { continue }
    $name = [regex]::Escape($rt.Name)
    # The literals are escaped whole rather than hand-written, so the backslash
    # in {#PayloadDir}\vc_redist.x64.exe cannot be miscounted into a pattern
    # that silently matches nothing and leaves the broken line in place.
    $filesLine = '(?m)^' + [regex]::Escape('Source: "{#PayloadDir}\') + $name + '".*\r?\n'
    $runLine   = '(?m)^' + [regex]::Escape('Filename: "{tmp}\') + $name + '".*\r?\n'
    foreach ($pattern in @($filesLine, $runLine)) {
        $iss = [regex]::Replace($iss, $pattern, '')
    }
    if ($iss -match [regex]::Escape($rt.Name)) {
        throw "$($rt.Name) was unavailable, but it is still referenced in the installer script. Refusing to build an installer that would fail mid-install."
    }
    Write-Host ("  {0} unavailable - stripped from this build only" -f $rt.Name) -ForegroundColor Yellow
}

# Inno's preprocessor claims any line whose first non-blank character is '#',
# even inside [Code]. A wrapped string such as "+ #13#10 + #13#10 +" landing at
# the start of a line therefore dies with a bare "Unknown preprocessor
# directive" that never mentions the real cause. Catch it here with the line
# and the actual offender named.
$knownDirectives = '^#(define|include|if|ifdef|ifndef|else|endif|for|endfor|sub|endsub|pragma|emit)\b'
$lineNo = 0
foreach ($line in ($iss -split "`r?`n")) {
    $lineNo++
    if ($line -match '^\s*#' -and $line -notmatch $knownDirectives) {
        Write-Warning ("installer\FolioSetup.iss line {0}: a line starts with '#', so Inno Setup will read it as a preprocessor directive and refuse to compile. Move the leading '#' onto the previous line." -f $lineNo)
        Write-Warning ("    offending line: {0}" -f $line.Trim())
        throw 'Installer script has a line that Inno Setup will mistake for a preprocessor directive.'
    }
}

# Written without a BOM: Inno Setup is happier with plain UTF-8 here, and the
# copy has to survive being fed straight back into ISCC.
[IO.File]::WriteAllText($buildIss, $iss, (New-Object System.Text.UTF8Encoding $false))

# ----------------------------------------------------------- 4. installer
Write-Host ''
Write-Host '=== 4/4  compile setup.exe ===' -ForegroundColor Cyan
$iscc = Find-Iscc
Write-Host "Inno Setup : $iscc" -ForegroundColor DarkGray
Write-Host "Script     : $buildIss" -ForegroundColor DarkGray

try {
    & $iscc $buildIss
    if ($LASTEXITCODE -ne 0) { throw "Inno Setup failed with exit code $LASTEXITCODE" }
}
finally {
    Remove-Item $buildIss -Force -ErrorAction SilentlyContinue
}

$setup = Join-Path $root 'dist\FolioSetup.exe'
if (-not (Test-Path $setup)) { throw "Installer was not produced: $setup" }

Write-Host ''
Write-Host "Built $setup  ($([math]::Round((Get-Item $setup).Length / 1MB, 1)) MB)" -ForegroundColor Green
Write-Host "  FileVersion: $((Get-Item $setup).VersionInfo.FileVersion)"
Write-Host ''
Write-Host 'Hand this one file to employees. Double-click it, tick the boxes, done.' -ForegroundColor Green
