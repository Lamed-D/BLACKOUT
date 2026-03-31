-- MariaDB 초기 스키마 및 시드 데이터 (도커 컨테이너 최초 실행 시 자동 적용)
-- /docker-entrypoint-initdb.d/ 에 마운트하면 MariaDB가 자동으로 실행해 줌

USE blackout_db;

-- ── Drop existing tables (재시작 시 초기화) ──────────────────
DROP TABLE IF EXISTS login_logs;
DROP TABLE IF EXISTS post_likes;
DROP TABLE IF EXISTS files;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS notices;
DROP TABLE IF EXISTS posts;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS users;

-- ── Users ─────────────────────────────────────────────────────
CREATE TABLE users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(80)  NOT NULL UNIQUE,
    password       VARCHAR(255) NOT NULL DEFAULT '',
    name           VARCHAR(80)  NOT NULL,
    email          VARCHAR(120),
    role           VARCHAR(20)  DEFAULT 'user',
    bio            TEXT         DEFAULT '',
    last_ip        VARCHAR(50)  DEFAULT '',
    oauth_provider VARCHAR(20)  DEFAULT NULL COMMENT 'google / naver / null',
    oauth_id       VARCHAR(128) DEFAULT NULL COMMENT '소셜 공급자의 고유 사용자 ID',
    created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Login Logs ────────────────────────────────────────────────
CREATE TABLE login_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT,
    username    VARCHAR(80)  NOT NULL,
    ip_address  VARCHAR(50)  NOT NULL,
    user_agent  TEXT,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Posts ─────────────────────────────────────────────────────
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Notices (공지사항) ────────────────────────────────────────
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Files (post/notice attachments) ──────────────────────────
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Comments (supports nested via parent_id) ──────────────────
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Post Likes ─────────────────────────────────────────────────
CREATE TABLE post_likes (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    UNIQUE KEY uq_post_user (post_id, user_id),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seed Data (초기 계정 및 샘플 게시글) ──────────────────────
-- [VULN] MD5 해시 사용 (의도적 취약점)
-- admin123 → 0192023a7bbd73250516f069df18b500
-- testpass → 179ad45c6ce2cb97cf1029e212046e81
-- alice1234 → c3b1d57a02c8ec4a461f64f448a14795

INSERT INTO users (username, password, name, email, role, last_ip) VALUES
('admin',    '0192023a7bbd73250516f069df18b500', '관리자', 'admin@example.com',  'admin', '127.0.0.1'),
('testuser', '179ad45c6ce2cb97cf1029e212046e81', '테스터', 'test@example.com',   'user',  '192.168.1.105'),
('alice',    'c3b1d57a02c8ec4a461f64f448a14795', '앨리스', 'alice@example.com',  'user',  '10.0.2.14');

INSERT INTO notices (title, content, author_id, author) VALUES
('[공지] 웹 해킹 스터디 카페 이용 안내', '본 카페는 의도적으로 취약하게 제작된 모의해킹 실습 환경입니다.\n학습 목적 외에는 사용하지 마세요.', 1, 'admin');

INSERT INTO posts (title, content, author_id, author) VALUES
('가입 인사 드립니다!', '반갑습니다. 웹 보안 스터디를 위해 가입했습니다 😊', 2, 'testuser'),
('SQL Injection 학습 자료 공유', 'PortSwigger Academy의 SQL Injection 트랙을 추천합니다!', 3, 'alice');

INSERT INTO comments (post_id, author_id, author, content) VALUES
(1, 3, 'alice', '환영합니다! 함께 공부해요 :)'),
(1, 1, 'admin', '잘 오셨습니다! 실습 랩을 마음껏 활용해 보세요.');
