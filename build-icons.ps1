<#
    Builds the Folio brand logo set FROM THE OFFICIAL LOGO FILE.

      * Source logo        -> Hifi Logo.jfif  (447x447, round artwork on #F8F8F8)
      * PWA icons          -> php-app\icons
      * PWA icons (parity) -> src\main\resources\static\icons  (Java reference)
      * Windows icon       -> agent\assets\app.ico   (EXE + tray + login)

    The logo is rendered "contain" (fits, never crops) on a square canvas filled
    with the logo's own background colour, then clipped to a CIRCLE. Every surface
    (web header, PWA, Windows tray, agent login) therefore shows the same round
    mark with transparent corners - never a square.

    The maskable icon is the one exception: it stays full-bleed with -NoClip
    because Android supplies its own mask and transparent corners would leak.

    Uses System.Drawing, present on every Windows machine - no image editor,
    no .NET SDK.

        powershell -ExecutionPolicy Bypass -File build-icons.ps1

    Override the artwork without editing this file:

        powershell -ExecutionPolicy Bypass -File build-icons.ps1 -SourceLogo "other.jfif"
#>
[CmdletBinding()]
param(
    [string]$OutputDir,
    [string]$SourceLogo
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $OutputDir) { $OutputDir = Join-Path $scriptDir 'php-app\icons' }
if (-not $SourceLogo) { $SourceLogo = Join-Path $scriptDir 'Hifi Logo.jfif' }
if (-not (Test-Path $SourceLogo)) { throw "Logo source not found: $SourceLogo" }

$javaDir  = Join-Path $scriptDir 'src\main\resources\static\icons'
$assetDir = Join-Path $scriptDir 'agent\assets'

# Background sampled from the logo's own corner (#F8F8F8).
$src = [System.Drawing.Bitmap]::FromFile($SourceLogo)
$bg = $src.GetPixel(2, 2)
Write-Host ("Logo source : {0}  ({1}x{2})" -f $SourceLogo, $src.Width, $src.Height) -ForegroundColor DarkGray
Write-Host ("Background  : #{0:X2}{1:X2}{2:X2}" -f $bg.R, $bg.G, $bg.B) -ForegroundColor DarkGray

function New-RoundIcon {
    # Renders the official logo inside Size x Size on the logo's own background
    # colour, clipped to an inscribed CIRCLE - that is what makes the mark round
    # everywhere instead of a square tile. The clip is drawn on the canvas, so
    # the corners come out genuinely transparent.
    #
    #   -NoClip  keeps the full-bleed square. Required for the maskable icon:
    #            Android applies its own mask, and transparent corners would
    #            render as holes against the launcher's own background.
    param([int]$Size, [double]$Scale = 1.0, [switch]$NoClip)

    $bmp = New-Object System.Drawing.Bitmap($Size, $Size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $bmp.SetResolution(96, 96)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode     = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode   = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality

    $path = $null
    if (-not $NoClip) {
        # GraphicsPath.AddEllipse + SetClip gives an antialiased rim, which is
        # what keeps a 16px tray icon looking round rather than chewed.
        $path = New-Object System.Drawing.Drawing2D.GraphicsPath
        $path.AddEllipse(0, 0, [float]$Size, [float]$Size)
        $g.SetClip($path)
    }

    $bgBrush = New-Object System.Drawing.SolidBrush($bg)
    $g.FillRectangle($bgBrush, 0, 0, $Size, $Size)

    # target box: logo scaled by $Scale, centered, aspect preserved
    $tw = [int]($Size * $Scale)
    $th = [int]($tw * $src.Height / $src.Width)
    if ($th -gt [int]($Size * $Scale)) { $th = [int]($Size * $Scale); $tw = [int]($th * $src.Width / $src.Height) }
    $x = [int](($Size - $tw) / 2)
    $y = [int](($Size - $th) / 2)

    $dst = New-Object System.Drawing.Rectangle($x, $y, $tw, $th)
    $g.DrawImage($src, $dst, 0, 0, $src.Width, $src.Height, [System.Drawing.GraphicsUnit]::Pixel)

    if ($path) { $path.Dispose() }
    $bgBrush.Dispose(); $g.Dispose()
    return $bmp
}

function Save-Png {
    param([string]$Path, [System.Drawing.Bitmap]$Bmp)
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $Bmp.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    Write-Host ("  {0,-34} {1} KB" -f $Path, [math]::Round((Get-Item $Path).Length / 1KB, 1)) -ForegroundColor Green
}

Write-Host '=== Folio brand icon build ===' -ForegroundColor Cyan

foreach ($s in @(192, 512)) {
    $bmp = New-RoundIcon -Size $s
    Save-Png -Path (Join-Path $OutputDir "icon-$s.png") -Bmp $bmp
    Save-Png -Path (Join-Path $javaDir "icon-$s.png") -Bmp $bmp
    $bmp.Dispose()
}

# Header logo: same round mark, its own file because the sidebar renders it at
# 38px next to the wordmark and as a wide banner it used to be a square tile.
$site = New-RoundIcon -Size 256
Save-Png -Path (Join-Path $OutputDir 'websitelogo.png') -Bmp $site
$site.Dispose()

# maskable: mark inside Android's 80% safe zone; stays full-bleed square (-NoClip)
# because Android supplies the mask and transparent corners would leak.
$mask = New-RoundIcon -Size 512 -Scale 0.80 -NoClip
Save-Png -Path (Join-Path $OutputDir 'icon-maskable-512.png') -Bmp $mask
Save-Png -Path (Join-Path $javaDir 'icon-maskable-512.png') -Bmp $mask
$mask.Dispose()

# ---------------------------------------------------------------- app.ico ----
if (-not (Test-Path $assetDir)) { New-Item -ItemType Directory -Path $assetDir -Force | Out-Null }

# The agent embeds this as WorkFolio.Agent.logo for its sign-in screen. It has to
# be written here, not copied on demand by build-agent.ps1, otherwise a rebuild
# silently keeps embedding whatever logo was left over from the previous brand.
$logo = New-RoundIcon -Size 512
Save-Png -Path (Join-Path $assetDir 'logo-512.png') -Bmp $logo
$logo.Dispose()

function Get-PngBytes {
    param([int]$Size)
    $bmp = New-RoundIcon -Size $Size
    $ms = New-Object System.IO.MemoryStream
    $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
    $bytes = $ms.ToArray()
    $ms.Dispose()
    return $bytes
}

$icoSizes = @(16, 24, 32, 48, 64, 128, 256)
$blobs = @()
foreach ($s in $icoSizes) { $blobs += ,(Get-PngBytes -Size $s) }

Write-Host '  Assembling app.ico ...' -ForegroundColor DarkGray
$count = $icoSizes.Count
$offset = 6 + 16 * $count
$ms = New-Object System.IO.MemoryStream
$bw = New-Object System.IO.BinaryWriter($ms)
$bw.Write([uint16]0); $bw.Write([uint16]1); $bw.Write([uint16]$count)
for ($i = 0; $i -lt $count; $i++) {
    $s = $icoSizes[$i]
    $dim = if ($s -ge 256) { 0 } else { $s }
    $bw.Write([byte]$dim); $bw.Write([byte]$dim)
    $bw.Write([byte]0); $bw.Write([byte]0)
    $bw.Write([uint16]1); $bw.Write([uint16]32)
    $bw.Write([uint32]$blobs[$i].Length)
    $bw.Write([uint32]$offset)
    $offset += $blobs[$i].Length
}
foreach ($b in $blobs) {
    $bytes = [byte[]]$b
    $ms.Write($bytes, 0, $bytes.Length)
}
$bw.Flush()
[System.IO.File]::WriteAllBytes((Join-Path $assetDir 'app.ico'), $ms.ToArray())
$bw.Dispose(); $ms.Dispose()

Write-Host ("  {0,-34} {1} KB" -f (Join-Path $assetDir 'app.ico'), [math]::Round((Get-Item (Join-Path $assetDir 'app.ico')).Length / 1KB, 1)) -ForegroundColor Green

$src.Dispose()
Write-Host ''
Write-Host 'Brand icon set ready.' -ForegroundColor Cyan