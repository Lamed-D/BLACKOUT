import os
import pymysql
import pymysql.cursors

# ─── DB 연결 설정: 환경변수 → fallback 순으로 읽음 ───────────────────────
DB_CONFIG = {
    'host':     os.environ.get('DB_HOST', '127.0.0.1'),
    'port':     int(os.environ.get('DB_PORT', 3306)),
    'database': os.environ.get('DB_NAME', 'blackout_db'),
    'user':     os.environ.get('DB_USER', 'blackout_user'),
    'password': os.environ.get('DB_PASSWORD', 'blackout_pass'),
    'charset':  'utf8mb4',
    # DictCursor: dict처럼 컬럼명으로 접근 가능 (SQLite Row 객체와 동일한 사용법)
    'cursorclass': pymysql.cursors.DictCursor,
    'autocommit': False,
}

def get_db_connection():
    """
    MariaDB 연결을 반환합니다.
    SQLite의 conn.row_factory = sqlite3.Row 와 동일하게
    row['column_name'] 방식으로 데이터에 접근할 수 있습니다.
    """
    conn = pymysql.connect(**DB_CONFIG)
    return conn
