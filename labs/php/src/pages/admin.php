<?php
// 관리자: 쿠키 우회 접근 / 사용자·로그 검색(SQLi) / 권한변경(forced browsing) / 공지 / CSV 수식 인젝션
require_once __DIR__ . '/../lib.php';

function deny_admin(): void {
    http_response_code(403);
    layout_header('접근 거부');
    echo '<div class="card"><div class="card-body p-4"><h4 class="text-danger">접근 거부: 관리자(role=admin) 권한 필요</h4>';
    echo '<p>현재 권한: <code>' . h($_COOKIE['role'] ?? 'user') . '</code></p>';
    echo '<p class="text-secondary small">실습: 쿠키 <code>role=admin</code> 으로 바꾸면 접근됩니다.</p></div></div>';
    layout_footer();
}

function page_admin(): void {
    if (!is_admin()) { deny_admin(); return; }
    $pdo = db();
    $q_user = $_GET['q_user'] ?? ''; $q_log = $_GET['q_log'] ?? '';
    if ($q_user !== '') {
        // [VULN] SQL Injection (관리자 사용자 검색)
        $users = $pdo->query("SELECT id,username,email,role,points FROM users WHERE username LIKE '%$q_user%' OR email LIKE '%$q_user%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $users = $pdo->query("SELECT id,username,email,role,points FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($q_log !== '') {
        $logs = $pdo->query("SELECT * FROM login_logs WHERE username LIKE '%$q_log%' OR ip_address LIKE '%$q_log%' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $logs = $pdo->query("SELECT * FROM login_logs ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
    $stats = [
        'users' => $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'],
        'posts' => $pdo->query("SELECT COUNT(*) c FROM posts")->fetch()['c'],
        'orders'=> $pdo->query("SELECT COUNT(*) c FROM orders")->fetch()['c'],
    ];

    layout_header('관리자');
    echo '<div class="card mb-3"><div class="card-body"><h4 class="fw-bold"><i class="bi bi-shield-lock-fill text-danger"></i> 관리자 대시보드</h4>';
    echo '<p class="text-secondary small">민감정보: ' . h(flag('admin_cookie_bypass')) . '</p>';
    echo '<div class="d-flex gap-3"><span class="badge bg-primary">사용자 ' . (int)$stats['users'] . '</span><span class="badge bg-info">게시글 ' . (int)$stats['posts'] . '</span><span class="badge bg-success">주문 ' . (int)$stats['orders'] . '</span>';
    echo ' <a class="btn btn-sm btn-outline-secondary" href="/admin/export.csv">사용자 CSV 내보내기</a></div></div></div>';

    echo '<div class="card mb-3"><div class="card-body"><h5 class="fw-bold">사용자</h5>';
    echo '<form class="d-flex gap-2 mb-2" method="get" action="/admin"><input class="form-control" name="q_user" value="' . h($q_user) . '" placeholder="사용자 검색(SQLi)"><button class="btn btn-primary">검색</button></form>';
    echo '<table class="table table-sm"><thead><tr><th>ID</th><th>아이디</th><th>이메일</th><th>권한</th><th>P</th><th>작업</th></tr></thead><tbody>';
    foreach ($users as $x) {
        echo '<tr><td>' . h($x['id']) . '</td><td>' . h($x['username']) . '</td><td>' . h($x['email']) . '</td><td>' . h($x['role']) . '</td><td>' . h($x['points']) . '</td><td>';
        echo '<form method="post" action="/admin/user/' . (int)$x['id'] . '/role" class="d-inline"><input type="hidden" name="role" value="admin"><button class="btn btn-sm btn-outline-danger">admin화</button></form> ';
        echo '<form method="post" action="/admin/user/' . (int)$x['id'] . '/delete" class="d-inline"><button class="btn btn-sm btn-outline-secondary">삭제</button></form></td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<div class="card mb-3"><div class="card-body"><h5 class="fw-bold">공지 작성</h5>';
    echo '<form method="post" action="/admin/notice/create"><input class="form-control mb-2" name="title" placeholder="제목"><textarea class="form-control mb-2" name="content" placeholder="내용"></textarea><button class="btn btn-primary">등록</button></form></div></div>';

    echo '<div class="card"><div class="card-body"><h5 class="fw-bold">로그인 로그</h5>';
    echo '<form class="d-flex gap-2 mb-2" method="get" action="/admin"><input class="form-control" name="q_log" value="' . h($q_log) . '" placeholder="로그 검색(SQLi)"><button class="btn btn-primary">검색</button></form>';
    echo '<table class="table table-sm"><thead><tr><th>시각</th><th>아이디</th><th>IP</th><th>UA</th></tr></thead><tbody>';
    // [VULN] 저장형 XSS — User-Agent 를 이스케이프 없이 렌더(관리자 화면)
    foreach ($logs as $l) echo '<tr><td class="small">' . h($l['created_at']) . '</td><td>' . h($l['username']) . '</td><td>' . h($l['ip_address']) . '</td><td class="small">' . $l['user_agent'] . '</td></tr>';
    echo '</tbody></table></div></div>';
    layout_footer();
}

function page_admin_user_role(int $id): void {
    if (!is_admin()) { http_response_code(403); echo '권한 없음'; return; }
    $role = $_POST['role'] ?? 'user';
    db()->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $id]);
    redirect('/admin');
}
function page_admin_user_delete(int $id): void {
    if (!is_admin()) { http_response_code(403); echo '권한 없음'; return; }
    db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    redirect('/admin');
}
function page_admin_notice_create(): void {
    if (!is_admin()) { http_response_code(403); echo '권한 없음'; return; }
    $t = trim($_POST['title'] ?? ''); $c = trim($_POST['content'] ?? '');
    if ($t !== '' && $c !== '') db()->prepare("INSERT INTO notices (title,content,author_id,author) VALUES (?,?,?,?)")->execute([$t, $c, 1, 'admin']);
    redirect('/admin');
}

function page_admin_export(): void {
    if (!is_admin()) { http_response_code(403); echo '권한 없음'; return; }
    $rows = db()->query("SELECT id,username,email,role,points FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'username', 'email', 'role', 'points']);
    // [VULN] CSV/수식 인젝션 — =,+,-,@ 로 시작하는 값을 그대로 기록
    foreach ($rows as $r) fputcsv($out, [$r['id'], $r['username'], $r['email'], $r['role'], $r['points']]);
    fclose($out); exit;
}
