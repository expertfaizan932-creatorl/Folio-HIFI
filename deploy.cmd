@echo off
setlocal
set HOST=root@81.17.101.93
set DEST=/var/www/folio

echo === Step 1/3: uploading agent files to %HOST%:/tmp ===
scp "%~dp0php-app\lib\api_agent.php" "%~dp0php-app\agent\WorkFolioAgent.exe" "%~dp0php-app\agent\version.json" %HOST%:/tmp/
if errorlevel 1 goto :fail

echo.
echo === Step 2/3: installing on the server ===
ssh %HOST% "install -m 0644 -o www-data -g www-data /tmp/api_agent.php %DEST%/lib/api_agent.php && install -m 0644 -o www-data -g www-data /tmp/WorkFolioAgent.exe %DEST%/agent/WorkFolioAgent.exe && install -m 0644 -o www-data -g www-data /tmp/version.json %DEST%/agent/version.json && systemctl reload php8.3-fpm && echo DEPLOY-OK"
if errorlevel 1 goto :fail

echo.
echo === Step 3/3: verify /api/agent/update ===
curl -s https://folio.biztrack.uk/api/agent/update
echo.
echo.
echo Finished. Expect version 1.4.1 and sha256 4d418bb10f070de35fd27f2d9110ba41f2f308fe7f9ae89c7a645af104af52e6
pause
goto :eof

:fail
echo.
echo *** DEPLOY FAILED - see the error above ***
pause
