<#
    Builds the owner-side phone console (WorkFolioApkManager.exe).

    The same windowed console as WorkFolioManager.exe, but compiled with
    APKMANAGER defined so it lists only Android phones (the Folio Manager APK)
    and hides PC rows. Sign in as an owner, pick a phone, then browse its
    storage, run a shell command, or open the interactive terminal.

        powershell -ExecutionPolicy Bypass -File agent\build-apkmanager.ps1
#>

[CmdletBinding()]
param(
    [string]$OutputPath = ''
)

$ErrorActionPreference = 'Stop'

if (-not $OutputPath) {
    $root = if ($PSScriptRoot) { Split-Path -Parent $PSScriptRoot } else { Get-Location }
    $OutputPath = Join-Path $root 'dist\WorkFolioApkManager.exe'
}

$csc = $null
foreach ($base in @($env:WINDIR, 'C:\Windows')) {
    if (-not $base) { continue }
    foreach ($fx in @('Framework64', 'Framework')) {
        $dir = Join-Path $base "Microsoft.NET\$fx"
        if (-not (Test-Path $dir)) { continue }
        $found = Get-ChildItem -Path $dir -Recurse -Filter 'csc.exe' -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending |
            Where-Object { $_.FullName -match 'v4\.' }
        if ($found) { $csc = $found[0].FullName; break }
    }
    if ($csc) { break }
}
if (-not $csc) { throw 'csc.exe not found. Install the .NET Framework 4.x developer pack or the .NET SDK.' }

# Same source as the main manager; the APKMANAGER symbol switches the title and
# locks the systems list to Android phones.
$source = Join-Path $PSScriptRoot 'manager\ManagerGui.cs'
if (-not (Test-Path $source)) { throw "Manager source not found in $($PSScriptRoot)\manager" }

$outDir = Split-Path -Parent $OutputPath
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }

$arguments = @(
    '/nologo',
    '/target:winexe',
    '/platform:anycpu',
    '/optimize+',
    '/warn:3',
    '/nowarn:1591',
    '/utf8output',
    '/define:APKMANAGER',
    ('/out:"' + $OutputPath + '"')
)
foreach ($ref in @('System.dll', 'System.Core.dll', 'System.Web.Extensions.dll', 'System.Net.Http.dll', 'System.Xml.dll', 'System.Drawing.dll', 'System.Windows.Forms.dll')) {
    $arguments += '/reference:"' + $ref + '"'
}

$ico = Join-Path $PSScriptRoot 'assets\app.ico'
if (Test-Path $ico) { $arguments += ('/win32icon:"' + $ico + '"') }
$arguments += ('"' + $source + '"')

Write-Host '=== WorkFolio ApkManager build ===' -ForegroundColor Cyan
Write-Host "Compiler : $csc" -ForegroundColor DarkGray

$output = & $csc $arguments 2>&1
$code = $LASTEXITCODE
if ($output) { $output | ForEach-Object { Write-Host $_ } }
if ($code -ne 0) { throw "ApkManager compilation failed with exit code $code" }

Write-Host ''
Write-Host ("Built {0}  ({1} KB)" -f $OutputPath, [math]::Round((Get-Item $OutputPath).Length / 1024))
Write-Host ''
Write-Host 'Run WorkFolioApkManager.exe, sign in as an owner, pick a phone, then browse'
Write-Host 'its storage, run a command, or open the interactive terminal.'
exit 0
