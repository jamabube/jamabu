@echo off
rem ---------------------------------------------------------------------------
rem  Change the administrator username and password.
rem
rem  Double-click this file. It asks for the new username and password and does
rem  the rest, so nothing has to be typed at a command prompt.
rem
rem  The web interface cannot change a username, and the password can only be
rem  changed from inside the system by somebody who can already sign in. This
rem  file is the way back when neither of those is true.
rem ---------------------------------------------------------------------------

setlocal enabledelayedexpansion
title VAMS - Change the sign-in details

cd /d "%~dp0"

echo.
echo  ===========================================================
echo   Vehicle Access Monitoring System
echo   Change the sign-in details
echo  ===========================================================
echo.

rem Same search order as start.bat: XAMPP before the PATH, because a machine
rem with both usually has a much older PHP on the PATH.
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

echo   [X] PHP was not found. Install XAMPP, or run start.bat first to see
echo       the same message with more detail.
goto finish

:php_found

rem Without .env the application falls back to the defaults compiled into
rem config/database.php, which name an account no XAMPP has - so every answer
rem below would fail with "Access denied for user 'vams_app'" and none of it
rem would be about the answer. Almost always this means the file was run from
rem a freshly extracted copy rather than the folder actually in use.
if not exist ".env" (
    echo   [X] There is no .env in this folder, so there is no installation
    echo       here to change.
    echo.
    echo       Folder: %CD%
    echo.
    echo       Run start.bat here first to set one up, or run this file from
    echo       the folder you have been using.
    goto finish
)


rem The account being changed. Whoever is locked out knows the name they have
rem been using, and defaulting to the seeded one covers the common case.
set "CURRENT=administrator"
set /p "CURRENT=  Which account? [administrator]: "
if "!CURRENT!"=="" set "CURRENT=administrator"

rem Each question carries its own rules immediately above it. They were once
rem printed together, and the password rules landed under the username prompt
rem where they read as instructions for it - so a password was typed into the
rem username field and refused for containing an "@".
set "ACCOUNT=!CURRENT!"

:ask_username
echo.
echo  -----------------------------------------------------------
echo   STEP 1 of 2 - Username
echo  -----------------------------------------------------------
echo   Letters, digits, dots, dashes and underscores. No spaces,
echo   no "@". Leave it blank to keep the name "!CURRENT!".
echo.

set "NEWNAME="
set /p "NEWNAME=  New username: "
echo.

if not "!NEWNAME!"=="" (
    "!PHP!" bin\console user:rename "!CURRENT!" "!NEWNAME!" --force

    if errorlevel 2 (
        echo.
        echo   That username was not accepted. The reason is above.
        goto ask_username
    )

    if errorlevel 1 goto finish

    set "ACCOUNT=!NEWNAME!"
)

:ask_password
echo.
echo  -----------------------------------------------------------
echo   STEP 2 of 2 - Password for "!ACCOUNT!"
echo  -----------------------------------------------------------
echo   12 or more characters, with an upper-case letter, a
echo   lower-case letter, a digit and a symbol. Leave it blank
echo   and one will be generated for you.
echo.

set "NEWPASS="
set /p "NEWPASS=  New password: "
echo.

if "!NEWPASS!"=="" (
    rem --generate, not a bare --force: without it the command stops to ask
    rem for the password again, which after the prompt above reads as the
    rem first answer not having been taken.
    "!PHP!" bin\console user:password "!ACCOUNT!" --force --generate
) else (
    "!PHP!" bin\console user:password "!ACCOUNT!" --force --password="!NEWPASS!"
)

rem Exit code 2 means the password itself was refused, and asking again can
rem fix it. Anything else is a fault no answer here will resolve, so it stops
rem rather than asking the same question until somebody gives up.
if errorlevel 2 (
    echo.
    echo   That password was not accepted. The reason is above.
    goto ask_password
)

if errorlevel 1 goto finish

echo.
echo  -----------------------------------------------------------
echo   Sign in as: !ACCOUNT!
echo.
echo   The system asks for a new password at the first sign-in.
echo   That is deliberate: a password somebody else has typed for
echo   you should not stay in use.
echo  -----------------------------------------------------------

:finish
echo.
pause
endlocal
