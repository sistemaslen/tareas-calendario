"""
Borra TODAS las tareas auto-generadas (source LIKE 'AUTO_%') y su historial.
Útil para ambientes de prueba cuando necesitas recrear tareas desde cero.

Uso:
    python cleanup.py            # borra directamente
    python cleanup.py --dry-run  # muestra qué borraría sin ejecutar
    python cleanup.py --task-code ALTA_IMSS,BAJA_IMSS  # filtra por task_code
"""
import sys
import argparse
import logging

sys.path.insert(0, __file__[: __file__.rfind('\\') + 1] if '\\' in __file__ else '.')
from config.db import get_connection

logging.basicConfig(level=logging.INFO, format='%(asctime)s | %(message)s', datefmt='%Y-%m-%d %H:%M:%S',
                    handlers=[logging.StreamHandler()])
log = logging.getLogger(__name__)


def main():
    parser = argparse.ArgumentParser(description='Limpia tareas auto-generadas de la BD.')
    parser.add_argument('--dry-run', action='store_true', help='Solo muestra qué se borraría.')
    parser.add_argument('--task-code', help='Coma-separado de task_codes. Por defecto: todos AUTO_*.')
    args = parser.parse_args()

    conn = get_connection()
    cursor = conn.cursor()

    # Construir WHERE
    if args.task_code:
        codes = [c.strip() for c in args.task_code.split(',')]
        placeholders = ','.join(['?' for _ in codes])
        where_extra = f" AND task_code IN ({placeholders})"
        params_ids   = codes
    else:
        where_extra = ''
        params_ids   = []

    count_sql = f"SELECT COUNT(*) FROM tareas_gestion_talento WHERE source LIKE 'AUTO_%'{where_extra}"
    cursor.execute(count_sql, params_ids)
    total = cursor.fetchone()[0]
    log.info('Tareas auto-generadas encontradas: %d', total)

    if total == 0:
        log.info('No hay tareas que limpiar.')
        conn.close()
        return

    # Detalles de lo que se borraría
    detail_sql = f"""
        SELECT task_code, COUNT(*) AS cnt
        FROM tareas_gestion_talento
        WHERE source LIKE 'AUTO_%'{where_extra}
        GROUP BY task_code ORDER BY task_code
    """
    cursor.execute(detail_sql, params_ids)
    for row in cursor.fetchall():
        log.info('  %-25s : %d tareas', row[0], row[1])

    if args.dry_run:
        log.info('[DRY-RUN] No se ejecutaron cambios.')
        conn.close()
        return

    # Borrar historial primero
    hist_sql = f"""
        DELETE FROM tareas_gestion_talento_historial
        WHERE tarea_id IN (
            SELECT id FROM tareas_gestion_talento
            WHERE source LIKE 'AUTO_%'{where_extra}
        )
    """
    cursor.execute(hist_sql, params_ids)
    hist_deleted = cursor.rowcount
    log.info('Historial eliminado: %d registros', hist_deleted)

    # Borrar notificaciones in-app referentes a estas tareas
    notif_sql = f"""
        DELETE FROM notificaciones_app
        WHERE tarea_id IN (
            SELECT id FROM tareas_gestion_talento
            WHERE source LIKE 'AUTO_%'{where_extra}
        )
    """
    try:
        cursor.execute(notif_sql, params_ids)
        notif_deleted = cursor.rowcount
        log.info('Notificaciones in-app eliminadas: %d registros', notif_deleted)
    except Exception as exc:
        log.warning('No se pudieron borrar notificaciones in-app: %s', exc)

    # Borrar tareas
    del_sql = f"DELETE FROM tareas_gestion_talento WHERE source LIKE 'AUTO_%'{where_extra}"
    cursor.execute(del_sql, params_ids)
    deleted = cursor.rowcount
    conn.commit()
    log.info('Tareas eliminadas: %d', deleted)

    cursor.close()
    conn.close()
    log.info('Limpieza completada.')


if __name__ == '__main__':
    main()
