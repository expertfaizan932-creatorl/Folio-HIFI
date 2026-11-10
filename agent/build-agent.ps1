<#
    Builds WorkFolioAgent.exe from the C# sources in agent\src.

    Uses the C# compiler that ships with the .NET Framework, which is present on
    every Windows machine - no Visual Studio or .NET SDK installation required.

        powershell -ExecutionPolicy Bypass -File agent\build-agent.ps1

    Output: agent\bin\WorkFolioAgent.exe
#>

[CmdletBinding()]
param(
    [string]$OutputPath,
    [switch]$VerboseBuild
)

$ErrorActionPreference = 'Stop'

# $PSScriptRoot is not populated while param defaults are evaluated, so resolve it here.
$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $OutputPath) { $OutputPath = Join-Path $scriptDir 'bin\WorkFolioAgent.exe' }

function Find-Csc {
    $candidates = @()
    foreach ($base in @($env:WINDIR, 'C:\Windows')) {
        if (-not $base) { continue }
        foreach ($fx in @('Framework64', 'Framework')) {
            $dir = Join-Path $base "Microsoft.NET\$fx"
            if (-not (Test-Path $dir)) { continue }
            $found = Get-ChildItem -Path $dir -Recurse -Filter 'csc.exe' -ErrorAction SilentlyContinue |
                Sort-Object FullName -Descending |
                Where-Object { $_.FullName -match 'v4\.' }
            if ($found) { $candidates += $found[0].FullName }
        }
    }
    if ($candidates.Count -eq 0) {
        throw 'csc.exe not found. Install the .NET Framework 4.x developer pack or the .NET SDK.'
    }
    return $candidates[0]
}

function Find-GacAssembly {
    param([string]$Name)
    $gac = Join-Path $env:WINDIR 'Microsoft.NET\assembly\GAC_MSIL'
    $dir = Join-Path $gac $Name
    if (-not (Test-Path $dir)) { return $null }
    $dll = Get-ChildItem -Path $dir -Recurse -Filter "$Name.dll" -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($dll) { return $dll.FullName }
    return $null
}

Write-Host '=== Folio Agent build ===' -ForegroundColor Cyan

$csc = Find-Csc
Write-Host "Compiler : $csc" -ForegroundColor DarkGray

$srcDir = Join-Path $scriptDir 'src'
$sources = Get-ChildItem -Path $srcDir -Filter '*.cs' -File | Select-Object -ExpandProperty FullName
if (-not $sources) { throw "No .cs files found in $srcDir" }
Write-Host "Sources  : $($sources.Count) file(s)"

# UI Automation is a GAC assembly on .NET Framework, not in the framework folder.
$uiAutomation = @(
    (Find-GacAssembly -Name 'UIAutomationClient'),
    (Find-GacAssembly -Name 'UIAutomationTypes')
) | Where-Object { $_ }

$references = @(
    'System.dll',
    'System.Core.dll',
    'System.Drawing.dll',
    'System.Windows.Forms.dll',
    'System.Web.Extensions.dll',
    'System.Net.Http.dll',
    'System.Xml.dll',
    'System.ServiceProcess.dll',
    'System.Security.dll'
)

# WebView2 (Edge) renders the agent UI from the HTML pages in webui\. The two
# managed assemblies are referenced at compile time and copied next to the EXE
# at runtime; WebView2Loader.dll is the native loader the managed layer P/Invokes
# into. All three must sit beside WorkFolioAgent.exe.
$wv2Dir = Join-Path $scriptDir 'lib\webview2'
$wv2Refs = @(
    (Join-Path $wv2Dir 'Microsoft.Web.WebView2.Core.dll'),
    (Join-Path $wv2Dir 'Microsoft.Web.WebView2.WinForms.dll')
)
foreach ($p in $wv2Refs) {
    if (-not (Test-Path $p)) { throw "WebView2 SDK missing: $p (expected under agent\lib\webview2)" }
}
if (-not (Test-Path (Join-Path $wv2Dir 'WebView2Loader.dll'))) {
    throw "WebView2 native loader missing: $wv2Dir\WebView2Loader.dll"
}

# Framework assemblies are resolved implicitly by csc from its own directory,
# so only the GAC-hosted UI Automation assemblies need explicit validation.
if ($uiAutomation.Count -lt 2) {
    throw 'UIAutomation assemblies not found. Browser URL capture needs them.'
}
Write-Host "UI Auto  : $($uiAutomation -join ', ')" -ForegroundColor DarkGray

$outDir = Split-Path -Parent $OutputPath
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }

# The brand icon must exist before compile: it becomes both the EXE file icon
# (/win32icon) and an embedded resource (/resource) so the tray and login
# screen can show it no matter where the EXE is copied.
$assetDir = Join-Path $scriptDir 'assets'
$ico = Join-Path $assetDir 'app.ico'
if (-not (Test-Path $ico)) {
    Write-Host 'app.ico missing - generating the logo set first.' -ForegroundColor DarkGray
    & powershell -ExecutionPolicy Bypass -File (Join-Path $scriptDir '..\build-icons.ps1')
}
if (-not (Test-Path $ico)) { throw 'app.ico not found - build-icons.ps1 did not produce it.' }

