@echo off
setlocal EnableExtensions EnableDelayedExpansion

rem ---------------------------------------------------------------------------
rem  Security-Enhanced IoT-Based Vehicle Access Monitoring System
rem  Forest Lawn Memorial Park
rem
rem  Double-click this file to start the system.
rem
rem  On a first run it prepares everything: finds PHP, checks MySQL, writes a
rem  .env, generates the application key, creates the database, applies the
rem  migrations and seeds the reference data. On later runs it skips whatever
rem  is already done and just starts the server.
rem
rem  Options (all optional):
rem      start.bat --port 8080     serve on a different port
rem      start.bat --demo          also load sample vehicles and movements
rem      start.bat --fresh         drop and rebuild the database (destructive)
rem      start.bat --no-browser    do not open a browser window
rem      start.bat --test          run the test suite and exit
rem
rem  @package VAMS
rem  @version 1.0.0
rem ---------------------------------------------------------------------------

title VAMS - Vehicle Access Monitoring System

cd /d "%~dp0"

set "PORT=8000"
set "HOST=127.0.0.1"
set "LOAD_DEMO="
set "FRESH="
set "OPEN_BROWSER=1"
set "RUN_TESTS="

rem Each option is handled on its own line rather than in a parenthesised
rem block: cmd expands %1 when it parses a block, so a shift inside one does
rem not affect the arguments already read from it.
:parse_arguments
if "%~1"=="" goto arguments_done

if /i "%~1"=="--help" goto show_help
if /i "%~1"=="-h"     goto show_help

if /i "%~1"=="--port" goto option_port
if /i "%~1"=="--demo" goto option_demo
if /i "%~1"=="--fresh" goto option_fresh
if /i "%~1"=="--no-browser" goto option_no_browser
if /i "%~1"=="--test" goto option_test

echo   Unrecognised option: %~1
echo   Run "start.bat --help" to see what is available.
goto fail

:option_port
if "%~2"=="" (
    echo   --port needs a number, for example: start.bat --port 8080
    goto fail
)
set "PORT=%~2"
shift
shift
goto parse_arguments

:option_demo
set "LOAD_DEMO=1"
shift
goto parse_arguments

:option_fresh
set "FRESH=1"
shift
goto parse_arguments

:option_no_browser
set "OPEN_BROWSER="
shift
goto parse_arguments

:option_test
set "RUN_TESTS=1"
shift
goto parse_arguments

:arguments_done

echo.
echo  ===========================================================
echo   Vehicle Access Monitoring System
echo   Forest Lawn Memorial Park
echo  ===========================================================
echo.

rem ---------------------------------------------------------------------------
rem  1. Locate PHP
rem
rem  XAMPP is checked before the PATH: a machine with both usually has a much
rem  older PHP on the PATH, and starting against the wrong one produces errors
rem  that look like application faults.
rem ---------------------------------------------------------------------------

set "PHP="

for %%D in ("C:\xampp\php" "D:\xampp\php" "C:\xampp8\php" "%ProgramFiles%\php" "C:\php") do (
    if exist "%%~D\php.exe" (
        set "PHP=%%~D\php.exe"
        goto php_found
    )
)

for /f "delims=" %%P in ('where php 2^>nul') do (
    set "PHP=%%P"
    goto php_found
)

echo   [X] PHP was not found.
echo.
echo       This system needs PHP 8.2 or newer. The usual way to get it on
echo       Windows is to install XAMPP, which puts it at C:\xampp\php.
echo.
echo       Download: https://www.apachefriends.org/
echo.
echo       If PHP is installed somewhere unusual, add its folder to the PATH
echo       and run this file again.
goto fail

:php_found
echo   [1/7] PHP
echo         !PHP!

rem Refuse to run on a version the code does not support. The syntax used
rem throughout requires 8.2, and the failure on an older runtime is a parse
rem error in an unrelated file, which tells the operator nothing.
"!PHP!" -r "exit(PHP_VERSION_ID >= 80200 ? 0 : 1);" >nul 2>&1
if errorlevel 1 (
    for /f "delims=" %%V in ('"!PHP!" -r "echo PHP_VERSION;" 2^>nul') do set "PHPVER=%%V"
    echo         [X] PHP !PHPVER! is too old. Version 8.2 or newer is required.
    goto fail
)

for /f "delims=" %%V in ('"!PHP!" -r "echo PHP_VERSION;" 2^>nul') do set "PHPVER=%%V"
echo         version !PHPVER!  [ok]

rem The application cannot run without these. Checking here turns a confusing
rem runtime failure into a sentence naming the extension to enable.
set "MISSING="
for %%E in (pdo_mysql mbstring openssl json fileinfo) do (
    "!PHP!" -r "exit(extension_loaded('%%E') ? 0 : 1);" >nul 2>&1
    if errorlevel 1 set "MISSING=!MISSING! %%E"
)

