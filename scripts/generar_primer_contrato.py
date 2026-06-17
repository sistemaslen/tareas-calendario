import os
import sys
import json
import logging
from datetime import date, datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.utils import get_week_bucket

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_primer_contrato.log')

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
              NULL, NULL, NULL, 'AUTO_PRIMER_CONTRATO', ?);
END
"""

QUERY = """
SELECT e.id, e.employee_number, jh.hire_date, jh.first_training_contract_end,
       COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE jh.first_training_contract_end IS NOT NULL AND e.status = 'ALTA'
"""


def run():
    log.info('==== INICIO TAREAS PRIMER CONTRATO ====')
    today = date.today()

    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(QUERY)
    rows = cursor.fetchall()
    cursor.close()

    for row in rows:
        emp_id, emp_num, hire_raw, contract_end_raw, full_name = row
        emp_name = full_name or ''

        if isinstance(hire_raw, datetime):
            hire_date = hire_raw.date()
        elif isinstance(hire_raw, date):
            hire_date = hire_raw
        else:
            try:
                hire_date = date.fromisoformat(str(hire_raw)[:10])
            except Exception:
                continue

        if isinstance(contract_end_raw, datetime):
            contract_end = contract_end_raw.date()
        elif isinstance(contract_end_raw, date):
            contract_end = contract_end_raw
        else:
            try:
                contract_end = date.fromisoformat(str(contract_end_raw)[:10])
            except Exception:
                continue

        hire_str         = str(hire_date)
        contract_end_str = str(contract_end)
        # Due date = mismo dia de ingreso (evento de un solo dia)
        bucket = get_week_bucket(hire_date, today)

        metadata = json.dumps({'rule': 'PRIMER_CONTRATO_DIA_INGRESO', 'generated_at': datetime.now().isoformat(),
                               'first_training_contract_end': contract_end_str}, ensure_ascii=False)

        try:
            c = conn.cursor()
            c.execute(UPSERT_SQL, (
                'PRIMER_CONTRATO', emp_id, hire_str,
                hire_str, bucket, emp_name,
                'Firmar y registrar el primer contrato el dia de ingreso del colaborador.',
                metadata,
                'PRIMER_CONTRATO', emp_id, hire_str,
                'PRIMER_CONTRATO', 'Revision de primer contrato',
                'Firmar y registrar el primer contrato el dia de ingreso del colaborador.',
                emp_id, emp_num, emp_name, hire_str, hire_str, bucket, metadata,
            ))
            conn.commit()
            c.close()
            log.info('Upsert OK employee_id=%s hire=%s bucket=%s', emp_id, hire_str, bucket)
        except Exception as exc:
            log.error('Error upsert employee_id=%s: %s', emp_id, exc)

    conn.close()
    log.info('==== FIN TAREAS PRIMER CONTRATO ====')


if __name__ == '__main__':
    run()
