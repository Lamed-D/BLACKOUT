<?php
// ============================================================
// BLACKOUT PHP 엔진 — 공통 부트스트랩 (상용 DB=MariaDB / 시드 / 헬퍼 / 레이아웃)
//  ⚠️ 의도적으로 취약한 학습용 코드. 로컬에서만 구동할 것.
// ============================================================
session_start();

// ── MariaDB(PDO) 연결 — 준비될 때까지 재시도, 없으면 스키마+시드 생성 ──
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'blackout';
    $user = getenv('DB_USER') ?: 'blackout';
    $pass = getenv('DB_PASSWORD') ?: 'blackout_pass';
    $dsn  = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";

    $last = null;
    for ($i = 0; $i < 60; $i++) {
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            break;
        } catch (Throwable $e) { $last = $e; usleep(500000); }
    }
    if ($pdo === null) throw new RuntimeException('DB 연결 실패: ' . ($last ? $last->getMessage() : ''));

    init_db($pdo);
    return $pdo;
}

// ── 스키마 생성 + 시드 (멱등, 워커 간 GET_LOCK 으로 직렬화) ──────
function init_db(PDO $pdo): void {
    $pdo->query("SELECT GET_LOCK('blackout_init', 15)");
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL DEFAULT '', name VARCHAR(80) NOT NULL DEFAULT '',
            email VARCHAR(120), role VARCHAR(20) DEFAULT 'user', bio TEXT,
            points INT DEFAULT 1000, last_ip VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(500) NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS oauth_provider VARCHAR(20) NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS oauth_id VARCHAR(255) NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content TEXT NOT NULL,
            author_id INT NOT NULL, author VARCHAR(80) NOT NULL, views INT DEFAULT 0, likes INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
            id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, author_id INT NOT NULL,
            author VARCHAR(80) NOT NULL, content TEXT NOT NULL, parent_id INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, content TEXT NOT NULL,
            author_id INT NOT NULL, author VARCHAR(80) NOT NULL, views INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS files (
            id INT AUTO_INCREMENT PRIMARY KEY, post_id INT, notice_id INT,
            filename VARCHAR(255) NOT NULL, orig_name VARCHAR(255) NOT NULL, size INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_logs (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, username VARCHAR(80) NOT NULL,
            ip_address VARCHAR(50) NOT NULL, user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS post_likes (
            id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NOT NULL,
            UNIQUE KEY uq_post_user (post_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT,
            price INT NOT NULL, stock INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, product_id INT NOT NULL,
            quantity INT NOT NULL, unit_price INT NOT NULL, total INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ((int)$pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'] === 0) {
            // 비밀번호는 솔트 없는 MD5 — 의도적 취약
            $u = $pdo->prepare("INSERT INTO users (username,password,name,email,role,bio,points,last_ip) VALUES (?,?,?,?,?,?,?,?)");
            $u->execute(['admin',    md5('admin123'),  '관리자', 'admin@blackout.lab', 'admin', '', 999999, '127.0.0.1']);
            $u->execute(['testuser', md5('testpass'),  '테스터', 'test@blackout.lab',  'user',  '', 1000,   '192.168.1.105']);
            $u->execute(['alice',    md5('alice1234'), '앨리스', 'alice@blackout.lab', 'user',  '', 1500,   '10.0.2.14']);

            $pdo->prepare("INSERT INTO notices (title,content,author_id,author) VALUES (?,?,?,?)")
                ->execute(['[공지] BLACKOUT 실습 카페 이용 안내',
                           "본 카페는 의도적으로 취약하게 제작된 모의해킹 실습 환경입니다.\n학습 목적 외 사용을 금합니다.", 1, 'admin']);

            $p = $pdo->prepare("INSERT INTO posts (title,content,author_id,author) VALUES (?,?,?,?)");
            $p->execute(['가입 인사 드립니다!', '반갑습니다. 웹 보안 스터디를 위해 가입했습니다 😊', 2, 'testuser']);
            $p->execute(['SQL Injection 학습 자료 공유', 'PortSwigger Academy의 SQL Injection 트랙을 추천합니다!', 3, 'alice']);
            // 페이지네이션 체감용 샘플 글 다수
            $samples = ['환경 세팅 질문', 'XSS 필터 우회 팁', '파일 업로드 취약점 정리', 'JWT 디버깅 노트',
                        'SSRF 사례 공유', '관리자 페이지 접근 실습', '명령 주입 체크리스트', '역직렬화 입문',
                        'CORS 오설정 확인법', '비밀번호 재설정 흐름', 'IDOR 찾는 법', '세션 관리 베스트프랙티스'];
            foreach ($samples as $i => $t) {
                $p->execute([$t, '실습 메모 #' . ($i + 1) . ' — 자유롭게 토론해요.', ($i % 2) ? 3 : 2, ($i % 2) ? 'alice' : 'testuser']);
            }

            $c = $pdo->prepare("INSERT INTO comments (post_id,author_id,author,content) VALUES (?,?,?,?)");
            $c->execute([1, 3, 'alice', '환영합니다! 함께 공부해요 :)']);
            $c->execute([1, 1, 'admin', '잘 오셨습니다! 실습 랩을 마음껏 활용해 보세요.']);

            $pr = $pdo->prepare("INSERT INTO products (name,description,price,stock) VALUES (?,?,?,?)");
            $pr->execute(['실습 포인트 충전권', '포인트 1000 충전 쿠폰', 1000, 100]);
            $pr->execute(['BLACKOUT 후드티', '한정판 보안 스터디 굿즈', 35000, 20]);
            $pr->execute(['프리미엄 멘토링 1회', '1:1 웹해킹 멘토링', 200000, 5]);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('blackout_init')");
    }
}

// ── 헬퍼 ────────────────────────────────────────────────────
define('JWT_SECRET', 'secret');            // [VULN] 약한 시크릿
define('FLAG_PREFIX', 'BLACKOUT{php_');

function flag(string $t): string { return FLAG_PREFIX . $t . '}'; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function is_admin(): bool {
    // [VULN] 클라이언트 쿠키 role 값을 신뢰
    $role = $_COOKIE['role'] ?? ($_SESSION['role'] ?? 'user');
    return $role === 'admin';
}
function redirect(string $to): void { header("Location: $to"); exit; }

// ── 레이아웃 (base.html 계승) ───────────────────────────────
function layout_header(string $title = 'BLACKOUT'): void {
    $u = current_user();
    $admin = is_admin();
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . ' · BLACKOUT PHP</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">';
    echo '<link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="/community.css"><script src="/community.js" defer></script></head><body>';
    echo '<div class="bg-shape shape-a"></div><div class="bg-shape shape-b"></div>';
    echo '<nav class="navbar navbar-expand-lg custom-nav"><div class="container">';
    echo '<a class="navbar-brand" href="/"><i class="bi bi-shield-lock-fill" style="color:#2563eb"></i> BLACKOUT <span style="color:#2563eb">Security</span> Lab <span class="engine-badge engine-php ms-1">PHP</span></a>';
    echo '<div class="ms-auto d-flex align-items-center gap-2 flex-wrap">';
    if ($u) {
        echo '<span class="nav-link"><i class="bi bi-person-circle text-primary me-1"></i><b>' . h($u['username']) . '</b>님 · ' . (int)$u['points'] . 'P</span>';
        echo '<a class="nav-link text-warning" href="/post/write"><i class="bi bi-pencil-square"></i> 글쓰기</a>';
        echo '<a class="nav-link text-success" href="/shop"><i class="bi bi-bag"></i> 상점</a>';
        echo '<a class="nav-link text-info fw-bold" href="/practice"><i class="bi bi-shield-x"></i> 실습실</a>';
        echo '<a class="nav-link text-warning fw-bold" href="/thoughts"><i class="bi bi-lightbulb"></i> 사고들</a>';
        if ($admin) echo '<a class="nav-link text-danger fw-bold" href="/admin"><i class="bi bi-shield-lock-fill"></i> 관리자</a>';
        echo '<a class="nav-link" href="/profile?user_id=' . (int)$u['id'] . '"><i class="bi bi-person"></i> 프로필</a>';
        echo '<a class="nav-link" href="/account"><i class="bi bi-bell"></i> 마이페이지</a>';
        echo '<a class="nav-link text-danger" href="/logout"><i class="bi bi-box-arrow-right"></i> 로그아웃</a>';
    } else {
        echo '<a class="nav-link text-info fw-bold" href="/practice"><i class="bi bi-shield-x"></i> 실습실</a>';
        echo '<a class="nav-link text-warning fw-bold" href="/thoughts"><i class="bi bi-lightbulb"></i> 사고들</a>';
        echo '<a class="nav-link text-success fw-bold" href="/login"><i class="bi bi-box-arrow-in-right"></i> 로그인</a>';
        echo '<a class="nav-link" href="/register"><i class="bi bi-person-plus"></i> 회원가입</a>';
    }
    echo '</div></div></nav>';
    echo '<div class="container my-5" style="position:relative;z-index:1;"><div class="panel">';
    if (!empty($_SESSION['oauth_message'])) { echo '<div class="alert alert-info" role="status">' . h($_SESSION['oauth_message']) . '</div>'; unset($_SESSION['oauth_message']); }
}
function layout_footer(): void {
    echo '</div></div><footer class="footer-custom py-4 text-center mt-5"><div class="container">';
    echo '<p class="mb-0" style="color:#94a3b8;"><i class="bi bi-shield-lock me-1"></i> BLACKOUT PHP 엔진 · 로컬 학습 전용 · <a href="/practice">실습실</a></p>';
    echo '</div></footer>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}
