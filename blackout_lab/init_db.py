import sqlite3
import os
import hashlib

db_path = os.path.join(os.path.dirname(__file__), 'database.db')
connection = sqlite3.connect(db_path)
connection.row_factory = sqlite3.Row
cur = connection.cursor()

# ── Drop existing tables ──────────────────────────────────────
for t in ['login_logs', 'comment_likes', 'post_likes', 'files', 'comments', 'notices', 'posts', 'sessions', 'users']:
    cur.execute(f"DROP TABLE IF EXISTS {t}")

# ── Users ─────────────────────────────────────────────────────
cur.execute('''
CREATE TABLE users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT NOT NULL UNIQUE,
    password    TEXT NOT NULL,
    name        TEXT NOT NULL,
    email       TEXT,
    role        TEXT DEFAULT 'user',
    bio         TEXT DEFAULT '',
    last_ip     TEXT DEFAULT '',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
''')

# ── Login Logs ────────────────────────────────────────────────
cur.execute('''
CREATE TABLE login_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    username    TEXT NOT NULL,
    ip_address  TEXT NOT NULL,
    user_agent  TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
''')

# ── Posts ─────────────────────────────────────────────────────
cur.execute('''
CREATE TABLE posts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    content     TEXT NOT NULL,
    author_id   INTEGER NOT NULL,
    author      TEXT NOT NULL,
    views       INTEGER DEFAULT 0,
    likes       INTEGER DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id)
)
''')

# ── Notices (공지사항) ──────────────────────────────────────────────
cur.execute('''
CREATE TABLE notices (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    content     TEXT NOT NULL,
    author_id   INTEGER NOT NULL,
    author      TEXT NOT NULL,
    views       INTEGER DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id)
)
''')

# ── Files (post/notice attachments) ──────────────────────────────────
cur.execute('''
CREATE TABLE files (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER,
    notice_id   INTEGER,
    filename    TEXT NOT NULL,
    orig_name   TEXT NOT NULL,
    size        INTEGER DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (notice_id) REFERENCES notices(id)
)
''')

# ── Comments (supports nested via parent_id) ──────────────────
cur.execute('''
CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    author_id   INTEGER NOT NULL,
    author      TEXT NOT NULL,
    content     TEXT NOT NULL,
    parent_id   INTEGER DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (author_id) REFERENCES users(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
)
''')

# ── Post Likes ─────────────────────────────────────────────────
cur.execute('''
CREATE TABLE post_likes (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    UNIQUE(post_id, user_id),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)
''')

# ── Seed data ─────────────────────────────────────────────────
admin_pw   = hashlib.md5('admin123'.encode()).hexdigest()   # [VULN] MD5 for intentional vulnerability
test_pw    = hashlib.md5('testpass'.encode()).hexdigest()
alice_pw   = hashlib.md5('alice1234'.encode()).hexdigest()

cur.execute("INSERT INTO users (username, password, name, email, role, last_ip) VALUES (?,?,?,?,?,?)",
            ('admin', admin_pw, '관리자', 'admin@example.com', 'admin', '127.0.0.1'))
cur.execute("INSERT INTO users (username, password, name, email, role, last_ip) VALUES (?,?,?,?,?,?)",
            ('testuser', test_pw, '테스터', 'test@example.com', 'user', '192.168.1.105'))
cur.execute("INSERT INTO users (username, password, name, email, role, last_ip) VALUES (?,?,?,?,?,?)",
            ('alice', alice_pw, '앨리스', 'alice@example.com', 'user', '10.0.2.14'))

cur.execute("INSERT INTO notices (title, content, author_id, author) VALUES (?,?,?,?)",
            ('[공지] 웹 해킹 스터디 카페 이용 안내', '본 카페는 의도적으로 취약하게 제작된 모의해킹 실습 환경입니다.\n학습 목적 외에는 사용하지 마세요.', 1, 'admin'))
cur.execute("INSERT INTO posts (title, content, author_id, author) VALUES (?,?,?,?)",
            ('가입 인사 드립니다!', '반갑습니다. 웹 보안 스터디를 위해 가입했습니다 😊', 2, 'testuser'))
cur.execute("INSERT INTO posts (title, content, author_id, author) VALUES (?,?,?,?)",
            ('SQL Injection 학습 자료 공유', 'PortSwigger Academy의 SQL Injection 트랙을 추천합니다!', 3, 'alice'))

cur.execute("INSERT INTO comments (post_id, author_id, author, content) VALUES (?,?,?,?)",
            (1, 3, 'alice', '환영합니다! 함께 공부해요 :)'))
cur.execute("INSERT INTO comments (post_id, author_id, author, content) VALUES (?,?,?,?)",
            (1, 1, 'admin', '잘 오셨습니다! 실습 랩을 마음껏 활용해 보세요.'))

connection.commit()
connection.close()
print("Database initialized successfully.")
