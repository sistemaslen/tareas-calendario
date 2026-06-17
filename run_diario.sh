#!/usr/bin/env bash
# Genera tareas del dia y envia notificaciones.
# Sin argumentos procesa el dia actual.
# Cron: 0 7 * * 1-6  y  0 14 * * 1-6

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

mkdir -p logs

TS=$(date +"%Y%m%d_%H%M%S")
LOG="$SCRIPT_DIR/logs/diario_${TS}.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

if [ -f "$SCRIPT_DIR/.venv/bin/python" ]; then
    PYTHON="$SCRIPT_DIR/.venv/bin/python"
else
    PYTHON="python3"
fi

log "Iniciando generacion de tareas"
$PYTHON main_generar.py >> "$LOG" 2>&1

log "Generacion OK. Enviando notificaciones"
$PYTHON main_notificar.py >> "$LOG" 2>&1

log "Finalizado. Log: $LOG"
