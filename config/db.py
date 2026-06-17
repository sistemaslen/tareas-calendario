import os
import pyodbc
from dotenv import load_dotenv

load_dotenv()


def get_connection() -> pyodbc.Connection:
    host = os.getenv('GT_DB_HOST', '192.168.0.137')
    port = os.getenv('GT_DB_PORT', '1433')
    db   = os.getenv('GT_DB_NAME', 'GestionTalento')
    user = os.getenv('GT_DB_USER') or os.getenv('DB_USER', 'integra')
    pwd  = os.getenv('GT_DB_PASSWORD') or os.getenv('DB_PASSWORD', 'hibrido')

    conn_str = (
        f'DRIVER={{ODBC Driver 18 for SQL Server}};'
        f'SERVER={host},{port};'
        f'DATABASE={db};'
        f'UID={user};'
        f'PWD={pwd};'
        f'TrustServerCertificate=yes;'
        f'CharSet=UTF-8;'
    )
    return pyodbc.connect(conn_str)
