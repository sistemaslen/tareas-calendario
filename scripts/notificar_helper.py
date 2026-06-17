"""
Funciones compartidas por todos los scripts notificar_*.py
"""
import os
import sys
import logging
from datetime import date, datetime
from html import escape

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from scripts.utils import role_matches, normalize_text


def get_user_emails_by_roles(conn, role_targets: list[str]) -> list[str]:
    if not role_targets:
        return []

    sql = """
        SELECT DISTINCT u.email, r.name AS role_name
        FROM user_profiles up
        INNER JOIN roles r ON r.id = up.role_id
        INNER JOIN auth_user u ON u.id = up.user_id
        WHERE up.is_active = 1 AND r.is_active = 1 AND u.is_active = 1
          AND u.email IS NOT NULL AND LTRIM(RTRIM(u.email)) <> ''
    """
    cursor = conn.cursor()
    cursor.execute(sql)
    rows = cursor.fetchall()
    cursor.close()

    emails = []
    for row in rows:
        email, role_name = row
        for target in role_targets:
            if role_matches(str(role_name), target):
                emails.append(email.strip())
                break
    return emails


def find_recipients(conn, task_code: str, fallback_roles: list[str] = None,
                     always_roles: list[str] = None) -> list[str]:
    sql = """
        SELECT destination_type, destination_value
        FROM tareas_notificacion_destinos
        WHERE task_code = ? AND is_active = 1
    """
    cursor = conn.cursor()
    cursor.execute(sql, (task_code,))
    rows = cursor.fetchall()
    cursor.close()

    emails = []
    role_targets = []
    for dest_type, dest_value in rows:
        if dest_type == 'EMAIL':
            emails.append(dest_value.strip())
        elif dest_type == 'ROLE':
            role_targets.append(normalize_text(dest_value))

    if not role_targets and fallback_roles:
        role_targets = fallback_roles

    if always_roles:
        for role in always_roles:
            norm_role = normalize_text(role)
            if norm_role not in {normalize_text(r) for r in role_targets}:
                role_targets.append(norm_role)

    if role_targets:
        emails.extend(get_user_emails_by_roles(conn, role_targets))

    seen = set()
    result = []
    for e in emails:
        e = e.strip()
        if e and '@' in e and e not in seen:
            seen.add(e)
            result.append(e)
    return result


def load_tasks(conn, task_codes: list[str]) -> tuple[list, list, list, list]:
    """Retorna (vencidas, actuales, proximas, todas_ids_con_info) para los task_codes dados."""
    codes_placeholder = ','.join(['?' for _ in task_codes])
    sql = f"""
        SELECT id, employee_number, employee_name, hire_date, due_date, week_bucket, status
        FROM tareas_gestion_talento
        WHERE task_code IN ({codes_placeholder})
          AND week_bucket IN ('VENCIDA', 'ACTUAL', 'PROXIMA')
          AND status IN ('PENDIENTE', 'EN_PROCESO')
        ORDER BY
          CASE week_bucket WHEN 'VENCIDA' THEN 1 WHEN 'ACTUAL' THEN 2 WHEN 'PROXIMA' THEN 3 ELSE 4 END,
          due_date ASC, employee_number ASC
    """
    cursor = conn.cursor()
    cursor.execute(sql, task_codes)
    rows = cursor.fetchall()
    cursor.close()

    vencidas, actuales, proximas = [], [], []
    for row in rows:
        tid, emp_num, emp_name, hire_raw, due_raw, bucket, status = row
        item = {
            'id':              tid,
            'employee_number': emp_num or '',
            'employee_name':   emp_name or '',
            'hire_date':       _fmt_date(hire_raw),
            'due_date':        _fmt_date(due_raw),
            'status':          status or '',
        }
        if bucket == 'VENCIDA':
            vencidas.append(item)
        elif bucket == 'ACTUAL':
            actuales.append(item)
        else:
            proximas.append(item)

    return vencidas, actuales, proximas


def _fmt_date(raw) -> str:
    if isinstance(raw, (date, datetime)):
        return str(raw)[:10]
    return str(raw or '')[:10]


def update_notification_metadata(conn, task_codes: list[str]) -> None:
    codes_placeholder = ','.join(['?' for _ in task_codes])
    sql = f"""
        UPDATE tareas_gestion_talento
        SET metadata_json = JSON_MODIFY(
                COALESCE(metadata_json, '{{}}'),
                '$.last_weekly_notification_at',
                CONVERT(VARCHAR(19), GETDATE(), 126)
            )
        WHERE task_code IN ({codes_placeholder})
          AND week_bucket IN ('VENCIDA', 'ACTUAL', 'PROXIMA')
          AND status IN ('PENDIENTE', 'EN_PROCESO')
    """
    cursor = conn.cursor()
    cursor.execute(sql, task_codes)
    conn.commit()
    cursor.close()


# ─── HTML builder ────────────────────────────────────────────────────────────

