@echo off
setlocal
set TARGET_DIR=c:\apache\htdocs\talent-management-app\backend
cd /d "%~dp0"

REM Resuelve Python del backend Django: venv local > sistema
if exist "%TARGET_DIR%\venv\Scripts\python.exe" (
    set PYTHON=%TARGET_DIR%\venv\Scripts\python.exe
) else (
    set PYTHON=python
)

if not exist "logs" mkdir logs

REM Timestamp para el log
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format 'yyyyMMdd_HHmmss'"') do set TS=%%i
set LOG=%~dp0logs\dias_otorgados_%TS%.log

echo [%date% %time%] Iniciando renovacion de dias de vacaciones >> "%LOG%"
echo Iniciando renovacion de dias de vacaciones...

pushd "%TARGET_DIR%"
%PYTHON% manage.py renew_vacation_balances >> "%LOG%" 2>&1
set EXIT_CODE=%errorlevel%
popd

if %EXIT_CODE% neq 0 (
    echo [%date% %time%] ERROR - codigo de salida: %EXIT_CODE% >> "%LOG%"
    echo ERROR en renovacion de vacaciones. Revisa: %LOG%
) else (
    echo [%date% %time%] Finalizado correctamente >> "%LOG%"
    echo Renovacion completada. Log: %LOG%
)

exit /b %EXIT_CODE%
