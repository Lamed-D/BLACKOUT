const mysql = require('mysql2/promise');
const crypto = require('node:crypto');

const pool = mysql.createPool({
  host: process.env.DB_HOST || 'db',
  user: process.env.DB_USER || 'blackout',
  password: process.env.DB_PASSWORD || 'blackout_pass',
  database: process.env.DB_NAME || 'blackout',
  charset: 'utf8mb4',
  waitForConnections: true,
  connectionLimit: 10
});

// [VULN] 약한 비밀번호 해시 — 솔트 없는 MD5 (PHP 엔진과 동일, 의도적 취약)
function hashPassword(password) {
  return crypto.createHash('md5').update(String(password)).digest('hex');
}

function checkPassword(password, stored) {
  return hashPassword(password) === String(stored);
}

async function initialize() {
  let connection;
  for (let i = 0; i < 60; i++) {
    try { connection = await pool.getConnection(); break; }
    catch (error) { if (i === 59) throw error; await new Promise(resolve => setTimeout(resolve, 1000)); }
  }
  try {
    await connection.query("SELECT GET_LOCK('blackout_node_init', 20)");
    await connection.query(`CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE,
      password VARCHAR(255) NOT NULL, name VARCHAR(80) NOT NULL DEFAULT '',
      email VARCHAR(120), role VARCHAR(20) NOT NULL DEFAULT 'user', bio TEXT,
      points INT NOT NULL DEFAULT 1000, last_ip VARCHAR(50) NOT NULL DEFAULT '',
      avatar VARCHAR(500), oauth_provider VARCHAR(20), oauth_id VARCHAR(255),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query('ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(500) NULL');
    await connection.query('ALTER TABLE users ADD COLUMN IF NOT EXISTS oauth_provider VARCHAR(20) NULL');
    await connection.query('ALTER TABLE users ADD COLUMN IF NOT EXISTS oauth_id VARCHAR(255) NULL');
    await connection.query(`CREATE TABLE IF NOT EXISTS posts (
      id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content TEXT NOT NULL,
      author_id INT NOT NULL, author VARCHAR(80) NOT NULL, views INT NOT NULL DEFAULT 0,
      likes INT NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query(`CREATE TABLE IF NOT EXISTS comments (
      id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, author_id INT NOT NULL,
      author VARCHAR(80) NOT NULL, content TEXT NOT NULL, parent_id INT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query(`CREATE TABLE IF NOT EXISTS notices (
      id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content TEXT NOT NULL,
      author_id INT NOT NULL, author VARCHAR(80) NOT NULL, views INT NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query(`CREATE TABLE IF NOT EXISTS files (
      id INT AUTO_INCREMENT PRIMARY KEY, post_id INT, notice_id INT,
      filename VARCHAR(255) NOT NULL, orig_name VARCHAR(255) NOT NULL,
      size INT NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query(`CREATE TABLE IF NOT EXISTS login_logs (
      id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, username VARCHAR(80) NOT NULL,
      ip_address VARCHAR(50) NOT NULL, user_agent TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query(`CREATE TABLE IF NOT EXISTS post_likes (
      id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NOT NULL,
      UNIQUE KEY uq_post_user (post_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query(`CREATE TABLE IF NOT EXISTS products (
      id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL,
      description TEXT, price INT NOT NULL, stock INT NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    await connection.query(`CREATE TABLE IF NOT EXISTS orders (
      id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, product_id INT NOT NULL,
      quantity INT NOT NULL, unit_price INT NOT NULL, total INT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
    const [[count]] = await connection.execute('SELECT COUNT(*) AS total FROM users');
    if (count.total === 0) {
      const accounts = [
        ['admin', 'admin123', '관리자', 'admin@blackout.lab', 'admin', 999999],
        ['testuser', 'testpass', '테스터', 'test@blackout.lab', 'user', 1000],
        ['alice', 'alice1234', '앨리스', 'alice@blackout.lab', 'user', 1500]
      ];
      for (const [username, password, name, email, role, points] of accounts)
        await connection.execute('INSERT INTO users (username,password,name,email,role,points) VALUES (?,?,?,?,?,?)',
          [username, hashPassword(password), name, email, role, points]);
      await connection.execute('INSERT INTO notices (title,content,author_id,author) VALUES (?,?,?,?)',
        ['[공지] BLACKOUT 실습 카페 이용 안내', '본 카페는 로컬 학습 환경입니다. 함께 즐겁게 토론해 주세요.', 1, 'admin']);
      const seedPosts = [
        ['가입 인사 드립니다!', '반갑습니다. 웹 보안 스터디를 위해 가입했습니다 😊', 2, 'testuser'],
        ['SQL Injection 학습 자료 공유', 'PortSwigger Academy의 SQL Injection 트랙을 추천합니다!', 3, 'alice']
      ];
      for (const row of seedPosts)
        await connection.execute('INSERT INTO posts (title,content,author_id,author) VALUES (?,?,?,?)', row);
      const samples = ['환경 세팅 질문', 'XSS 필터 우회 팁', '파일 업로드 취약점 정리', 'JWT 디버깅 노트',
        'SSRF 사례 공유', '관리자 페이지 접근 실습', '명령 주입 체크리스트', '역직렬화 입문',
        'CORS 오설정 확인법', '비밀번호 재설정 흐름', 'IDOR 찾는 법', '세션 관리 베스트프랙티스'];
      for (let i = 0; i < samples.length; i++)
        await connection.execute('INSERT INTO posts (title,content,author_id,author) VALUES (?,?,?,?)',
          [samples[i], '실습 메모 #' + (i + 1) + ' — 자유롭게 토론해요.', i % 2 ? 3 : 2, i % 2 ? 'alice' : 'testuser']);
      await connection.execute(
        'INSERT INTO login_logs (user_id,username,ip_address,user_agent) VALUES (?,?,?,?)',
        [1, 'admin', '127.0.0.1', 'Mozilla/5.0 (관리자 콘솔)']);
      await connection.execute('INSERT INTO comments (post_id,author_id,author,content) VALUES (?,?,?,?)',
        [1, 3, 'alice', '환영합니다! 함께 공부해요 :)']);
      await connection.execute('INSERT INTO comments (post_id,author_id,author,content) VALUES (?,?,?,?)',
        [1, 1, 'admin', '잘 오셨습니다! 실습 랩을 마음껏 활용해 보세요.']);
      const products = [
        ['실습 포인트 충전권', '포인트 1000 충전 쿠폰', 1000, 100],
        ['BLACKOUT 후드티', '한정판 보안 스터디 굿즈', 35000, 20],
        ['프리미엄 멘토링 1회', '1:1 웹해킹 멘토링', 200000, 5]
      ];
      for (const row of products)
        await connection.execute('INSERT INTO products (name,description,price,stock) VALUES (?,?,?,?)', row);
    }
  } finally {
    if (connection) {
      await connection.query("SELECT RELEASE_LOCK('blackout_node_init')");
      connection.release();
    }
  }
}

module.exports = { pool, initialize, hashPassword, checkPassword };
