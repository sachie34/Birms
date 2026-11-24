@echo off
setlocal
cd /d "%~dp0"

echo ============================================
echo BIRMS - XAMPP Local Setup
echo ============================================
echo.
echo This installer uses a separate database named barangay_db_work.
echo It does NOT import or modify barangay_db.
echo Start Apache and the intended local MySQL/MariaDB server before continuing.
echo The installer expects MySQL on 127.0.0.1 port 3306 by default.
echo Do not start two MySQL services on the same port.
echo.

rem Detect the XAMPP root from the project location:
rem <xampp-root>\htdocs\<project>\SETUP_XAMPP.bat
for %%I in ("%~dp0..\..") do set "XAMPP_ROOT=%%~fI"
set "PHP=%XAMPP_ROOT%\php\php.exe"

rem Keep compatibility with the common C:\xampp installation layout.
if not exist "%PHP%" set "PHP=C:\xampp\php\php.exe"

rem Last resort: use PHP already configured in the user's PATH.
if not exist "%PHP%" (
    where php >nul 2>nul
    if errorlevel 1 (
        echo ERROR: PHP was not found.
        echo Expected PHP beside this XAMPP installation: "%XAMPP_ROOT%\php\php.exe"
        echo Or install XAMPP with its PHP folder or add the correct PHP executable to PATH.
        pause
        exit /b 2
    )
    set "PHP=php"
)

echo Using PHP: %PHP%
echo.
"%PHP%" tools\setup_xampp.php
set "RESULT=%ERRORLEVEL%"

echo.
if "%RESULT%"=="0" (
    echo Setup script finished. Open the localhost URL printed above.
    echo Ensure Apache and MySQL are both running in the XAMPP Control Panel.
) else (
    echo Setup stopped with exit code %RESULT%.
    echo Read the error above; no existing app/config.local.php was overwritten.
)
echo.
pause
exit /b %RESULT%
