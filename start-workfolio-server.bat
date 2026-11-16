@echo off
setlocal
title Folio Local Server
cd /d "%~dp0"

echo ==============================================
echo   Folio - Local Server
echo   URL : http://127.0.0.1:8899
echo ==============================================
echo.

rem Stop any old server on port 8899 first
powershell -NoProfile -Command "Get-NetTCPConnection -LocalPort 8899 -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }"
timeout /t 1 /nobreak >nul

set "PHP=C:\Users\hp\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
if not exist "%PHP%" (
  echo PHP not found at: %PHP%
  echo Install PHP 8.3+ or edit the PHP path inside this file.
  pause
  exit /b 1
)

echo Starting PHP dev server (0.0.0.0:8899) ...
echo Press Ctrl+C to stop.
echo.
"%PHP%" -S 0.0.0.0:8899 -t "%~dp0php-app" "%~dp0php-app\index.php"