# High-resolution square logo (icon-512.png produced by build-icons.ps1) so the
# sign-in screen renders a crisp logo instead of an upscaled icon frame.
$logoPng = Join-Path $assetDir 'logo-512.png'
if (-not (Test-Path $logoPng)) {
    $icons512 = Join-Path $scriptDir '..\php-app\icons\icon-512.png'
    if (Test-Path $icons512) { Copy-Item $icons512 $logoPng -Force }
}

$arguments = @(
    '/nologo',
    '/target:winexe',
    '/platform:anycpu',
    '/optimize+',
    '/warn:3',
    '/nowarn:1591',
    '/utf8output',
    ('/out:"' + $OutputPath + '"'),
    ('/win32icon:"' + $ico + '"'),
    ('/resource:"' + $ico + '",WorkFolio.Agent.app.ico')
)
if (Test-Path $logoPng) {
    $arguments += ('/resource:"' + $logoPng + '",WorkFolio.Agent.logo')
}
# Declares PerMonitorV2 DPI awareness and Common Controls v6. Without it Windows
# bitmap-stretches the window on a 125%/150% display, which is what made the
# text look clipped and soft.
$manifest = Join-Path $assetDir 'app.manifest'
if (Test-Path $manifest) {
    $arguments += ('/win32manifest:"' + $manifest + '"')
} else {
    Write-Host 'app.manifest missing - building without explicit DPI awareness.' -ForegroundColor Yellow
}

# The two UI pages are embedded so the interface travels with the binary.
$webuiDir = Join-Path $scriptDir 'webui'
$loginHtml = Join-Path $webuiDir 'login.html'
$appHtml = Join-Path $webuiDir 'app.html'
if (-not (Test-Path $loginHtml) -or -not (Test-Path $appHtml)) {
    throw "webui pages missing: expected login.html and app.html under $webuiDir"
}
$arguments += ('/resource:"' + $loginHtml + '",WorkFolio.Agent.webui.login.html')
$arguments += ('/resource:"' + $appHtml + '",WorkFolio.Agent.webui.app.html')

# Embed the WebView2 assemblies so a single WorkFolioAgent.exe is fully
# self-contained. The built-in updater replaces only the EXE, so these files
# have to travel inside it; EmbeddedRuntime.cs loads them from these resources
# when they are not present beside the EXE. They are still also copied next to
# the EXE below, which is what the installer and the FZ FOLIO package use.
foreach ($pair in @(
        @('Microsoft.Web.WebView2.Core.dll', 'WorkFolio.Agent.lib.Microsoft.Web.WebView2.Core.dll'),
        @('Microsoft.Web.WebView2.WinForms.dll', 'WorkFolio.Agent.lib.Microsoft.Web.WebView2.WinForms.dll'),
        @('WebView2Loader.dll', 'WorkFolio.Agent.lib.WebView2Loader.dll'))) {
    $arguments += ('/resource:"' + (Join-Path $wv2Dir $pair[0]) + '",' + $pair[1])
}

foreach ($ref in $references) { $arguments += '/reference:"' + $ref + '"' }
foreach ($ref in $uiAutomation) { $arguments += '/reference:"' + $ref + '"' }
foreach ($ref in $wv2Refs) { $arguments += '/reference:"' + $ref + '"' }
foreach ($src in $sources) { $arguments += '"' + $src + '"' }

if ($VerboseBuild) { Write-Host ($arguments -join ' ') -ForegroundColor DarkGray }

Write-Host 'Compiling...'
$output = & $csc $arguments 2>&1
$exitCode = $LASTEXITCODE

if ($output) { $output | ForEach-Object { Write-Host $_ } }
if ($exitCode -ne 0) {
    throw "Compilation failed with exit code $exitCode"
}

if (-not (Test-Path $OutputPath)) { throw 'Compiler reported success but no exe was produced.' }

# Ship the WebView2 runtime alongside the EXE. The installer and the FZ FOLIO
# package must carry these three files too, or the window cannot open.
foreach ($name in @('Microsoft.Web.WebView2.Core.dll', 'Microsoft.Web.WebView2.WinForms.dll', 'WebView2Loader.dll')) {
    Copy-Item (Join-Path $wv2Dir $name) $outDir -Force
}
Write-Host "WebView2 : copied 3 runtime file(s) to $outDir" -ForegroundColor DarkGray

$exe = Get-Item $OutputPath
Write-Host ''
Write-Host "Built $($exe.FullName)  ($([math]::Round($exe.Length / 1KB, 1)) KB)" -ForegroundColor Green
Write-Host ''
Write-Host 'Next steps:' -ForegroundColor Cyan
Write-Host '  1. Start the Folio server (PHP at https://folio.biztrack.uk, or mvnw.cmd spring-boot:run locally)'
Write-Host '  2. Run WorkFolioAgent.exe and sign in with your work email'
Write-Host '  3. Copy the EXE to employee machines - the server is already configured,'
Write-Host '     employees only enter their work email and password'
Write-Host ''
Write-Host 'Optional: install autostart with  WorkFolioAgent.exe --install-autostart'
