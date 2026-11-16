@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Folio Server

rem ===========================================================================
rem  Folio server launcher
rem
rem    run-server.bat                     start the server
rem    run-server.bat rebuild             force a rebuild, then start
rem    run-server.bat --server.port=9090  any other argument goes to the app
rem
rem  The jar in target\ is rebuilt automatically whenever anything under src\
rem  is newer than it, so a normal "run-server.bat" is all that is needed.
rem ===========================================================================

rem --- Always work from the project folder so ./data resolves the same way ---
cd /d "%~dp0"

set "APP_NAME=Folio"
set "APP_JAR=target\WorkFolio-0.0.1-SNAPSHOT.jar"
set "APP_PROPS=src\main\resources\application.properties"
set "MVN_WRAPPER=mvnw.cmd"
set "PS_EXE=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
if not exist "%PS_EXE%" set "PS_EXE=powershell"

set "FORCE_BUILD=0"
set "NEED_BUILD=0"
set "BUILD_WHY="
set "APP_ARGS="

rem --- Separate our own options from the arguments meant for the app --------
:parse_args
if "%~1"=="" goto args_done
if /i "%~1"=="rebuild" goto arg_rebuild
set "APP_ARGS=%APP_ARGS% %1"
shift
goto parse_args

:arg_rebuild
set "FORCE_BUILD=1"
shift
goto parse_args

:args_done

echo.
echo === %APP_NAME% server ===
echo.

rem --- 1. Locate Java ------------------------------------------------------
set "JAVA_CMD="
if defined JAVA_HOME if exist "%JAVA_HOME%\bin\java.exe" set "JAVA_CMD=%JAVA_HOME%\bin\java.exe"
if not defined JAVA_CMD for /f "usebackq delims=" %%j in (`where java 2^>nul`) do if not defined JAVA_CMD set "JAVA_CMD=%%j"
if not defined JAVA_CMD goto no_java

set "JAVA_VER="
for /f "tokens=3" %%v in ('"%JAVA_CMD%" -version 2^>^&1') do if not defined JAVA_VER set "JAVA_VER=%%~v"
echo [java] %JAVA_VER%   (%JAVA_CMD%)

rem --- 2. Work out which port it will listen on ----------------------------
set "PORT=8080"
if defined SERVER_PORT set "PORT=%SERVER_PORT%"
if not defined SERVER_PORT for /f "usebackq tokens=1,* delims==" %%p in (`findstr /b /c:"server.port" "%APP_PROPS%" 2^>nul`) do for /f "tokens=* delims= " %%v in ("%%q") do if "!PORT!"=="8080" if not "%%v"=="" set "PORT=%%v"

rem --- 3. Warn when the port is already taken -----------------------------
set "PORT_PID="
echo !APP_ARGS! | findstr /i /c:"port=" >nul 2>&1
if not errorlevel 1 goto skip_port_check
for /f "tokens=5" %%p in ('netstat -ano -p tcp ^| findstr /c:":%PORT% " ^| findstr /c:"LISTENING"') do if not defined PORT_PID set "PORT_PID=%%p"
if not defined PORT_PID goto skip_port_check
echo [warn] Port %PORT% is already in use ^(PID %PORT_PID%^).
set /p "ANSWER=       Start anyway? [y/N] "
if /i "!ANSWER!"=="y" goto skip_port_check
goto abort

:skip_port_check

rem --- 4. Build the jar when it is missing or out of date ------------------
if "%FORCE_BUILD%"=="1" (
    set "NEED_BUILD=1"
    set "BUILD_WHY=rebuild was requested"
)
if "%NEED_BUILD%"=="0" if not exist "%APP_JAR%" (
    set "NEED_BUILD=1"
    set "BUILD_WHY=the jar is missing"
)
if "%NEED_BUILD%"=="0" call :check_stale
if "%NEED_BUILD%"=="0" goto run
if not "%BUILD_WHY%"=="" echo [build] Rebuilding - %BUILD_WHY%.

if not exist "%MVN_WRAPPER%" goto no_wrapper
echo [build] Packaging with the Maven wrapper - the first run downloads Maven.
call "%MVN_WRAPPER%" -q -DskipTests package
if errorlevel 1 goto build_failed
if not exist "%APP_JAR%" goto jar_missing

rem --- 5. Run it in the foreground ----------------------------------------
:run
echo.
echo [run] %APP_NAME% is starting on http://localhost:%PORT%/login
echo [run] Press Ctrl+C to stop the server.
echo.
"%JAVA_CMD%" -jar "%APP_JAR%"%APP_ARGS%
set "EXIT_CODE=%ERRORLEVEL%"
echo.
if "%EXIT_CODE%"=="0" goto stopped
goto failed

rem ===========================================================================
rem  Subroutine: is any source file newer than the jar?
rem ===========================================================================
:check_stale
rem Written without pipes so cmd.exe never gets a chance to eat one.
set "PS_STALE=$jar=Get-Item -LiteralPath '%CD%\%APP_JAR%'; $n=@(Get-ChildItem -LiteralPath '%CD%\src' -Recurse -File -ErrorAction SilentlyContinue).Where({$_.LastWriteTime -gt $jar.LastWriteTime}); if ($n.Count -gt 0){'STALE - ' + $n[0].Name}"
for /f "usebackq tokens=1-3" %%a in (`"%PS_EXE%" -NoProfile -Command "!PS_STALE!"`) do (
    if /i "%%a"=="STALE" (
        set "NEED_BUILD=1"
        set "BUILD_WHY=%%c is newer than the jar"
    )
)
goto :eof

rem ===========================================================================
rem  Exit paths
rem ===========================================================================
:stopped
echo [run] %APP_NAME% stopped.
endlocal
exit /b 0

:no_java
echo.
echo [error] No Java was found. Install JDK 8, 11 or 17, or point JAVA_HOME at
echo         the JDK you want, for example:
echo             set JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-17
goto failed

:no_wrapper
echo.
echo [error] %MVN_WRAPPER% is missing, so the jar cannot be built.
echo         Install Maven and run "mvn -DskipTests package", or set up the
echo         wrapper with "mvn wrapper:wrapper".
goto failed

:build_failed
echo.
echo [error] The Maven build failed - the messages above say why.
goto failed

:jar_missing
echo.
echo [error] The build finished but %APP_JAR% does not exist.
goto failed

:abort
echo.
echo [abort] Nothing was started.
endlocal
exit /b 1

:failed
echo.
echo [error] %APP_NAME% did not start. Fix the problem above and run this
echo         file again.
pause
endlocal
exit /b 1
