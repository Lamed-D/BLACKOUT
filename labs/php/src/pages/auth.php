<?php
// 인증/계정: 로그인(SQLi·쿠키권한) / 가입(Mass Assignment) / 찾기(열거·Host헤더) / CSRF / IDOR
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../jwt.php';

function page_login(): void {
    $error = null; $query = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? ''; $password = $_POST['password'] ?? '';
        // [VULN] SQL Injection — username/password 미파라미터화
        $query = "SELECT * FROM users WHERE username='$username' AND password='" . md5($password) . "'";
        try {
            $user = db()->query($query)->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            layout_header('로그인'); echo '<pre class="text-danger">DB 오류: ' . h($e->getMessage()) . "\n쿼리: " . h($query) . '</pre>'; layout_footer(); return;
        }
        if ($user) {
            $_SESSION['user_id'] = $user['id']; $_SESSION['user'] = $user['username']; $_SESSION['role'] = $user['role'];
            // [VULN] 클라이언트 쿠키로 권한 전달 + 약한 JWT
            setcookie('role', $user['role'], 0, '/');
            setcookie('jwt_token', jwt_encode(['username' => $user['username'], 'role' => $user['role']]), 0, '/');
            db()->prepare("INSERT INTO login_logs (user_id,username,ip_address,user_agent) VALUES (?,?,?,?)")
                ->execute([$user['id'], $user['username'], client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? '']);
            // [VULN] 세션 고정 — 로그인 성공 시 session_regenerate_id() 미호출
            // [VULN] 오픈 리다이렉트 — next 값을 검증 없이 사용
            $next = $_GET['next'] ?? ($_POST['next'] ?? '/');
            redirect($next);
        }
        $error = '아이디 또는 비밀번호가 틀렸습니다.';
    }
    layout_header('로그인');
    echo '<div class="card mx-auto" style="max-width:420px;"><div class="card-body p-4"><h4 class="fw-bold mb-3">로그인</h4>';
    if ($error) echo '<div class="alert alert-danger">' . h($error) . '</div>';
    if ($query) echo '<pre class="small">실행 쿼리: ' . h($query) . '</pre>'; // [VULN] 쿼리 노출
    echo '<form method="post" action="/login"><input type="hidden" name="next" value="' . h($_GET['next'] ?? '/') . '"><input class="form-control mb-2" name="username" placeholder="아이디">';
    echo '<input class="form-control mb-3" type="password" name="password" placeholder="비밀번호">';
    echo '<button class="btn btn-primary w-100">로그인</button></form>';
    echo '<div class="mt-3 small"><a href="/register">회원가입</a> · <a href="/find_id">아이디 찾기</a> · <a href="/find_pw">비밀번호 찾기</a></div>';
    echo '</div></div>';
    layout_footer();
}

function page_register(): void {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? ''); $password = $_POST['password'] ?? '';
        $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? '');
        // [VULN] Mass Assignment — role/points 를 사용자 입력에서 그대로 수용
        $role = $_POST['role'] ?? 'user';
        $points = (int)($_POST['points'] ?? 1000);
        if ($username === '' || $password === '') { $error = '아이디/비밀번호를 입력하세요.'; }
        else {
            $pdo = db();
            $ex = $pdo->prepare("SELECT id FROM users WHERE username=?"); $ex->execute([$username]);
            if ($ex->fetch()) { $error = '이미 사용 중인 아이디입니다.'; }
            else {
                $pdo->prepare("INSERT INTO users (username,password,name,email,role,points,last_ip) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$username, md5($password), $name, $email, $role, $points, client_ip()]);
                redirect('/login');
            }
        }
    }
    layout_header('회원가입');
    echo '<div class="card mx-auto" style="max-width:420px;"><div class="card-body p-4"><h4 class="fw-bold mb-3">회원가입</h4>';
    if ($error) echo '<div class="alert alert-danger">' . h($error) . '</div>';
    echo '<form method="post" action="/register"><input class="form-control mb-2" name="username" placeholder="아이디">';
    echo '<input class="form-control mb-2" name="name" placeholder="이름"><input class="form-control mb-2" name="email" placeholder="이메일">';
    echo '<input class="form-control mb-3" type="password" name="password" placeholder="비밀번호">';
    echo '<button class="btn btn-primary w-100">가입</button></form></div></div>';
    layout_footer();
}

function page_logout(): void {
    session_destroy();
    setcookie('role', 'user', 0, '/'); setcookie('jwt_token', '', time() - 1, '/');
    redirect('/');
}

function page_find_id(): void {
    $result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $st = db()->prepare("SELECT username FROM users WHERE email=?"); $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        // [VULN] 사용자 열거 — 존재/비존재 응답 차이
        $result = $u ? ('가입된 아이디: ' . $u['username']) : '해당 이메일로 가입된 계정이 없습니다.';
    }
    layout_header('아이디 찾기');
    echo '<div class="card mx-auto" style="max-width:420px;"><div class="card-body p-4"><h4 class="fw-bold mb-3">아이디 찾기</h4>';
    if ($result) echo '<div class="alert alert-info">' . h($result) . '</div>';
    echo '<form method="post" action="/find_id"><input class="form-control mb-3" name="email" placeholder="이메일">';
    echo '<button class="btn btn-primary w-100">찾기</button></form></div></div>';
    layout_footer();
}