def build_email_html(
    header_title: str,
    intro_text: str,
    vencidas: list,
    actuales: list,
    proximas: list,
    date_label_first: str = 'Fecha ingreso',
    date_key_first: str = 'hire_date',
) -> str:
    today = date.today().strftime('%d/%m/%Y')
    total = len(vencidas) + len(actuales) + len(proximas)

    def rows_html(items: list, empty_msg: str) -> str:
        if not items:
            return f'<tr><td colspan="5" style="padding:12px 14px;color:#9ca3af;font-size:13px;font-style:italic;">{empty_msg}</td></tr>'
        html = ''
        for t in items:
            hire_fmt = _format_display_date(t.get(date_key_first, ''))
            due_fmt  = _format_display_date(t.get('due_date', ''))
            status_labels = {'PENDIENTE': 'Pendiente', 'EN_PROCESO': 'En proceso',
                             'COMPLETADA': 'Completada', 'CANCELADA': 'Cancelada'}
            status_label = status_labels.get(t.get('status', ''), escape(t.get('status', '')))
            html += (
                f'<tr>'
                f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;">{escape(t["employee_number"])}</td>'
                f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#1f1754;font-weight:600;">{escape(t["employee_name"])}</td>'
                f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">{hire_fmt}</td>'
                f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#6b7280;">{due_fmt}</td>'
                f'<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:12px;color:#374151;">{status_label}</td>'
                f'</tr>'
            )
        return html

    def section(label: str, border: str, bg: str, text: str, sub_color: str, subtitle: str) -> str:
        return f"""
        <tr><td style="padding:20px 32px 0;">
          <div style="border-left:4px solid {border};background:{bg};border-radius:0 8px 8px 0;padding:12px 16px;">
            <div style="font-size:13px;font-weight:700;color:{text};letter-spacing:0.03em;">{label}</div>
            <div style="font-size:12px;color:{sub_color};margin-top:3px;">{subtitle}</div>
          </div>
        </td></tr>"""

    def table(rows: str) -> str:
        return f"""
        <tr><td style="padding:10px 32px 4px;">
          <table width="100%" cellpadding="0" cellspacing="0"
                 style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
            <thead><tr style="background:#f9fafb;">
              <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #e5e7eb;">No. Empleado</th>
              <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #e5e7eb;">Nombre</th>
              <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #e5e7eb;">{date_label_first}</th>
              <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #e5e7eb;">Fecha límite</th>
              <th style="padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid #e5e7eb;">Estatus</th>
            </tr></thead>
            <tbody>{rows}</tbody>
          </table>
        </td></tr>"""

    nv = len(vencidas)
    alert_banner = ''
    if nv > 0:
        label = f'{nv} tarea{"" if nv == 1 else "s"} vencida{"" if nv == 1 else "s"} — requiere atención inmediata'
        alert_banner = f"""<tr><td style="padding:0 32px 0;">
          <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;">
            <span style="font-size:18px;margin-right:10px;">⚠️</span>
            <span style="font-size:13px;color:#b91c1c;font-weight:600;">{label}</span>
          </div></td></tr>"""

    return f"""<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f1f0fb;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f0fb;padding:32px 16px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0"
       style="max-width:640px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(103,83,246,0.10);border:1px solid #e2daff;">
  <tr><td style="background:#6753f6;padding:28px 32px 24px;">
    <div style="font-size:10px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:rgba(255,255,255,0.65);margin-bottom:8px;">Sistema Integral · Gestión de Talento</div>
    <div style="font-size:22px;font-weight:700;color:#ffffff;line-height:1.25;">{header_title}</div>
    <div style="font-size:13px;color:rgba(255,255,255,0.75);margin-top:6px;">Generado el {today} · {total} {'tarea activa' if total == 1 else 'tareas activas'}</div>
  </td></tr>
  <tr><td style="padding:24px 32px 8px;">
    <p style="margin:0;font-size:14px;color:#374151;line-height:1.7;">{intro_text}</p>
  </td></tr>
  {alert_banner}
  <tr><td style="padding:16px 32px 0;"><div style="border-top:1px solid #ece9ff;"></div></td></tr>
  {section(f'⛔ Vencidas ({nv})', '#dc2626', '#fef2f2', '#b91c1c', '#ef4444', 'Pasaron los días hábiles sin registrar la entrega')}
  {table(rows_html(vencidas, 'Sin tareas vencidas'))}
  {section(f'🔴 Esta semana ({len(actuales)})', '#ea580c', '#fff7ed', '#c2410c', '#fb923c', 'Fecha límite dentro de los próximos 7 días')}
  {table(rows_html(actuales, 'Sin tareas para esta semana'))}
  {section(f'🔵 Próxima semana ({len(proximas)})', '#0284c7', '#f0f9ff', '#0369a1', '#38bdf8', 'Fecha límite en la semana siguiente')}
  {table(rows_html(proximas, 'Sin tareas para la próxima semana'))}
  <tr><td style="padding:20px 32px 0;"><div style="border-top:1px solid #ece9ff;"></div></td></tr>
  <tr><td style="padding:16px 32px 24px;">
    <p style="margin:0 0 10px;font-size:11px;color:#9ca3af;line-height:1.6;">
      Este mensaje fue generado automáticamente. Por favor no respondas a este correo.<br>
      © 2026 Grupo LEN · Talento Humano
    </p>
  </td></tr>
</table></td></tr></table>
</body></html>"""


def _format_display_date(val: str) -> str:
    if not val or len(val) < 10:
        return '—'
    try:
        d = date.fromisoformat(val[:10])
        return d.strftime('%d/%m/%Y')
    except Exception:
        return val
