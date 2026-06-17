"""
Envía correo al jefe directo de cada colaborador cuya primera y/o segunda
evaluación de desempeño ya llegó (fecha <= hoy).

Solo se envía si ya existen los documentos generados de "Evaluación de Desempeño"
y "Autoevaluación de Desempeño" para el colaborador. Si faltan, se reintenta en
las siguientes corridas diarias hasta que existan.

Solo se consideran fechas de evaluación ya llegadas (fecha <= hoy) y con un
atraso de hasta VENTANA_NOTIFICACION_DIAS (30) días — esto evita notificar a
colaboradores antiguos cuyas evaluaciones quedaron en el pasado y que ya se
gestionaron por otros medios.

Cada colaborador se notifica solo una vez por ciclo (1ra / 2da evaluación) al
jefe directo. También inserta notificaciones in-app en notificaciones_app.
"""
import os
import sys
import logging
from datetime import date, datetime
from html import escape

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.db import get_connection
from scripts.mailer import send_email_smtp
from scripts.notificaciones_db import insert_notificaciones

LOG_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), 'logs', 'notificacion_jefe_evaluacion.log')
logging.basicConfig(
    level=logging.INFO, format='%(asctime)s | %(message)s', datefmt='%Y-%m-%d %H:%M:%S',
    handlers=[logging.FileHandler(LOG_FILE, encoding='utf-8'), logging.StreamHandler()],
)
log = logging.getLogger(__name__)


GENERATED_DOCS_ROOT_CANDIDATES = [
    os.getenv('GENERATED_DOCS_ROOT', '').strip(),
    os.getenv('GENERATED_DOCS_DIR', '').strip(),
    os.path.normpath(os.path.join(os.path.dirname(__file__), '..', '..', 'talent-management-app', 'backend', 'generated_documents')),
    '/app/media/generated_documents',
    os.path.join(os.path.dirname(os.path.dirname(__file__)), 'generated_documents'),
]

CICLOS = [
    ('PRIMERA_EVALUACION', 'eval1_raw', 'Primera evaluación', '1ª Evaluación'),
    ('SEGUNDA_EVALUACION', 'eval2_raw', 'Segunda evaluación', '2ª Evaluación'),
]

# Solo se notifica si la fecha de la evaluación ya llegó y no tiene más de
# esta cantidad de días de atraso. Evita notificar evaluaciones de
# colaboradores antiguos cuyas fechas quedaron muy en el pasado y que ya
# se gestionaron por otros medios en su momento.
VENTANA_NOTIFICACION_DIAS = 30


def _resolve_doc_path(relative_path: str) -> str | None:
    if not relative_path:
        return None
    rel = relative_path.strip().replace('\\', os.sep).replace('/', os.sep)
    for root in GENERATED_DOCS_ROOT_CANDIDATES:
        if not root:
            continue
        full = os.path.join(root, rel)
        if os.path.isfile(full):
            return full
    return None


def _find_document(conn, employee_id: int, name_like: str, exclude_like: str | None = None) -> dict | None:
    sql = """
        SELECT TOP 1 relative_path, file_name
        FROM employee_generated_documents
        WHERE employee_id = ?
          AND template_name COLLATE Latin1_General_CI_AI LIKE ?
    """
    params = [employee_id, name_like]
    if exclude_like:
        sql += " AND template_name COLLATE Latin1_General_CI_AI NOT LIKE ?"
        params.append(exclude_like)
    sql += " ORDER BY updated_at DESC, created_at DESC"

    cursor = conn.cursor()
    cursor.execute(sql, params)
    row = cursor.fetchone()
    cursor.close()
    if not row:
        return None
    rel_path, file_name = row
    full_path = _resolve_doc_path(str(rel_path or ''))
    if not full_path:
        return None
    return {'path': full_path, 'name': str(file_name or os.path.basename(full_path))}


def _find_evaluation_documents(conn, employee_id: int) -> dict | None:
    """Retorna {'evaluacion': {...}, 'autoevaluacion': {...}} solo si AMBOS documentos
    existen para el colaborador; de lo contrario None."""
    autoeval = _find_document(conn, employee_id, '%autoevaluacion%')
    evaluacion = _find_document(conn, employee_id, '%evaluacion%desempeno%', exclude_like='%autoevaluacion%')
    if not autoeval or not evaluacion:
        return None
    return {'evaluacion': evaluacion, 'autoevaluacion': autoeval}


