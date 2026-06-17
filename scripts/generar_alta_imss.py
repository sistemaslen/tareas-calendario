import os
import sys
import json
import logging
from datetime import date, datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.utils import add_business_days, get_week_bucket, start_of_week

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_imss.log')

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s | %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
    handlers=[logging.FileHandler(LOG_FILE, encoding='utf-8'), logging.StreamHandler()],
)
log = logging.getLogger(__name__)

UPSERT_SQL = """
IF EXISTS (
    SELECT 1 FROM tareas_gestion_talento
    WHERE task_code = ? AND employee_id = ? AND hire_date = ?
)
BEGIN
    UPDATE tareas_gestion_talento
    SET due_date = ?, week_bucket = ?, employee_name = ?,
        description = ?, metadata_json = ?, updated_at = GETDATE()
    WHERE task_code = ? AND employee_id = ? AND hire_date = ?;
END
ELSE
BEGIN
    INSERT INTO tareas_gestion_talento (
        task_code, title, description, employee_id, employee_number, employee_name,
        hire_date, due_date, week_bucket, owner_area, priority, status,
        status_changed_at, status_changed_by_user, status_changed_by_email,
        source, metadata_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NOMINAS', 'ALTA', 'PENDIENTE',
              NULL, NULL, NULL, 'AUTO_IMSS', ?);
END
"""

QUERY = """
SELECT e.id AS employee_id, e.employee_number,
       jh.hire_date, COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE e.status = 'ALTA' AND jh.hire_date IS NOT NULL
"""


def run():
    log.info('==== INICIO TAREAS IMSS ====')
    today = date.today()
    week_start = start_of_week(today)

    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(QUERY)
    rows = cursor.fetchall()
    cursor.close()

    buckets = {'VENCIDA': [], 'ACTUAL': [], 'PROXIMA': [], 'FUTURA': []}
    upserted = invalid = errors = 0
    window = {
        'current_week_start': str(week_start),
        'current_week_end':   str(week_start.replace(day=week_start.day + 6)),
    }

    for row in rows:
        emp_id, emp_num, hire_raw, full_name = row

        if isinstance(hire_raw, datetime):
            hire_date = hire_raw.date()
        elif isinstance(hire_raw, date):
            hire_date = hire_raw
        else:
            try:
                hire_date = date.fromisoformat(str(hire_raw)[:10])
            except Exception:
                invalid += 1
                continue

        due_date   = add_business_days(hire_date, 5)
        bucket     = get_week_bucket(due_date, today)
        hire_str   = str(hire_date)
        due_str    = str(due_date)
        emp_name   = full_name or ''

        description = 'Entregar Alta del IMSS a nominas dentro de los primeros 5 dias habiles desde el ingreso.'
        title       = 'Alta del IMSS (entrega a nominas)'
        metadata    = json.dumps({'rule': 'ALTA_IMSS_5_DIAS_HABILES', 'generated_at': datetime.now().isoformat(), 'window': window}, ensure_ascii=False)

        try:
            c = conn.cursor()
            c.execute(UPSERT_SQL, (
                'ALTA_IMSS', emp_id, hire_str,
                due_str, bucket, emp_name, description, metadata,
                'ALTA_IMSS', emp_id, hire_str,
                'ALTA_IMSS', title, description, emp_id, emp_num, emp_name,
                hire_str, due_str, bucket, metadata,
            ))
            conn.commit()
            c.close()
            upserted += 1
            buckets[bucket].append({'employee_id': emp_id, 'employee_number': emp_num,
                                    'employee_name': emp_name, 'hire_date': hire_str,
                                    'due_date': due_str})
        except Exception as exc:
            errors += 1
            log.error('Error upsert employee_id=%s: %s', emp_id, exc)

    conn.close()

    log.info('Filas leidas: %d | Descartados: %d | Errores: %d | Upserted: %d', len(rows), invalid, errors, upserted)
    for b, lst in buckets.items():
        log.info('  %s: %d', b, len(lst))
    log.info('==== FIN TAREAS IMSS ====')

    return buckets


if __name__ == '__main__':
    run()
