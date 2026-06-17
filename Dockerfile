FROM python:3.11-slim

# Mismo huso horario que GestionTalento/incidencias-tasks (hora local de
# Mexico), para que fechas/horarios de notificaciones sean consistentes.
ENV TZ=America/Mexico_City

# ── Dependencias del sistema + ODBC Driver 18 para SQL Server ────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        gnupg2 \
        apt-transport-https \
        unixodbc \
        unixodbc-dev \
        tzdata \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list \
        -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ── Dependencias Python ───────────────────────────────────────────────────────
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# ── Codigo fuente ─────────────────────────────────────────────────────────────
COPY . .

# Por defecto corre la generacion + notificacion diaria
CMD ["sh", "-c", "python main_generar.py && python main_notificar.py"]