def _already_notified_once(conn, employee_id: int, task_code: str) -> bool:
    sql = """
        SELECT TOP 1 1
                FROM tareas_gestion_talento t
                WHERE t.task_code = ?
                    AND t.employee_id = ?
                    AND (
                                JSON_VALUE(COALESCE(t.metadata_json, '{}'), '$.manager_eval_notified_once') = 'true'
                                OR EXISTS (
                                        SELECT 1
                                        FROM notificaciones_app n
                                        WHERE n.tarea_id = t.id
                                            AND n.task_code = ?
                                )
                            )
    """
    cursor = conn.cursor()
    cursor.execute(sql, (task_code, employee_id, task_code))
    row = cursor.fetchone()
    cursor.close()
    return row is not None


def _mark_notified_once(conn, employee_ids: list[int], task_code: str) -> None:
    unique_ids = sorted({int(eid) for eid in employee_ids if eid is not None})
    if not unique_ids:
        return

    placeholders = ','.join(['?' for _ in unique_ids])
    sql = f"""
        UPDATE tareas_gestion_talento
        SET metadata_json = JSON_MODIFY(
            JSON_MODIFY(COALESCE(metadata_json, '{{}}'), '$.manager_eval_notified_once', 'true'),
            '$.manager_eval_notified_at',
            CONVERT(VARCHAR(19), GETDATE(), 126)
        )
        WHERE task_code = ?
          AND employee_id IN ({placeholders})
          AND status IN ('PENDIENTE', 'EN_PROCESO')
    """
    cursor = conn.cursor()
    cursor.execute(sql, [task_code, *unique_ids])
    conn.commit()
    cursor.close()


def _get_task_ids_for_employees(conn, employee_ids: list[int], task_code: str) -> list[dict]:
    if not employee_ids:
        return []
    placeholders = ','.join(['?' for _ in employee_ids])
    sql = f"""
        SELECT id, employee_id, employee_name, employee_number
        FROM tareas_gestion_talento
        WHERE task_code = ?
          AND employee_id IN ({placeholders})
          AND status IN ('PENDIENTE', 'EN_PROCESO')
    """
    cursor = conn.cursor()
    cursor.execute(sql, [task_code, *employee_ids])
    rows = cursor.fetchall()
    cursor.close()
    return [{'id': r[0], 'employee_id': r[1], 'employee_name': r[2] or '', 'employee_number': r[3] or ''}
            for r in rows]


def _build_manager_html(manager_name: str, subordinates: list, today_str: str) -> str:
    rows = ''
    for s in subordinates:
        eval_fmt = datetime.strptime(s['eval_date'], '%Y-%m-%d').strftime('%d/%m/%Y') if s.get('eval_date') else '-'
        rows += (
            f'<tr>'
            f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;">{escape(s["employee_number"])}</td>'
            f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f1754;font-weight:600;">{escape(s["employee_name"])}</td>'
            f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">{escape(s["ciclo_label"])}</td>'
            f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">{eval_fmt}</td>'
            f'</tr>'
        )

    mgr_safe = escape(manager_name or 'Estimado/a jefe')
    return f"""<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>
<body style="font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f1f0fb;padding:24px;">
<table width="100%" style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2daff;border-radius:12px;padding:20px;">
<tr><td>
  <h2 style="margin:0;color:#1f1754;">Recordatorio: Evaluación de colaboradores a tu cargo</h2>
  <p style="color:#6b7280;margin:4px 0 16px;">Generado el {today_str}</p>
</td></tr>
<tr><td>
  <p style="color:#374151;">Hola {mgr_safe},</p>
  <p style="color:#374151;">El/los siguiente(s) colaborador(es) bajo tu supervisión tiene(n) lista su <strong>evaluación y autoevaluación</strong> de desempeño. Te adjuntamos los documentos generados para que los completes.</p>
</td></tr>
<tr><td>
  <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">
    <thead><tr style="background:#f9fafb;">
      <th style="padding:8px;text-align:left;">No. Empleado</th>
      <th style="padding:8px;text-align:left;">Nombre</th>
      <th style="padding:8px;text-align:left;">Tipo</th>
      <th style="padding:8px;text-align:left;">Fecha evaluación</th>
    </tr></thead>
    <tbody>{rows}</tbody>
  </table>
</td></tr>
<tr><td>
  <p style="color:#6b7280;font-size:12px;margin-top:20px;">
    Este mensaje es generado automáticamente por el sistema de Gestión de Talento.
    Si tienes dudas, contacta al área de Recursos Humanos.
  </p>
</td></tr>
</table></body></html>"""


def _d(raw):
    if isinstance(raw, datetime): return raw.date()
    if isinstance(raw, date): return raw
    try: return date.fromisoformat(str(raw)[:10])
    except Exception: return None


