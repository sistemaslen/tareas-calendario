import os
import sys
import json
import logging
from datetime import date, datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.utils import get_week_bucket

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_segundo_contrato.log')

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
              NULL, NULL, NULL, 'AUTO_SEGUNDO_CONTRATO', ?);
END
"""

QUERY = """
SELECT e.id, e.employee_number,
       jh.second_training_contract_end,
       COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE jh.second_training_contract_end IS NOT NULL AND e.status = 'ALTA'
"""


def run():
    log.info('==== INICIO TAREAS SEGUNDO CONTRATO ====')
    today = date.today()

    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(QUERY)
    rows = cursor.fetchall()
    cursor.close()

    for row in rows:
        emp_id, emp_num, end_raw, full_name = row
        emp_name = full_name or ''

        def parse(raw):
            if isinstance(raw, datetime): return raw.date()
            if isinstance(raw, date): return raw
            try: return date.fromisoformat(str(raw)[:10])
            except Exception: return None

        end_date = parse(end_raw)
        if not end_date:
            continue

        # Evento de 1 dia: ancla = due_date = fin del segundo contrato
        anchor_str = str(end_date)
        due_str    = str(end_date)
        bucket     = get_week_bucket(end_date, today)

        metadata = json.dumps({'rule': 'SEGUNDO_CONTRATO_DIA_UNICO', 'generated_at': datetime.now().isoformat(),
                               'contract_date': due_str}, ensure_ascii=False)

        try:
            c = conn.cursor()
            c.execute(UPSERT_SQL, (
                'SEGUNDO_CONTRATO', emp_id, anchor_str,
                due_str, bucket, emp_name,
                'Gestionar segundo contrato en la fecha programada (evento de un solo dia).',
                metadata,
                'SEGUNDO_CONTRATO', emp_id, anchor_str,
                'SEGUNDO_CONTRATO', 'Revision de segundo contrato',
                'Gestionar segundo contrato en la fecha programada (evento de un solo dia).',
                emp_id, emp_num, emp_name, anchor_str, due_str, bucket, metadata,
            ))
            conn.commit()
            c.close()
            log.info('Upsert OK employee_id=%s anchor=%s due=%s bucket=%s', emp_id, anchor_str, due_str, bucket)
        except Exception as exc:
            log.error('Error upsert employee_id=%s: %s', emp_id, exc)

    conn.close()
    log.info('==== FIN TAREAS SEGUNDO CONTRATO ====')


if __name__ == '__main__':
    run()
