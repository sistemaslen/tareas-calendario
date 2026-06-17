import os
import sys
import json
import logging
from datetime import date, datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.utils import get_week_bucket

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_firma_contrato.log')

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
              NULL, NULL, NULL, 'AUTO_FIRMA_CONTRATO', ?);
END
"""

QUERY = """
SELECT e.id, e.employee_number, jh.determined_indeterminate_contract_date,
       COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE jh.determined_indeterminate_contract_date IS NOT NULL AND e.status = 'ALTA'
"""


def run():
    log.info('==== INICIO TAREAS FIRMA CONTRATO ====')
    today = date.today()

    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(QUERY)
    rows = cursor.fetchall()
    cursor.close()

    for row in rows:
        emp_id, emp_num, firma_raw, full_name = row
        emp_name = full_name or ''

        if isinstance(firma_raw, datetime):
            firma_date = firma_raw.date()
        elif isinstance(firma_raw, date):
            firma_date = firma_raw
        else:
            try:
                firma_date = date.fromisoformat(str(firma_raw)[:10])
            except Exception:
                continue

        firma_str = str(firma_date)
        bucket    = get_week_bucket(firma_date, today)

        metadata = json.dumps({'rule': 'FIRMA_CONTRATO_FECHA_GUARDADA', 'generated_at': datetime.now().isoformat(),
                               'firma_date': firma_str}, ensure_ascii=False)

        try:
            c = conn.cursor()
            c.execute(UPSERT_SQL, (
                'FIRMA_CONTRATO', emp_id, firma_str,
                firma_str, bucket, emp_name,
                'Firma de contrato indeterminado o determinado en la fecha registrada del colaborador.',
                metadata,
                'FIRMA_CONTRATO', emp_id, firma_str,
                'FIRMA_CONTRATO', 'Firma de contrato definitivo',
                'Firma de contrato indeterminado o determinado en la fecha registrada del colaborador.',
                emp_id, emp_num, emp_name, firma_str, firma_str, bucket, metadata,
            ))
            conn.commit()
            c.close()
            log.info('Upsert OK employee_id=%s firma=%s bucket=%s', emp_id, firma_str, bucket)
        except Exception as exc:
            log.error('Error upsert employee_id=%s: %s', emp_id, exc)

    conn.close()
    log.info('==== FIN TAREAS FIRMA CONTRATO ====')


if __name__ == '__main__':
    run()
