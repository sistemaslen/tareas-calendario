import os
import sys
import json
import logging
from datetime import date, datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.utils import add_business_days, get_week_bucket

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_onboarding_base.log')

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
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'RH', 'MEDIA', 'PENDIENTE',
              NULL, NULL, NULL, 'AUTO_ONBOARDING_BASE', ?);
END
"""

QUERY = """
SELECT e.id, e.employee_number, jh.onboarding_date,
       COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE jh.onboarding_date IS NOT NULL AND e.status = 'ALTA'
"""


def run():
    log.info('==== INICIO TAREAS ONBOARDING BASE ====')
    today = date.today()

    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(QUERY)
    rows = cursor.fetchall()
    cursor.close()

    for row in rows:
        emp_id, emp_num, onboarding_raw, full_name = row
        emp_name = full_name or ''

        if isinstance(onboarding_raw, datetime):
            onboarding_date = onboarding_raw.date()
        elif isinstance(onboarding_raw, date):
            onboarding_date = onboarding_raw
        else:
            try:
                onboarding_date = date.fromisoformat(str(onboarding_raw)[:10])
            except Exception:
                continue

        # Onboarding: rango de 3 dias habiles a partir de la fecha guardada de onboarding
        range_start = onboarding_date
        range_end   = add_business_days(onboarding_date, 3)
        anchor_str  = str(range_start)
        due_str     = str(range_end)
        bucket      = get_week_bucket(range_end, today)

        metadata = json.dumps({'rule': 'ONBOARDING_3_DIAS_HABILES', 'generated_at': datetime.now().isoformat(),
                               'range_start': anchor_str, 'range_end': due_str},
                              ensure_ascii=False)

        try:
            c = conn.cursor()
            c.execute(UPSERT_SQL, (
                'ONBOARDING_BASE', emp_id, anchor_str,
                due_str, bucket, emp_name,
                'Realizar onboarding del colaborador (3 dias habiles a partir de la fecha registrada de onboarding).',
                metadata,
                'ONBOARDING_BASE', emp_id, anchor_str,
                'ONBOARDING_BASE', 'Onboarding colaborador',
                'Realizar onboarding del colaborador (3 dias habiles a partir de la fecha registrada de onboarding).',
                emp_id, emp_num, emp_name, anchor_str, due_str, bucket, metadata,
            ))
            conn.commit()
            c.close()
            log.info('Upsert OK employee_id=%s onboarding=%s a %s bucket=%s', emp_id, anchor_str, due_str, bucket)
        except Exception as exc:
            log.error('Error upsert employee_id=%s: %s', emp_id, exc)

    conn.close()
    log.info('==== FIN TAREAS ONBOARDING BASE ====')


if __name__ == '__main__':
    run()
