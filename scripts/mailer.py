import smtplib
import ssl
import os
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.mime.base import MIMEBase
from email import encoders

import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config.email_config import get_email_config


def send_email_smtp(
    to_emails: list[str],
    subject: str,
    html_body: str,
    plain_body: str = '',
    attachments: list = None,
) -> None:
    cfg = get_email_config()
    recipients = [e.strip() for e in to_emails if e.strip()]
    if not recipients:
        raise ValueError('No hay destinatarios válidos.')

    msg = MIMEMultipart('alternative')
    msg['Subject'] = subject
    msg['From'] = f"{cfg['from_name']} <{cfg['from_email']}>"
    msg['To'] = ', '.join(recipients)

    text_part = plain_body if plain_body else _strip_tags(html_body)
    msg.attach(MIMEText(text_part, 'plain', 'utf-8'))
    msg.attach(MIMEText(html_body, 'html', 'utf-8'))

    if attachments:
        msg = _add_attachments(msg, attachments)

    context = ssl.create_default_context()

    if cfg['secure'].lower() == 'ssl':
        with smtplib.SMTP_SSL(cfg['host'], cfg['port'], context=context) as server:
            server.login(cfg['username'], cfg['password'])
            server.sendmail(cfg['from_email'], recipients, msg.as_string())
    else:
        with smtplib.SMTP(cfg['host'], cfg['port']) as server:
            server.starttls(context=context)
            server.login(cfg['username'], cfg['password'])
            server.sendmail(cfg['from_email'], recipients, msg.as_string())


def _add_attachments(msg: MIMEMultipart, attachments: list) -> MIMEMultipart:
    outer = MIMEMultipart('mixed')
    outer['Subject'] = msg['Subject']
    outer['From'] = msg['From']
    outer['To'] = msg['To']
    outer.attach(msg)

    for item in attachments:
        if isinstance(item, str):
            path, name = item, os.path.basename(item)
        elif isinstance(item, dict):
            path = item.get('path', '')
            name = item.get('name', os.path.basename(path))
        else:
            continue

        if not path or not os.path.isfile(path):
            continue

        with open(path, 'rb') as f:
            part = MIMEBase('application', 'octet-stream')
            part.set_payload(f.read())
        encoders.encode_base64(part)
        part.add_header('Content-Disposition', 'attachment', filename=name)
        outer.attach(part)

    return outer


def _strip_tags(html: str) -> str:
    import re
    return re.sub(r'<[^>]+>', '', html)
