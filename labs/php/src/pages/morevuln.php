<?php
// 추가 취약점: ORDER BY 주입(#49) / 예측 가능한 재설정 토큰(#50)
//  ⚠️ 의도적으로 취약. 로컬 전용. (php://input LFI→RCE #51 은 tools.php 의 page_theme 가 allow_url_include 로 처리)
require_once __DIR__ . '/../lib.php';

function page_leaderboard(): void {
    // [VULN] ORDER BY 주입 — 정렬 파라미터를 쿼리에 직접 연결
    $sort = $_GET['sort'] ?? 'points DESC';
    $sql = "SELECT username, points FROM users ORDER BY $sort";
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // [VULN] verbose 오류 — error-based 추출(extractvalue)로 버전/데이터 유출
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'query' => $sql], JSON_UNESCAPED_UNICODE);
    }
}

function page_find_pw_weak(): void {
    $username = $_POST['username'] ?? ($_GET['username'] ?? '');
    if ($username === '') {
        echo '<form method="post"><input name="username" placeholder="아이디"><button>토큰 생성</button></form>';
        return;
    }
    // [VULN] 예측 가능한 토큰 — md5(username) 라 공격자가 그대로 계산 가능
    $token = md5($username);
    header('Content-Type: text/plain; charset=utf-8');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    echo "재설정 링크:\nhttp://$host/reset_pw?user=" . $username . "&token=$token\n(토큰 = md5(username) — 예측 가능)";
}
