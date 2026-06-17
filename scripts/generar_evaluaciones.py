import os
import sys
import json
import logging
from datetime import date, datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.utils import get_week_bucket, start_of_week

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'tareas_evaluaciones.log')

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
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'RH', 'ALTA', 'PENDIENTE',
              NULL, NULL, NULL, ?, ?);
END
"""

QUERY = """
SELECT e.id AS employee_id, e.employee_number,
       jh.hire_date,
       jh.first_performance_evaluation_sent,
       jh.second_performance_evaluation_sent,
       COALESCE(gi.full_name, '') AS full_name
FROM employees e
INNER JOIN employee_job_history jh ON jh.employee_id = e.id
LEFT JOIN employee_general_info gi ON gi.employee_id = e.id
WHERE (jh.first_performance_evaluation_sent IS NOT NULL
    OR jh.second_performance_evaluation_sent IS NOT NULL)
  AND e.status = 'ALTA'
"""


def _parse_date(raw) -> date | None:
    if isinstance(raw, datetime):
        return raw.date()
    if isinstance(raw, date):
        return raw
    try:
        return date.fromisoformat(str(raw)[:10])
    except Exception:
        return None


def _upsert(conn, task_code, emp_id, emp_num, emp_name, anchor_str, due_str,
             bucket, title, description, source, metadata):
    try:
        c = conn.cursor()
        c.execute(UPSERT_SQL, (
            task_code, emp_id, anchor_str,
            due_str, bucket, emp_name, description, metadata,
            task_code, emp_id, anchor_str,
            task_code, title, description, emp_id, emp_num, emp_name,
            anchor_str, due_str, bucket, source, metadata,
        ))
        conn.commit()
        c.close()
        log.info('Upsert OK %s employee_id=%s due=%s bucket=%s', task_code, emp_id, due_str, bucket)
    except Exception as exc:
        log.error('Error upsert %s employee_id=%s: %s', task_code, emp_id, exc)


def run():
    log.info('==== INICIO TAREAS EVALUACIONES ====')
    today = date.today()

    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute(QUERY)
    rows = cursor.fetchall()
    cursor.close()

    for row in rows:
        emp_id, emp_num, hire_raw, eval1_raw, eval2_raw, full_name = row
        emp_name = full_name or ''

        # --- PRIMERA EVALUACION (evento de 1 dia, en la fecha guardada) ---
        hire_date = _parse_date(hire_raw)
        eval1_date = _parse_date(eval1_raw)
        if eval1_date:
            bucket1 = get_week_bucket(eval1_date, today)
            meta1 = json.dumps({'rule': 'PRIMERA_EVALUACION_DIA_UNICO', 'generated_at': datetime.now().isoformat(),
                                 'hire_date': str(hire_date) if hire_date else None, 'eval_date': str(eval1_date),
                                 'autoevaluacion': str(eval1_date)}, ensure_ascii=False)
            _upsert(conn, 'PRIMERA_EVALUACION', emp_id, emp_num, emp_name,
                    str(eval1_date), str(eval1_date), bucket1,
                    'Primera evaluacion y autoevaluacion',
                    'Realizar primera evaluacion y autoevaluacion del colaborador (fecha guardada de 1era evaluacion).',
                    'AUTO_PRIMERA_EVALUACION', meta1)

        # --- SEGUNDA EVALUACION (evento de 1 dia, en la fecha guardada) ---
        eval2_date = _parse_date(eval2_raw)
        if eval2_date:
            bucket2 = get_week_bucket(eval2_date, today)
            meta2 = json.dumps({'rule': 'SEGUNDA_EVALUACION_DIA_UNICO', 'generated_at': datetime.now().isoformat(),
                                 'eval_date': str(eval2_date),
                                 'autoevaluacion': str(eval2_date)}, ensure_ascii=False)
            _upsert(conn, 'SEGUNDA_EVALUACION', emp_id, emp_num, emp_name,
                    str(eval2_date), str(eval2_date), bucket2,
                    'Segunda evaluacion y autoevaluacion',
                    'Realizar segunda evaluacion y autoevaluacion del colaborador (fecha guardada de 2da evaluacion).',
                    'AUTO_SEGUNDA_EVALUACION', meta2)

    conn.close()
    log.info('==== FIN TAREAS EVALUACIONES ====')


if __name__ == '__main__':
    run()