if not "!MISSING!"=="" (
    echo         [X] Missing PHP extension^(s^):!MISSING!
    echo.
    echo             Open php.ini next to php.exe, remove the leading
    echo             semicolon from the matching "extension=" lines, then
    echo             run this file again.
    goto fail
)
echo         extensions  [ok]

rem These are not required. Each one that is absent costs something specific,
rem so it is named rather than being allowed to surprise somebody later, but
rem none of them is a reason to refuse to start.
for %%E in (zip gd intl curl) do (
    "!PHP!" -r "exit(extension_loaded('%%E') ? 0 : 1);" >nul 2>&1
    if errorlevel 1 (
        if /i "%%E"=="zip"  echo         [warn] zip is off - backups go uncompressed, no Excel export.
        if /i "%%E"=="gd"   echo         [warn] gd is off - profile pictures are stored unresized.
        if /i "%%E"=="intl" echo         [warn] intl is off - built-in date and number formatting is used.
        if /i "%%E"=="curl" echo         [warn] curl is off - assets:fetch uses the stream wrapper.
    )
)

rem ---------------------------------------------------------------------------
rem  2. Environment file
rem ---------------------------------------------------------------------------

echo   [2/7] Configuration

if not exist ".env" (
    if not exist ".env.example" (
        echo         [X] Neither .env nor .env.example is present.
        echo             This does not look like a complete copy of the project.
        goto fail
    )

    copy /y ".env.example" ".env" >nul
    echo         created .env from the template

    rem The interface builds absolute asset URLs from APP_URL. If it does not
    rem match the address actually being served, every stylesheet and script
    rem 404s and the pages arrive unstyled.
    "!PHP!" -r "$p='.env';$c=file_get_contents($p);$c=preg_replace('/^APP_URL[^\S\r\n]*=[^\r\n]*$/m','APP_URL=http://%HOST%:%PORT%',$c,1);$c=preg_replace('/^APP_ENV[^\S\r\n]*=[^\r\n]*$/m','APP_ENV=development',$c,1);$c=preg_replace('/^DB_USERNAME[^\S\r\n]*=[^\r\n]*$/m','DB_USERNAME=root',$c,1);$c=preg_replace('/^DB_PASSWORD[^\S\r\n]*=[^\r\n]*$/m','DB_PASSWORD=',$c,1);file_put_contents($p,$c);"
    echo         set APP_URL to http://%HOST%:%PORT% and the database user to root
    echo.
    echo         Note: a first run uses the XAMPP default MySQL account
    echo         ^(root with no password^). Before this system is used for real,
    echo         create a dedicated account and set DB_USERNAME and DB_PASSWORD
    echo         in .env.
    echo.
) else (
    echo         .env is present
)

"!PHP!" bin\console key:generate >nul 2>&1
echo         application key  [ok]

rem ---------------------------------------------------------------------------
rem  3. MySQL
rem ---------------------------------------------------------------------------

echo   [3/7] Database server

set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "DB_NAME=vams"
set "DB_USER=root"
set "DB_PASS="

set "DB_USER_DECLARED="

for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
    if /i "%%A"=="DB_HOST"     set "DB_HOST=%%B"
    if /i "%%A"=="DB_PORT"     set "DB_PORT=%%B"
    if /i "%%A"=="DB_DATABASE" set "DB_NAME=%%B"
    if /i "%%A"=="DB_USERNAME" set "DB_USER=%%B"
    if /i "%%A"=="DB_USERNAME" set "DB_USER_DECLARED=1"
    if /i "%%A"=="DB_PASSWORD" set "DB_PASS=%%B"
)

rem This file falls back to "root" when .env does not name an account; the
rem application falls back to "vams_app". Left alone, that difference shows up
rem later as a migration that cannot connect while the check here passed.
if not defined DB_USER_DECLARED (
    echo         [X] .env does not set DB_USERNAME.
    echo.
    echo             Add these lines to .env and run this file again:
    echo                 DB_USERNAME=root
    echo                 DB_PASSWORD=
    goto fail
)

rem Values in .env may carry a trailing inline comment; strip it the same way
rem the application's own parser does.
for %%V in (DB_HOST DB_PORT DB_NAME DB_USER DB_PASS) do (
    for /f "tokens=1 delims=#" %%C in ("!%%V!") do set "%%V=%%C"
    for /f "tokens=* delims= " %%C in ("!%%V!") do set "%%V=%%C"
)

