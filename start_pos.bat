@echo off
setlocal
title Padel07 POS
color 0A

REM =====================================================
REM  Edit these two lines if your XAMPP is elsewhere:
set XAMPP=C:\xampp
set APP=C:\xampp\htdocs\restaurant-pos
REM =====================================================

set PHP=%XAMPP%\php\php.exe
set URL=http://localhost/restaurant-pos/login.php

echo.
echo  ================================================
echo   Padel07 POS  ^|  Hasbaya Padel Club
echo  ================================================
echo.

REM === Verify XAMPP install ===
if not exist "%XAMPP%\apache\bin\httpd.exe" (
    echo  ERROR: XAMPP not found at %XAMPP%
    echo  Edit the XAMPP= line at the top of this file.
    echo.
    pause
    exit /b 1
)

REM === Start Apache ===
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I "httpd.exe" >NUL
if errorlevel 1 (
    echo  [1/3] Starting Apache...
    start "" /B "%XAMPP%\apache\bin\httpd.exe"
    timeout /t 2 /nobreak >NUL
) else (
    echo  [1/3] Apache  ^>  already running
)

REM === Start MySQL ===
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL
if errorlevel 1 (
    echo  [2/3] Starting MySQL...
    start "" /B "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini"
    timeout /t 3 /nobreak >NUL
) else (
    echo  [2/3] MySQL   ^>  already running
)

REM === Check for updates ===
echo  [3/3] Checking for updates...
if exist "%PHP%" (
    "%PHP%" "%APP%\updater.php"
) else (
    echo        PHP not found at %PHP% - skipping update check.
)

REM === Open browser ===
echo.
echo  Opening POS...
start "" "%URL%"

echo  Window closes in 4 seconds.
timeout /t 4 /nobreak >NUL
exit /b 0
