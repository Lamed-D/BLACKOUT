import pymysql
import os
import hashlib
from dotenv import load_dotenv

# .env 파일이 있으면 로드 (로컬 실행 시 편리함)
load_dotenv()

def get_connection():
    return pymysql.connect(
        host=os.environ.get('DB_HOST', 'localhost'),
        port=int(os.environ.get('DB_PORT', 3306)),
        user=os.environ.get('DB_USER', 'blackout_user'),
        password=os.environ.get('DB_PASSWORD', 'blackout_pass'),
        database=os.environ.get('DB_NAME', 'blackout_db'),
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )

def init_db():
    conn = get_connection()
    cur = conn.cursor()

    print("── MariaDB 초기화 시작 ──")

    # 1. 기존 테이블 삭제
    tables = [
        'login_logs', 'post_likes', 'files', 'comments', 
        'notices', 'posts', 'sessions', 'users'
    ]
    cur.execute("SET FOREIGN_KEY_CHECKS = 0")
    for t in tables:
        cur.execute(f"DROP TABLE IF EXISTS {t}")
    cur.execute("SET FOREIGN_KEY_CHECKS = 1")
    print("기존 테이블 삭제 완료.")

    # 2. 테이블 생성 (SQL 파일에서 가져온 구조)
    
    # Users
    cur.execute('''
    CREATE TABLE users (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        username       VARCHAR(80)  NOT NULL UNIQUE,
        password       VARCHAR(255) NOT NULL DEFAULT '',
        name           VARCHAR(80)  NOT NULL,
        email          VARCHAR(120),
        role           VARCHAR(20)  DEFAULT 'user',
        bio            TEXT         DEFAULT '',
        last_ip        VARCHAR(50)  DEFAULT '',
        oauth_provider VARCHAR(20)  DEFAULT NULL,
        oauth_id       VARCHAR(128) DEFAULT NULL,
        created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ''')

    # Login Logs
    cur.execute('''
    CREATE TABLE login_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT,
        username    VARCHAR(80)  NOT NULL,
        ip_address  VARCHAR(50)  NOT NULL,
        user_agent  TEXT,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ''')

    # Posts
    cur.execute('''
    CREATE TABLE posts (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(200) NOT NULL,
        content     TEXT         NOT NULL,
        author_id   INT          NOT NULL,
        author      VARCHAR(80)  NOT NULL,
        views       INT          DEFAULT 0,
        likes       INT          DEFAULT 0,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (author_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ''')

    # Notices
    cur.execute('''
    CREATE TABLE notices (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(200) NOT NULL,
        content     TEXT         NOT NULL,
        author_id   INT          NOT NULL,
        author      VARCHAR(80)  NOT NULL,
        views       INT          DEFAULT 0,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (author_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ''')

    # Files
    cur.execute('''
    CREATE TABLE files (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        post_id     INT,
        notice_id   INT,
        filename    VARCHAR(255) NOT NULL,
        orig_name   VARCHAR(255) NOT NULL,
        size        INT          DEFAULT 0,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id)   REFERENCES posts(id),
        FOREIGN KEY (notice_id) REFERENCES notices(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ''')

    # Comments
    cur.execute('''
    CREATE TABLE comments (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        post_id     INT          NOT NULL,
        author_id   INT          NOT NULL,
        author      VARCHAR(80)  NOT NULL,
        content     TEXT         NOT NULL,
        parent_id   INT          DEFAULT NULL,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id)   REFERENCES posts(id),
        FOREIGN KEY (author_id) REFERENCES users(id),
        FOREIGN KEY (parent_id) REFERENCES comments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ''')

    # Post Likes
    cur.execute('''
    CREATE TABLE post_likes (
        id      INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        user_id INT NOT NULL,
        UNIQUE KEY uq_post_user (post_id, user_id),
        FOREIGN KEY (post_id) REFERENCES posts(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ''')
    print("스키마 생성 완료.")

    # 3. 시드 데이터 삽입
    admin_pw = hashlib.md5('admin123'.encode()).hexdigest()
    test_pw  = hashlib.md5('testpass'.encode()).hexdigest()
    alice_pw = hashlib.md5('alice1234'.encode()).hexdigest()

    # Users
    cur.execute("INSERT INTO users (username, password, name, email, role, last_ip) VALUES (%s,%s,%s,%s,%s,%s)",
                ('admin', admin_pw, '관리자', 'admin@example.com', 'admin', '127.0.0.1'))
    cur.execute("INSERT INTO users (username, password, name, email, role, last_ip) VALUES (%s,%s,%s,%s,%s,%s)",
                ('testuser', test_pw, '테스터', 'test@example.com', 'user', '192.168.1.105'))
    cur.execute("INSERT INTO users (username, password, name, email, role, last_ip) VALUES (%s,%s,%s,%s,%s,%s)",
                ('alice', alice_pw, '앨리스', 'alice@example.com', 'user', '10.0.2.14'))

    # Notices
    cur.execute("INSERT INTO notices (title, content, author_id, author) VALUES (%s,%s,%s,%s)",
                ('[공지] 웹 해킹 스터디 카페 이용 안내', '본 카페는 의도적으로 취약하게 제작된 모의해킹 실습 환경입니다.\n학습 목적 외에는 사용하지 마세요.', 1, 'admin'))
    
    # Posts
    cur.execute("INSERT INTO posts (title, content, author_id, author) VALUES (%s,%s,%s,%s)",
                ('가입 인사 드립니다!', '반갑습니다. 웹 보안 스터디를 위해 가입했습니다 😊', 2, 'testuser'))
    cur.execute("INSERT INTO posts (title, content, author_id, author) VALUES (%s,%s,%s,%s)",
                ('SQL Injection 학습 자료 공유', 'PortSwigger Academy의 SQL Injection 트랙을 추천합니다!', 3, 'alice'))

    # Comments
    cur.execute("INSERT INTO comments (post_id, author_id, author, content) VALUES (%s,%s,%s,%s)",
                (1, 3, 'alice', '환영합니다! 함께 공부해요 :)'))
    cur.execute("INSERT INTO comments (post_id, author_id, author, content) VALUES (%s,%s,%s,%s)",
                (1, 1, 'admin', '잘 오셨습니다! 실습 랩을 마음껏 활용해 보세요.'))

    conn.commit()
    cur.close(); conn.close()
    print("데이터베이스 초기화 성공!")

if __name__ == "__main__":
    try:
        init_db()
    except Exception as e:
        print(f"오류 발생: {e}")
