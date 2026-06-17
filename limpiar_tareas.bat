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
set LOG=logs\limpieza_%TS%.log

echo [%date% %time%] Iniciando limpieza de tareas auto-generadas >> "%LOG%"
echo Iniciando limpieza de tareas auto-generadas...

REM Por defecto corre en modo dry-run para evitar borrados accidentales.
REM Para borrar de verdad: limpiar_tareas.bat run
REM Para filtrar por task_code: limpiar_tareas.bat run ALTA_IMSS,BAJA_IMSS
if /i "%~1"=="run" (
    if not "%~2"=="" (
        %PYTHON% cleanup.py --task-code %~2 >> "%LOG%" 2>&1
    ) else (
        %PYTHON% cleanup.py >> "%LOG%" 2>&1
    )
) else (
    if not "%~1"=="" (
        %PYTHON% cleanup.py --dry-run --task-code %~1 >> "%LOG%" 2>&1
    ) else (
        %PYTHON% cleanup.py --dry-run >> "%LOG%" 2>&1
    )
)
set EXIT_CODE=%errorlevel%

if %EXIT_CODE% neq 0 (
    echo [%date% %time%] ERROR - codigo de salida: %EXIT_CODE% >> "%LOG%"
    echo ERROR en limpieza. Revisa: %LOG%
) else (
    echo [%date% %time%] Finalizado correctamente >> "%LOG%"
    echo Limpieza completada. Log: %LOG%
    if /i not "%~1"=="run" (
        echo (modo dry-run: no se borro nada. Usa "limpiar_tareas.bat run" para borrar de verdad)
    )
)

exit /b %EXIT_CODE%
