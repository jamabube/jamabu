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

rem The account being changed. Whoever is locked out knows the name they have
rem been using, and defaulting to the seeded one covers the common case.
set "CURRENT=administrator"
set /p "CURRENT=  Which account? [administrator]: "
if "!CURRENT!"=="" set "CURRENT=administrator"

echo.
echo   Leave a blank answer to keep what is there now.
echo.

set "NEWNAME="
set /p "NEWNAME=  New username for !CURRENT!: "

echo   A password needs 12 or more characters, with an upper-case letter,
echo   a lower-case letter, a digit and a symbol. Leave it blank and one
echo   will be generated for you.
echo.

rem The rename runs first: the password is then set against whatever the
rem account is actually called, so the two cannot disagree.
set "ACCOUNT=!CURRENT!"

if not "!NEWNAME!"=="" (
    "!PHP!" bin\console user:rename "!CURRENT!" "!NEWNAME!" --force
    if errorlevel 1 goto finish

    set "ACCOUNT=!NEWNAME!"
)

rem A rejected password is the likeliest outcome of this prompt, and the
rem reasons are printed by the command. Asking again beats sending somebody
rem back to re-run the whole file for a password two characters too short.
:ask_password
set "NEWPASS="
set /p "NEWPASS=  New password (blank generates one): "
echo.

if "!NEWPASS!"=="" (
    rem --generate, not a bare --force: without it the command stops to ask
    rem for the password again, which after the prompt above reads as the
    rem first answer not having been taken.
    "!PHP!" bin\console user:password "!ACCOUNT!" --force --generate
) else (
    "!PHP!" bin\console user:password "!ACCOUNT!" --force --password="!NEWPASS!"
)

if errorlevel 1 (
    echo.
    echo   That password was not accepted. The reason is above.
    echo.
    goto ask_password
)

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
