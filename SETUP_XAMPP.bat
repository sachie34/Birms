@echo off
setlocal
cd /d "%~dp0"

echo ============================================
echo BIRMS - XAMPP Local Setup
echo ============================================
echo.
echo This installer uses a separate database named barangay_db_work.
echo It does NOT import or modify barangay_db.
echo Start MySQL in the XAMPP Control Panel before continuing.
echo.

set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" (
    where php >nul 2>nul
    if errorlevel 1 (
        echo ERROR: PHP was not found.
        echo Install XAMPP at C:\xampp or add PHP to PATH, then try again.
        pause
        exit /b 2
    )
    set "PHP=php"
)

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
