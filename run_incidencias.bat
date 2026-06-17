@echo off
setlocal
set TARGET_DIR=c:\apache\htdocs\incidencias-tasks
cd /d "%~dp0"

REM Resuelve Python del proyecto incidencias-tasks: venv local > sistema
if exist "%TARGET_DIR%\.venv\Scripts\python.exe" (
    set PYTHON=%TARGET_DIR%\.venv\Scripts\python.exe
) else (
    set PYTHON=python
)

if not exist "logs" mkdir logs

REM Rango de fechas opcional. Si se deja en blanco, se procesa solo el dia actual.
set FECHA_INICIO=%1
set FECHA_FIN=%2

if "%FECHA_INICIO%"=="" (
    set FECHA_ARGS=
) else (
    if "%FECHA_FIN%"=="" set FECHA_FIN=%FECHA_INICIO%
    set FECHA_ARGS=--fecha-inicio %FECHA_INICIO% --fecha-fin %FECHA_FIN%
)

REM Timestamp para el log
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format 'yyyyMMdd_HHmmss'"') do set TS=%%i
set LOG=%~dp0logs\incidencias_%TS%.log

echo [%date% %time%] Iniciando procesamiento de incidencias %FECHA_ARGS% >> "%LOG%"
echo Iniciando procesamiento de incidencias...

REM Solo retardos y faltas: la renovacion de dias de vacaciones
REM se maneja aparte con run_dias_otorgados.bat (1 vez al dia)
pushd "%TARGET_DIR%"
%PYTHON% main.py %FECHA_ARGS% --solo retardos >> "%LOG%" 2>&1
set EXIT_CODE=%errorlevel%

if %EXIT_CODE% equ 0 (
    %PYTHON% main.py %FECHA_ARGS% --solo faltas >> "%LOG%" 2>&1
    set EXIT_CODE=%errorlevel%
)
popd

if %EXIT_CODE% neq 0 (
    echo [%date% %time%] ERROR - codigo de salida: %EXIT_CODE% >> "%LOG%"
    echo ERROR en incidencias. Revisa: %LOG%
) else (
    echo [%date% %time%] Finalizado correctamente >> "%LOG%"
    echo Procesamiento completado. Log: %LOG%
)

exit /b %EXIT_CODE%
