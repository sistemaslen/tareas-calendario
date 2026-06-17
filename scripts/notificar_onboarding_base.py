import os
import sys
import logging

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.notificar_helper import find_recipients, load_tasks, update_notification_metadata
from scripts.notificaciones_db import insert_notificaciones

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_onboarding_base_notificacion.log')
logging.basicConfig(
    level=logging.INFO, format='%(asctime)s | %(message)s', datefmt='%Y-%m-%d %H:%M:%S',
    handlers=[logging.FileHandler(LOG_FILE, encoding='utf-8'), logging.StreamHandler()],
)
log = logging.getLogger(__name__)

TASK_CODE = 'ONBOARDING_BASE'


def run():
    log.info('==== INICIO NOTIFICACION ONBOARDING BASE ====')
    conn = get_connection()

    vencidas, actuales, proximas = load_tasks(conn, [TASK_CODE])
    all_tasks = vencidas + actuales + proximas

    if not all_tasks:
        log.info('Sin tareas pendientes para %s.', TASK_CODE)
        conn.close()
        return

    recipients = find_recipients(conn, TASK_CODE, fallback_roles=['RH', 'ADMIN'], always_roles=['ADMIN'])
    if not recipients:
        log.warning('Sin destinatarios configurados para %s — sin notificaciones in-app.', TASK_CODE)

    update_notification_metadata(conn, [TASK_CODE])
    inserted = insert_notificaciones(conn, all_tasks, recipients, TASK_CODE)

    log.info('Vencidas: %d | Actuales: %d | Próximas: %d | Notifs insertadas: %d',
             len(vencidas), len(actuales), len(proximas), inserted)
    log.info('==== FIN NOTIFICACION ONBOARDING BASE ====')
    conn.close()


if __name__ == '__main__':
    run()