def run():
    log.info('==== INICIO NOTIFICACION JEFE EVALUACION ====')
    today = date.today()

    conn = get_connection()

    sql = """
    SELECT
        e.id AS employee_id,
        e.employee_number,
        e.manager_id,
        jh.first_performance_evaluation_sent AS eval1_date,
        jh.second_performance_evaluation_sent AS eval2_date,
        COALESCE(gi.full_name, '')   AS employee_name,
        COALESCE(mgi.full_name, '')  AS manager_name,
        COALESCE(mgi.corporate_email, '') AS manager_email
    FROM employees e
    INNER JOIN employee_job_history jh ON jh.employee_id = e.id
    LEFT JOIN employee_general_info gi  ON gi.employee_id  = e.id
    LEFT JOIN employee_general_info mgi ON mgi.employee_id = e.manager_id
    WHERE e.status = 'ALTA'
      AND e.manager_id IS NOT NULL
      AND (jh.first_performance_evaluation_sent IS NOT NULL
           OR jh.second_performance_evaluation_sent IS NOT NULL)
    ORDER BY mgi.full_name ASC
    """
    cursor = conn.cursor()
    cursor.execute(sql)
    rows = cursor.fetchall()
    cursor.close()

    # Agrupar por manager_email
    by_manager: dict[str, dict] = {}

    for row in rows:
        emp_id, emp_num, manager_id, eval1_raw, eval2_raw, emp_name, mgr_name, mgr_email = row
        mgr_email = (mgr_email or '').strip()
        if not mgr_email or '@' not in mgr_email:
            log.warning('Sin email válido para manager_id=%s (empleado %s) — omitido', manager_id, emp_num)
            continue

        ctx = {'eval1_raw': eval1_raw, 'eval2_raw': eval2_raw}

        for task_code, raw_key, ciclo_label, ciclo_short in CICLOS:
            eval_date = _d(ctx[raw_key])
            if not eval_date or eval_date > today:
                continue
            if (today - eval_date).days > VENTANA_NOTIFICACION_DIAS:
                log.info(
                    'Empleado %s (%s): fecha %s tiene mas de %d dias de atraso — se omite (ya gestionada por otros medios)',
                    emp_num, task_code, eval_date, VENTANA_NOTIFICACION_DIAS,
                )
                continue
            if _already_notified_once(conn, int(emp_id), task_code):
                continue

            docs = _find_evaluation_documents(conn, emp_id)
            if not docs:
                log.info('Empleado %s (%s): faltan documentos de evaluación/autoevaluación — se reintenta otro día',
                         emp_num, task_code)
                continue

            if mgr_email not in by_manager:
                by_manager[mgr_email] = {'manager_name': str(mgr_name or ''), 'items': {}, 'attachments': []}

            by_manager[mgr_email]['items'].setdefault(task_code, []).append({
                'employee_id':     emp_id,
                'employee_number': str(emp_num or ''),
                'employee_name':   str(emp_name or ''),
                'eval_date':       str(eval_date),
                'ciclo_label':     ciclo_short,
            })
            by_manager[mgr_email]['attachments'].append(docs['evaluacion'])
            by_manager[mgr_email]['attachments'].append(docs['autoevaluacion'])

    if not by_manager:
        log.info('No hay jefes con colaboradores listos para notificar. Sin envíos.')
        conn.close()
        return

    today_fmt = today.strftime('%d/%m/%Y')
    sent = 0

    for mgr_email, data in by_manager.items():
        items_by_code = data['items']
        attachments    = data['attachments']

        subordinates = [s for items in items_by_code.values() for s in items]

        # Deduplicar adjuntos
        unique_attachments, seen_paths = [], set()
        for att in attachments:
            p = att.get('path', '')
            if p and p not in seen_paths:
                seen_paths.add(p)
                unique_attachments.append(att)

        html = _build_manager_html(data['manager_name'], subordinates, today_fmt)
        plain = f"Tienes {len(subordinates)} colaborador(es) con evaluación lista para revisar. Adjuntos: {len(unique_attachments)}."
        subject = 'Recordatorio: Evaluación de tu(s) colaborador(es)'

        send_email_smtp([mgr_email], subject, html, plain, unique_attachments)
        log.info('Email enviado a %s (%s) — %d colaborador(es), adjuntos=%d',
                 mgr_email, data['manager_name'], len(subordinates), len(unique_attachments))

        for task_code, items in items_by_code.items():
            employee_ids = [s['employee_id'] for s in items]
            tasks_with_ids = _get_task_ids_for_employees(conn, employee_ids, task_code)
            if tasks_with_ids:
                insert_notificaciones(conn, tasks_with_ids, [mgr_email], task_code,
                                      message_template='Evaluación pendiente — {employee_name}')
            _mark_notified_once(conn, employee_ids, task_code)

        sent += 1

    log.info('==== FIN NOTIFICACION JEFE EVALUACION — %d emails enviados ====', sent)
    conn.close()


if __name__ == '__main__':
    run()
