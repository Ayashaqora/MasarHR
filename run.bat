@echo off
rem MasarHR local launcher (S46). Delegates all checks/startup logic to run-local.ps1,
rem located next to this file regardless of the current working directory.
rem This file does not modify php.ini, .env, package.json or application source,
rem and does not run migrations/seed/optimize/cache-clear.

setlocal

set "SCRIPT_DIR=%~dp0"
set "PS1=%SCRIPT_DIR%run-local.ps1"

if not exist "%PS1%" (
    echo.
    echo ============================================================
    echo  FAILED: run-local.ps1 not found next to this file:
    echo  %PS1%
    echo ============================================================
    echo.
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PS1%"
set "RC=%ERRORLEVEL%"

if not "%RC%"=="0" (
    echo.
    echo ============================================================
    echo  FAILED: run-local.ps1 exited with code %RC%
    echo  The window will stay open so you can read the message above.
    echo ============================================================
    echo.
    pause
    exit /b %RC%
)

endlocal
exit /b 0
