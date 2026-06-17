import os
import sys
import logging

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.notificar_helper import find_recipients, load_tasks, update_notification_metadata
from scripts.notificaciones_db import insert_notificaciones

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_evaluaciones_notificacion.log')
logging.basicConfig(
    level=logging.INFO, format='%(asctime)s | %(message)s', datefmt='%Y-%m-%d %H:%M:%S',
    handlers=[logging.FileHandler(LOG_FILE, encoding='utf-8'), logging.StreamHandler()],
)
log = logging.getLogger(__name__)

TASK_CODES = ['PRIMERA_EVALUACION', 'SEGUNDA_EVALUACION']


def run():
    log.info('==== INICIO NOTIFICACION EVALUACIONES ====')
    conn = get_connection()

    vencidas, actuales, proximas = load_tasks(conn, TASK_CODES)
    all_tasks = vencidas + actuales + proximas

    if not all_tasks:
        log.info('Sin tareas pendientes para evaluaciones.')
        conn.close()
        return

    # Destinatarios unificados de ambos task_codes
    to_primera = find_recipients(conn, 'PRIMERA_EVALUACION', fallback_roles=['RH', 'ADMIN'], always_roles=['ADMIN'])
    to_segunda  = find_recipients(conn, 'SEGUNDA_EVALUACION', fallback_roles=['RH', 'ADMIN'], always_roles=['ADMIN'])
    recipients  = list({e for e in to_primera + to_segunda})

    if not recipients:
        log.warning('Sin destinatarios configurados para evaluaciones — sin notificaciones in-app.')

    update_notification_metadata(conn, TASK_CODES)
    inserted = insert_notificaciones(conn, all_tasks, recipients, 'EVALUACION')

    log.info('Vencidas: %d | Actuales: %d | Próximas: %d | Notifs insertadas: %d',
             len(vencidas), len(actuales), len(proximas), inserted)
    log.info('==== FIN NOTIFICACION EVALUACIONES ====')
    conn.close()


if __name__ == '__main__':
    run()
