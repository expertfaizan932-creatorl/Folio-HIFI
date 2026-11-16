# Folio - switch BACK to production https://folio.biztrack.uk
# Stops tunnel + php, removes autostart, reverts agent configs.
$ErrorActionPreference = 'SilentlyContinue'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path

# stop processes
Get-NetTCPConnection -LocalPort 8899 -State Listen | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
Get-Process cloudflared | Stop-Process -Force

# remove autostart
Remove-Item (Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs\Startup\start-workfolio-tunnel-autostart.vbs') -Force

# revert configs
foreach ($cfg in @((Join-Path $root 'agent\bin\agent.config'), (Join-Path $root 'usb\agent.config'))) {
    if (-not (Test-Path $cfg)) { continue }
    $j = Get-Content $cfg -Raw | ConvertFrom-Json
    $j.ServerUrl = 'https://folio.biztrack.uk'
    ($j | ConvertTo-Json -Depth 6 -Compress) | Set-Content $cfg -NoNewline -Encoding ASCII
}
Write-Host 'Production mode: tunnel band, configs -> https://folio.biztrack.uk' -ForegroundColor Green