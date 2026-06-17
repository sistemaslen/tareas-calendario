@echo off
setlocal
cd /d "%~dp0"

REM Resuelve Python: venv local > sistema
if exist ".venv\Scripts\python.exe" (
    set PYTHON=.venv\Scripts\python.exe
) else (
    set PYTHON=python
)

if not exist "logs" mkdir logs

REM Timestamp para el log
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format 'yyyyMMdd_HHmmss'"') do set TS=%%i
set LOG=logs\diario_%TS%.log

echo [%date% %time%] Iniciando generacion de tareas >> "%LOG%"
echo Iniciando generacion de tareas...

%PYTHON% main_generar.py >> "%LOG%" 2>&1
set EXIT_CODE=%errorlevel%

if %EXIT_CODE% neq 0 (
    echo [%date% %time%] ERROR en generacion - codigo de salida: %EXIT_CODE% >> "%LOG%"
    echo ERROR en generacion. Revisa: %LOG%
    exit /b %EXIT_CODE%
)

echo [%date% %time%] Generacion OK. Iniciando notificaciones >> "%LOG%"
echo Generacion OK. Iniciando notificaciones...

%PYTHON% main_notificar.py >> "%LOG%" 2>&1
set EXIT_CODE=%errorlevel%

if %EXIT_CODE% neq 0 (
    echo [%date% %time%] ERROR en notificaciones - codigo de salida: %EXIT_CODE% >> "%LOG%"
    echo ERROR en notificaciones. Revisa: %LOG%
) else (
    echo [%date% %time%] Finalizado correctamente >> "%LOG%"
    echo Proceso diario completado. Log: %LOG%
)

exit /b %EXIT_CODE%
