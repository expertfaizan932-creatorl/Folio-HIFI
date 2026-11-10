<#
    Runs the agent's C# unit tests.

    The ShiftClock tests are compiled against the real ShiftClock.cs rather than a
    copy, so a change that breaks the ledger fails here instead of in production.

        powershell -ExecutionPolicy Bypass -File agent\test-agent.ps1
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }

function Find-Csc {
    foreach ($base in @($env:WINDIR, 'C:\Windows')) {
        if (-not $base) { continue }
        foreach ($fx in @('Framework64', 'Framework')) {
            $dir = Join-Path $base "Microsoft.NET\$fx"
            if (-not (Test-Path $dir)) { continue }
            $found = Get-ChildItem -Path $dir -Recurse -Filter 'csc.exe' -ErrorAction SilentlyContinue |
                Sort-Object FullName -Descending |
                Where-Object { $_.FullName -match 'v4\.' }
            if ($found) { return $found[0].FullName }
        }
    }
    throw 'csc.exe not found. Install the .NET Framework 4.x developer pack or the .NET SDK.'
}

$csc = Find-Csc
$srcDir = Join-Path $scriptDir 'src'
$testDir = Join-Path $scriptDir 'test'
$testFiles = @(
    (Join-Path $testDir 'ShiftClockTest.cs'),
    (Join-Path $testDir 'ProductivityTargetTest.cs'),
    (Join-Path $testDir 'ClockSyncTest.cs'),
    (Join-Path $testDir 'ConnectionStateTest.cs'),
    (Join-Path $testDir 'AgentSettingsTest.cs'),
    (Join-Path $testDir 'UpdateVerifierTest.cs'),
    (Join-Path $testDir 'CommandLedgerTest.cs'),
    (Join-Path $testDir 'IpcTest.cs'),
    (Join-Path $testDir 'RemoteFsOpsTest.cs')
)
foreach ($required in $testFiles) {
    if (-not (Test-Path $required)) { throw "Test file not found: $required" }
}
if (Test-Path (Join-Path $testDir 'PowerMonitorTest.cs')) { $testFiles += Join-Path $testDir 'PowerMonitorTest.cs' }
# The UI checks build the real forms, so the whole agent has to be compiled in.
# UiTestRunner supplies Main because Program.cs has the shipping entry point.
if (Test-Path (Join-Path $testDir 'UiLayoutTest.cs')) { $testFiles += Join-Path $testDir 'UiLayoutTest.cs' }
if (Test-Path (Join-Path $testDir 'UiTestRunner.cs')) { $testFiles += Join-Path $testDir 'UiTestRunner.cs' }

$agentSources = @(Get-ChildItem -Path $srcDir -Filter '*.cs' -File | Sort-Object Name)
$iconPath = Join-Path $scriptDir 'assets\app.ico'

