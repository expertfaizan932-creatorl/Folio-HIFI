@echo off
title Folio - Public Tunnel
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-workfolio-tunnel.ps1"
echo.
pause