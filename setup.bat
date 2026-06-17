@echo off
setlocal
cd /d "%~dp0"

echo === Configuracion inicial talent-tasks-sync ===

REM Verifica Python
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python no encontrado. Instala Python 3.10+ y agregalo al PATH.
    pause
    exit /b 1
)

REM Crea venv si no existe
if not exist ".venv" (
    echo Creando entorno virtual...
    python -m venv .venv
)

REM Activa venv e instala dependencias
echo Instalando dependencias...
call .venv\Scripts\activate.bat
pip install -r requirements.txt

REM Crea carpeta de logs si no existe
if not exist "logs" mkdir logs

REM Crea .env si no existe
if not exist ".env" (
    copy .env.example .env
    echo.
    echo IMPORTANTE: Edita el archivo .env con tus credenciales reales.
    echo   - GT_DB_HOST, GT_DB_USER, GT_DB_PASSWORD
    echo   - GT_SMTP_USER, GT_SMTP_PASSWORD
)

echo.
echo Configuracion completada. Ejecuta run_diario.bat, run_incidencias.bat o run_dias_otorgados.bat para probar.
pause
