import os
from dotenv import load_dotenv

load_dotenv()


def get_email_config() -> dict:
    return {
        'host':       os.getenv('GT_SMTP_HOST',       'smtp.gmail.com'),
        'port':       int(os.getenv('GT_SMTP_PORT',   '465')),
        'username':   os.getenv('GT_SMTP_USER',       'noreply@len.com.mx'),
        'password':   os.getenv('GT_SMTP_PASSWORD',   'byjyoiewkfhiuqnw'),
        'from_email': os.getenv('GT_SMTP_FROM_EMAIL', 'noreply@len.com.mx'),
        'from_name':  os.getenv('GT_SMTP_FROM_NAME',  'RH Sistema'),
        'secure':     os.getenv('GT_SMTP_SECURE',     'ssl'),
    }
