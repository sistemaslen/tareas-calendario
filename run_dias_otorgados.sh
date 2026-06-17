#!/usr/bin/env bash
# Renueva saldos de vacaciones via Django manage.py renew_vacation_balances.
# Cron: 30 6 * * 1-6

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKEND_DIR="$SCRIPT_DIR/../backend"

mkdir -p "$SCRIPT_DIR/logs"

TS=$(date +"%Y%m%d_%H%M%S")
LOG="$SCRIPT_DIR/logs/dias_otorgados_${TS}.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

if [ -f "$BACKEND_DIR/venv/bin/python" ]; then
    PYTHON="$BACKEND_DIR/venv/bin/python"
else
    PYTHON="python3"
fi

log "Iniciando renovacion de dias de vacaciones"
cd "$BACKEND_DIR"
$PYTHON manage.py renew_vacation_balances >> "$LOG" 2>&1

log "Finalizado. Log: $LOG"