function page_find_pw(): void {
    $message = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $st = db()->prepare("SELECT id FROM users WHERE username=?"); $st->execute([$username]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $_SESSION['reset_user_id'] = $u['id'];
            $token = bin2hex(random_bytes(8));
            // [VULN] Host 헤더 인젝션 — 재설정 링크에 신뢰 불가 Host 사용
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $message = "비밀번호 재설정 링크가 생성되었습니다:\nhttp://$host/reset_pw?token=$token";
        } else { $message = '아이디가 존재하지 않습니다.'; } // [VULN] 열거
    }
    layout_header('비밀번호 찾기');
    echo '<div class="card mx-auto" style="max-width:480px;"><div class="card-body p-4"><h4 class="fw-bold mb-3">비밀번호 찾기</h4>';
    if ($message) echo '<pre class="alert alert-info">' . h($message) . '</pre>';
    echo '<form method="post" action="/find_pw"><input class="form-control mb-3" name="username" placeholder="아이디">';
    echo '<button class="btn btn-primary w-100">재설정 링크 생성</button></form></div></div>';
    layout_footer();
}

function page_reset_pw(): void {
    if (empty($_SESSION['reset_user_id'])) { redirect('/find_pw'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        db()->prepare("UPDATE users SET password=? WHERE id=?")
            ->execute([md5($_POST['new_pw'] ?? ''), $_SESSION['reset_user_id']]);
        unset($_SESSION['reset_user_id']);
        redirect('/login');
    }
    layout_header('비밀번호 재설정');
    echo '<div class="card mx-auto" style="max-width:420px;"><div class="card-body p-4"><h4 class="fw-bold mb-3">새 비밀번호</h4>';
    echo '<form method="post" action="/reset_pw"><input class="form-control mb-3" type="password" name="new_pw" placeholder="새 비밀번호">';
    echo '<button class="btn btn-primary w-100">변경</button></form></div></div>';
    layout_footer();
}

function page_change_pw(): void {
    $u = current_user(); if (!$u) redirect('/login');
    // [VULN] CSRF — 토큰 없음, GET 으로도 비밀번호 변경 가능
    $new = $_GET['new_pw'] ?? ($_POST['new_pw'] ?? '');
    if ($new !== '') {
        db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([md5($new), $u['id']]);
        echo "<script>alert('비밀번호가 변경되었습니다.');location.href='/';</script>"; return;
    }
    layout_header('비밀번호 변경');
    echo '<div class="card mx-auto" style="max-width:420px;"><div class="card-body p-4"><h4 class="fw-bold mb-3">비밀번호 변경</h4>';
    echo '<p class="text-secondary small">※ CSRF 실습: GET <code>/change_pw?new_pw=해킹</code> 으로도 변경됩니다.</p>';
    echo '<form method="post" action="/change_pw"><input class="form-control mb-3" type="password" name="new_pw" placeholder="새 비밀번호">';
    echo '<button class="btn btn-primary w-100">변경</button></form></div></div>';
    layout_footer();
}

function page_profile(): void {
    // [VULN] IDOR — user_id 파라미터로 타인 프로필 열람, 권한 검사 없음
    $uid = $_GET['user_id'] ?? ($_SESSION['user_id'] ?? null);
    if (!$uid) redirect('/login');
    $st = db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$uid]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo '사용자를 찾을 수 없습니다.'; return; }
    layout_header('프로필');
    echo '<div class="card mx-auto" style="max-width:560px;"><div class="card-body p-4">';
    echo '<h4 class="fw-bold mb-3"><i class="bi bi-person-badge"></i> ' . h($user['username']) . ' 프로필</h4>';
    echo '<table class="table"><tr><th>ID</th><td>' . (int)$user['id'] . '</td></tr>';
    echo '<tr><th>이름</th><td>' . h($user['name']) . '</td></tr>';
    echo '<tr><th>이메일</th><td>' . h($user['email']) . '</td></tr>';
    echo '<tr><th>권한</th><td>' . h($user['role']) . '</td></tr>';
    echo '<tr><th>포인트</th><td>' . (int)$user['points'] . '</td></tr>';
    echo '<tr><th>소개</th><td>' . $user['bio'] . '</td></tr>'; // [VULN] Stored XSS (bio)
    echo '<tr><th>마지막 IP</th><td>' . h($user['last_ip']) . '</td></tr></table>';
    if (current_user() && current_user()['id'] == $user['id']) echo '<a class="btn btn-outline-primary" href="/profile/edit">프로필 수정</a>';
    echo '</div></div>';
    layout_footer();
}

function page_profile_edit(): void {
    $u = current_user(); if (!$u) redirect('/login');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // [VULN] Mass Assignment — role/points 도 폼으로 덮어쓰기 가능
        $role = $_POST['role'] ?? $u['role'];
        $points = isset($_POST['points']) ? (int)$_POST['points'] : $u['points'];
        db()->prepare("UPDATE users SET email=?, bio=?, role=?, points=? WHERE id=?")
            ->execute([$_POST['email'] ?? '', $_POST['bio'] ?? '', $role, $points, $u['id']]);
        redirect('/profile?user_id=' . $u['id']);
    }
    layout_header('프로필 수정');
    echo '<div class="card mx-auto" style="max-width:480px;"><div class="card-body p-4"><h4 class="fw-bold mb-3">프로필 수정</h4>';
    echo '<form method="post" action="/profile/edit"><input class="form-control mb-2" name="email" value="' . h($u['email']) . '" placeholder="이메일">';
    echo '<textarea class="form-control mb-3" name="bio" placeholder="소개 (HTML 허용 — 저장형 XSS)">' . h($u['bio']) . '</textarea>';
    echo '<button class="btn btn-primary w-100">저장</button></form></div></div>';
    layout_footer();
}
