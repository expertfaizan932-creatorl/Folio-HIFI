<#
    Builds the Folio Manager Android APK (release, signed).

    Produces android\app\build\outputs\apk\release\app-release.apk and copies
    it to <repo>\dist\FolioManager.apk.

    The build is framework-only (no AndroidX / no third-party libraries), so it
    needs nothing beyond the Android SDK and a Gradle distribution. This script
    prefers a locally cached Gradle under %USERPROFILE%\.gradle (the wrapper
    would otherwise try to re-download the distribution).

        powershell -ExecutionPolicy Bypass -File android\build-apk.ps1
#>

[CmdletBinding()]
param(
    [string]$SdkDir = '',
    [string]$GradleVersion = '8.14.3',
    [switch]$Offline = $true
)

$ErrorActionPreference = 'Stop'
$here = if ($PSScriptRoot) { $PSScriptRoot } else { (Get-Location).Path }

# --- SDK -------------------------------------------------------------------
if (-not $SdkDir) {
    if ($env:ANDROID_SDK_ROOT) { $SdkDir = $env:ANDROID_SDK_ROOT }
    elseif ($env:ANDROID_HOME) { $SdkDir = $env:ANDROID_HOME }
    else { $SdkDir = Join-Path $env:LOCALAPPDATA 'Android\Sdk' }
}
if (-not (Test-Path $SdkDir)) { throw "Android SDK not found at '$SdkDir'. Pass -SdkDir." }
$env:ANDROID_SDK_ROOT = $SdkDir
$env:ANDROID_HOME = $SdkDir
# Make local.properties match the SDK the build will actually use.
$sdkForProps = $SdkDir -replace '\\', '/'
Set-Content -Path (Join-Path $here 'local.properties') -Value ("sdk.dir=" + $sdkForProps) -Encoding ASCII

# --- signing material ------------------------------------------------------
# keystore.properties and the keystore are secrets and are git-ignored. If they
# are absent (fresh checkout) create a release key so the build still works.
$keystoreProps = Join-Path $here 'keystore.properties'
$keystoreFile  = Join-Path $here 'folimanager-release.keystore'
if (-not (Test-Path $keystoreProps) -or -not (Test-Path $keystoreFile)) {
    Write-Host 'No release keystore found - generating one.' -ForegroundColor Yellow
    $keytool = Join-Path $env:JAVA_HOME 'bin\keytool.exe'
    if (-not (Test-Path $keytool)) { $keytool = 'keytool' }
    & $keytool -genkeypair -v -keystore $keystoreFile -alias foliomanager `
        -keyalg RSA -keysize 2048 -validity 10000 `
        -storepass folio123 -keypass folio123 `
        -dname "CN=Folio Manager, OU=WorkFolio, O=BizTrack, L=PK, ST=PK, C=PK" 2>&1 | Out-Null
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $text = "storeFile=folimanager-release.keystore`nstorePassword=folio123`nkeyAlias=foliomanager`nkeyPassword=folio123`n"
    [System.IO.File]::WriteAllText($keystoreProps, $text, $utf8NoBom)
}

# --- Gradle ----------------------------------------------------------------
$gradle = $null
$cached = Get-ChildItem (Join-Path $env:USERPROFILE ".gradle\wrapper\dists") -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like "gradle-$GradleVersion-*" } |
    Sort-Object Name -Descending
foreach ($dir in $cached) {
    $bat = Get-ChildItem $dir.FullName -Recurse -Filter 'gradle.bat' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($bat) { $gradle = $bat.FullName; break }
}
if (-not $gradle) {
    $onPath = Get-Command gradle -ErrorAction SilentlyContinue
    if ($onPath) { $gradle = $onPath.Source }
}
if (-not $gradle) {
    $wrapper = Join-Path $here 'gradlew.bat'
    if (Test-Path $wrapper) { $gradle = $wrapper }
}
if (-not $gradle) { throw "No Gradle found. Install Gradle, or create a wrapper, or cache $GradleVersion under $env:USERPROFILE\.gradle\wrapper\dists." }

if (-not $env:GRADLE_USER_HOME) {
    $env:GRADLE_USER_HOME = Join-Path $env:USERPROFILE '.gradle'
}

Write-Host '=== Folio Manager APK build ===' -ForegroundColor Cyan
Write-Host "SDK      : $SdkDir" -ForegroundColor DarkGray
Write-Host "Gradle   : $gradle" -ForegroundColor DarkGray

$gradleArgs = @(':app:assembleRelease', '--console=plain')
if ($Offline) { $gradleArgs += '--offline' }

Push-Location $here
try {
    & $gradle @gradleArgs
    if ($LASTEXITCODE -ne 0) { throw "Gradle build failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

$apk = Join-Path $here 'app\build\outputs\apk\release\app-release.apk'
if (-not (Test-Path $apk)) { throw "APK not found at $apk" }

$dist = Join-Path (Split-Path -Parent $here) 'dist'
if (-not (Test-Path $dist)) { New-Item -ItemType Directory -Path $dist -Force | Out-Null }
$out = Join-Path $dist 'FolioManager.apk'
Copy-Item $apk $out -Force

Write-Host ''
Write-Host ("Built {0}  ({1} MB)" -f $out, [math]::Round((Get-Item $out).Length / 1MB, 2))
Write-Host 'Install with:  adb install -r dist\FolioManager.apk'
exit 0