"!PHP!" -r "try{new PDO(sprintf('mysql:host=%%s;port=%%s','%DB_HOST%','%DB_PORT%'),'%DB_USER%','%DB_PASS%');exit(0);}catch(Throwable $e){exit(1);}" >nul 2>&1

if errorlevel 1 (
    echo         MySQL is not answering on %DB_HOST%:%DB_PORT% - trying to start it
    call :start_mysql
    timeout /t 4 /nobreak >nul

    "!PHP!" -r "try{new PDO(sprintf('mysql:host=%%s;port=%%s','%DB_HOST%','%DB_PORT%'),'%DB_USER%','%DB_PASS%');exit(0);}catch(Throwable $e){exit(1);}" >nul 2>&1
    if errorlevel 1 (
        echo         [X] Could not connect to MySQL on %DB_HOST%:%DB_PORT% as "%DB_USER%".
        echo.
        echo             Open the XAMPP Control Panel and press Start next to
        echo             MySQL, then run this file again.
        echo.
        echo             If MySQL is running and this still fails, the account
        echo             in .env is wrong. Check DB_USERNAME and DB_PASSWORD.
        goto fail
    )
)
echo         connected to %DB_HOST%:%DB_PORT% as "%DB_USER%"  [ok]

rem ---------------------------------------------------------------------------
rem  4. Database
rem ---------------------------------------------------------------------------

echo   [4/7] Schema

if defined FRESH (
    echo.
    echo         --fresh will DROP the database "%DB_NAME%" and everything in it:
    echo         every vehicle, every movement record, every audit entry.
    echo.
    set /p "CONFIRM=        Type DROP to confirm, or press Enter to cancel: "
    if /i not "!CONFIRM!"=="DROP" (
        echo         Cancelled. Nothing was changed.
        goto fail
    )
    "!PHP!" -r "$p=new PDO(sprintf('mysql:host=%%s;port=%%s','%DB_HOST%','%DB_PORT%'),'%DB_USER%','%DB_PASS%');$p->exec('DROP DATABASE IF EXISTS `%DB_NAME%`');"
    echo         dropped "%DB_NAME%"
)

"!PHP!" -r "$p=new PDO(sprintf('mysql:host=%%s;port=%%s','%DB_HOST%','%DB_PORT%'),'%DB_USER%','%DB_PASS%');$p->exec('CREATE DATABASE IF NOT EXISTS `%DB_NAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');" 2>nul
if errorlevel 1 (
    echo         [X] Could not create the database "%DB_NAME%".
    echo             The account "%DB_USER%" may not be allowed to create databases.
    goto fail
)
echo         database "%DB_NAME%" is present  [ok]

rem The checks above connect to the server. The application connects to the
rem database, with a charset, as a named account - so that is what is proved
rem here, before a failure can reappear as an opaque migration error.
set "DB_SOCKET="
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
    if /i "%%A"=="DB_SOCKET" set "DB_SOCKET=%%B"
)

rem A socket connection is a different DSN entirely, and is not something a
rem XAMPP installation on Windows uses. Rather than probe it wrongly, skip.
if defined DB_SOCKET goto db_probe_done

set "DBPROBE=%TEMP%\vams-db-probe.txt"
if exist "%DBPROBE%" del "%DBPROBE%" >nul 2>&1

"!PHP!" -r "try{new PDO(sprintf('mysql:host=%%s;port=%%s;dbname=%%s;charset=utf8mb4','%DB_HOST%','%DB_PORT%','%DB_NAME%'),'%DB_USER%','%DB_PASS%');}catch(Throwable $e){file_put_contents(getenv('DBPROBE'),$e->getMessage());}"

if exist "%DBPROBE%" (
    echo         [X] The application cannot open "%DB_NAME%" as "%DB_USER%".
    type "%DBPROBE%"
    echo.
    echo.
    echo             Check DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env.
    del "%DBPROBE%" >nul 2>&1
    goto fail
)
echo         reachable as the application connects  [ok]

:db_probe_done

"!PHP!" bin\console migrate --no-remedy

rem Exit code 3 means the database holds tables that no migration accounts
rem for - what an earlier run that died partway leaves behind. It is the one
rem migration failure with a known remedy, so it is offered rather than
rem printed as an instruction to type somewhere else.
if errorlevel 3 goto migrate_partial

if errorlevel 1 (
    echo.
    echo         [X] The migrations did not complete. The message above says why.
    goto fail
)
goto migrate_done

:migrate_partial
echo.

if defined FRESH goto migrate_rebuild

echo         This rebuild DROPS the database "%DB_NAME%" and everything in it.
echo         On a half-finished installation that is nothing; on a running
echo         system it is every vehicle, movement and audit record.
echo.
set "REBUILD="
set /p "REBUILD=        Type REBUILD to drop and recreate the schema, or press Enter to stop: "

