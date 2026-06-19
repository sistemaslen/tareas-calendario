"""
Helper para insertar notificaciones in-app en la tabla notificaciones_app.
Los scripts notificar_*.py llaman a insert_notificaciones() tras enviar el correo.
"""
import logging
from datetime import datetime, timezone

logger = logging.getLogger(__name__)


def get_user_ids_by_emails(conn, emails: list[str]) -> dict[str, int]:
    """Retorna {email: user_id} para los emails dados que existan en auth_user."""
    if not emails:
        return {}

    placeholders = ','.join(['?' for _ in emails])
    sql = f"""
        SELECT id, email
        FROM auth_user
        WHERE LOWER(LTRIM(RTRIM(email))) IN ({placeholders})
          AND is_active = 1
    """
    lower_emails = [e.lower().strip() for e in emails]
    cursor = conn.cursor()
    cursor.execute(sql, lower_emails)
    rows = cursor.fetchall()
    cursor.close()
    return {row[1].lower().strip(): row[0] for row in rows}


def get_unread_pairs(conn, task_code: str, user_ids: list[int]) -> set[tuple[int, int]]:
    """Retorna {(user_id, tarea_id)} que ya tienen una notificación SIN LEER para este task_code."""
    if not user_ids:
        return set()

    placeholders = ','.join(['?' for _ in user_ids])
    sql = f"""
        SELECT user_id, tarea_id
        FROM notificaciones_app
        WHERE task_code = ? AND is_read = 0 AND user_id IN ({placeholders})
    """
    cursor = conn.cursor()
    cursor.execute(sql, [task_code, *user_ids])
    rows = cursor.fetchall()
    cursor.close()
    return {(row[0], row[1]) for row in rows}


def insert_notificaciones(
    conn,
    tasks: list[dict],
    recipient_emails: list[str],
    task_code: str,
    message_template: str = None,
) -> int:
    """
    Inserta una notificación por cada combinación (tarea, usuario_destinatario).

    tasks: lista de dicts con claves: id, employee_name, employee_number
    recipient_emails: emails de los usuarios que deben recibir la notificación
    task_code: código de tarea (ALTA_IMSS, BAJA_IMSS, etc.)
    message_template: template de mensaje. Si None, se genera uno por defecto.

    Si el usuario ya tiene una notificación SIN LEER para esa misma tarea,
    no se inserta otra (evita duplicados al correr el job a diario).

    Retorna el número de filas insertadas.
    """
    if not tasks or not recipient_emails:
        return 0

    user_map = get_user_ids_by_emails(conn, recipient_emails)
    if not user_map:
        logger.warning('No se encontraron usuarios en auth_user para los emails: %s', recipient_emails)
        return 0

    user_ids = list(user_map.values())
    unread_pairs = get_unread_pairs(conn, task_code, user_ids)

    sql = """
        INSERT INTO notificaciones_app
            (user_id, tarea_id, task_code, employee_name, employee_number, message, is_read, is_dismissed, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?)
    """

    now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    inserted = 0
    cursor = conn.cursor()

    for task in tasks:
        tarea_id = task.get('id')
        employee_name   = task.get('employee_name', '')
        employee_number = task.get('employee_number', '')

        if message_template:
            message = message_template.format(
                employee_name=employee_name,
                employee_number=employee_number,
                task_code=task_code,
            )
        else:
            message = f'{employee_name} — {task_code}'

        for email, user_id in user_map.items():
            if (user_id, tarea_id) in unread_pairs:
                continue
            try:
                cursor.execute(sql, (user_id, tarea_id, task_code,
                                     employee_name, employee_number, message, now))
                inserted += 1
            except Exception as exc:
                logger.error('Error insertando notificación user_id=%s tarea_id=%s: %s', user_id, tarea_id, exc)

    try:
        conn.commit()
    except Exception as exc:
        logger.error('Error en commit de notificaciones: %s', exc)

    cursor.close()
    return inserted