$outDir = Join-Path $env:TEMP ('wf-agent-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $outDir -Force | Out-Null
$exe = Join-Path $outDir 'AgentTest.exe'

Write-Host '=== Folio agent unit tests ===' -ForegroundColor Cyan
Write-Host "Compiler : $csc" -ForegroundColor DarkGray

$references = @('/reference:System.dll', '/reference:System.Core.dll',
                '/reference:System.Drawing.dll', '/reference:System.Windows.Forms.dll',
                '/reference:System.ServiceProcess.dll', '/reference:System.Security.dll')

# The UI is HTML hosted in WebView2, so the test build needs the same managed
# assemblies the shipping build references.
$wv2Dir = Join-Path $scriptDir 'lib\webview2'
foreach ($dll in @('Microsoft.Web.WebView2.Core.dll', 'Microsoft.Web.WebView2.WinForms.dll')) {
    $p = Join-Path $wv2Dir $dll
    if (-not (Test-Path $p)) { throw "WebView2 SDK missing: $p" }
    $references += ('/reference:"' + $p + '"')
}
# BrowserUrlReader reads browser tabs through UI Automation, so the whole agent
# needs the same UIAutomation references the shipping build uses.
foreach ($dll in @(
    'C:\Windows\Microsoft.NET\assembly\GAC_MSIL\UIAutomationClient\v4.0_4.0.0.0__31bf3856ad364e35\UIAutomationClient.dll',
    'C:\Windows\Microsoft.NET\assembly\GAC_MSIL\UIAutomationTypes\v4.0_4.0.0.0__31bf3856ad364e35\UIAutomationTypes.dll')) {
    if (Test-Path $dll) { $references += ('/reference:"' + $dll + '"') }
}
foreach ($dll in @('UIAutomationClient', 'UIAutomationTypes')) {
    $found = Get-ChildItem -Path "$env:WINDIR\Microsoft.NET\assembly" -Recurse -Filter "$dll.dll" -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match 'v4\.0_4\.0\.0\.0' } | Select-Object -First 1
    if ($found -and ($references -notcontains ('/reference:"' + $found.FullName + '"'))) {
        $references += ('/reference:"' + $found.FullName + '"')
    }
}
$arguments = @('/nologo', '/target:winexe', ('/out:"' + $exe + '"'),
               '/main:UiTestRunner') + $references
foreach ($f in $agentSources) { $arguments += ('"' + $f.FullName + '"') }
foreach ($f in $testFiles) { $arguments += ('"' + $f + '"') }
if (Test-Path $iconPath) {
    $arguments += ('/win32icon:"' + $iconPath + '"')
    $arguments += ('/resource:"' + $iconPath + '",WorkFolio.Agent.app.ico')
}
# The UI assets are embedded exactly as the shipping build does, so the suite
# checks the real pages and logo rather than a copy.
$logoPng = Join-Path $scriptDir 'assets\logo-512.png'
if (Test-Path $logoPng) { $arguments += ('/resource:"' + $logoPng + '",WorkFolio.Agent.logo') }
$webuiDir = Join-Path $scriptDir 'webui'
foreach ($page in @(@('login.html', 'WorkFolio.Agent.webui.login.html'), @('app.html', 'WorkFolio.Agent.webui.app.html'))) {
    $p = Join-Path $webuiDir $page[0]
    if (-not (Test-Path $p)) { throw "webui page missing: $p" }
    $arguments += ('/resource:"' + $p + '",' + $page[1])
}

$output = & $csc $arguments 2>&1
$code = $LASTEXITCODE
if ($output) { $output | ForEach-Object { Write-Host $_ } }
if ($code -ne 0) { throw "Test compilation failed with exit code $code" }

# The WebView2 assemblies must sit beside AgentTest.exe for the process to load.
function Copy-WebView2 {
    foreach ($dll in @('Microsoft.Web.WebView2.Core.dll', 'Microsoft.Web.WebView2.WinForms.dll', 'WebView2Loader.dll')) {
        Copy-Item (Join-Path $wv2Dir $dll) $outDir -Force
    }
}
Copy-WebView2

# A freshly compiled EXE is scanned by real-time antivirus the first time it
# runs, which can surface as a spurious FileNotFoundException while the WebView2
# DLLs beside it are still being inspected. Start-Process gives us a reliable
# exit code (native stderr would otherwise abort the script), and a crash-only
# retry rides out the scan. A normal test failure is a small positive count and
# is reported immediately.
$exit = 0
for ($attempt = 1; $attempt -le 3; $attempt++) {
    $proc = Start-Process -FilePath $exe -NoNewWindow -Wait -PassThru
    $exit = $proc.ExitCode
    if ($exit -ge 0) { break }
    Write-Host "Agent tests crashed (exit $exit); retrying for attempt $attempt." -ForegroundColor Yellow
    Start-Sleep -Seconds 2
    Copy-WebView2
}

Remove-Item -Recurse -Force $outDir -ErrorAction SilentlyContinue
exit $exit