if /i not "!REBUILD!"=="REBUILD" (
    echo.
    echo         Stopped. Nothing was changed.
    echo         To take a backup of what is there first:
    echo             php bin\console backup:create
    goto fail
)

:migrate_rebuild
echo.
echo         Rebuilding the schema
"!PHP!" bin\console migrate:fresh --force
if errorlevel 1 (
    echo.
    echo         [X] The rebuild did not complete. The message above says why.
    goto fail
)

:migrate_done

rem ---------------------------------------------------------------------------
rem  5. Reference data
rem ---------------------------------------------------------------------------

echo   [5/7] Reference data

if defined LOAD_DEMO (
    "!PHP!" bin\console seed --demo
) else (
    "!PHP!" bin\console seed
)

if errorlevel 1 (
    echo.
    echo         [X] Seeding did not complete. The message above says why.
    goto fail
)

rem ---------------------------------------------------------------------------
rem  6. Tests, when asked for
rem ---------------------------------------------------------------------------

if defined RUN_TESTS (
    echo   [6/7] Tests
    echo.
    "!PHP!" bin\console test
    echo.
    echo   Finished. The server was not started because --test was given.
    goto done
)

echo   [6/7] Front-end assets

if exist "public\assets\vendor\bootstrap\bootstrap.min.css" (
    echo         vendor libraries present  [ok]
) else (
    echo         vendor libraries not fetched
    echo         The interface works without them, but icons and charts will be
    echo         plain. On a machine with internet access, run:
    echo             php bin\console assets:fetch
)

rem ---------------------------------------------------------------------------
rem  7. Serve
rem ---------------------------------------------------------------------------

echo   [7/7] Starting the web server

rem A port already in use is the most common repeat-run problem, and the error
rem PHP prints for it is easy to miss in the scrollback.
"!PHP!" -r "$s=@stream_socket_server('tcp://%HOST%:%PORT%',$n,$m);if($s===false){exit(1);}fclose($s);exit(0);" >nul 2>&1
if errorlevel 1 (
    echo         [X] Port %PORT% is already in use.
    echo.
    echo             Either the system is already running - try
    echo             http://%HOST%:%PORT%/ in a browser - or another program
    echo             has the port. To use a different one:
    echo.
    echo                 start.bat --port 8080
    goto fail
)

echo.
echo  ===========================================================
echo   Ready.
echo.
echo     Address:  http://%HOST%:%PORT%/
echo     Sign in:  administrator
echo     Password: shown when the administrator account was first
echo               created. If it was missed, reset it with:
echo                   php bin\console user:password administrator
echo.
echo   Leave this window open. Closing it stops the system.
echo   Press Ctrl+C to stop.
echo  ===========================================================
echo.

if defined OPEN_BROWSER (
    rem Give the server a moment to bind before the browser asks for a page.
    start "" /b cmd /c "timeout /t 2 /nobreak >nul & start http://%HOST%:%PORT%/"
)

rem public\router.php reproduces what public\.htaccess does under Apache:
rem serve a real file when one exists, hand everything else to the front
rem controller. Apache never loads it.
"!PHP!" -S %HOST%:%PORT% -t public public\router.php

echo.
echo   The server has stopped.
goto done

rem ---------------------------------------------------------------------------
rem  Helpers
rem ---------------------------------------------------------------------------

:start_mysql
rem Try the XAMPP service first, then the executable directly. Both are quiet
rem on failure: the caller re-tests the connection and reports properly.
net start mysql >nul 2>&1
if not errorlevel 1 exit /b 0

for %%D in ("C:\xampp\mysql\bin" "D:\xampp\mysql\bin" "C:\xampp8\mysql\bin") do (
    if exist "%%~D\mysqld.exe" (
        start "" /b "%%~D\mysqld.exe" --defaults-file="%%~D\..\bin\my.ini" --standalone
        exit /b 0
    )
)
exit /b 1

:show_help
echo.
echo   Vehicle Access Monitoring System - start.bat
echo.
echo   Usage:  start.bat [options]
echo.
echo     --port NUMBER    serve on this port instead of 8000
echo     --demo           also load sample vehicles, drivers and movements
echo     --fresh          drop and rebuild the database ^(destructive^)
echo     --no-browser     do not open a browser window
echo     --test           run the test suite and exit without serving
echo     --help           show this text
echo.
echo   With no options it prepares whatever is not ready yet and starts the
echo   server on http://127.0.0.1:8000/.
echo.
goto done

:fail
echo.
echo  -----------------------------------------------------------
echo   Startup stopped. Nothing is running.
echo  -----------------------------------------------------------
echo.
pause
endlocal
exit /b 1

:done
echo.
pause
endlocal
exit /b 0
