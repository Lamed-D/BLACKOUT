<?php
// PHP 전용 취약점: 타입 저글링(매직 해시) / extract() 변수 주입 / strcmp 배열 우회 / phpinfo 노출
//  ⚠️ 의도적으로 취약한 학습용 코드. 로컬 전용.
require_once __DIR__ . '/../lib.php';

// ── 1) 타입 저글링 — 매직 해시 (loose ==) ──────────────────────
// 저장된 관리자 토큰 해시(예: md5("QNKCDZO"))는 "0e..." 형태.
// 느슨한 비교(==)에서 "0e[숫자]" 해시는 0으로 취급되어, 또 다른 매직해시 입력으로 우회된다.
function page_verify_token(): void {
    $STORED = '0e830400451993494058024219903391'; // = md5('QNKCDZO')
    $msg = null; $ok = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['token'] ?? '';
        // [VULN] PHP 타입 저글링 — strict(===) 가 아닌 loose(==) 비교
        if (md5($token) == $STORED) { $ok = true; $msg = '토큰 검증 통과!'; }
        else { $msg = '토큰이 올바르지 않습니다. (md5=' . md5($token) . ')'; }
    }
    layout_header('관리자 토큰 검증');
    echo '<div class="card mx-auto" style="max-width:520px;"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-hash"></i> 관리자 토큰 검증</h4>';
    echo '<p class="text-secondary small">저장된 해시는 <code>0e…</code> 형태입니다. 타입 저글링(매직 해시)으로 우회해 보세요. 예: <code>240610708</code></p>';
    if ($msg) echo '<div class="alert ' . ($ok ? 'alert-success' : 'alert-warning') . '">' . h($msg) . '</div>';
    if ($ok) echo '<div class="alert alert-dark">' . h(flag('type_juggling_magic_hash')) . '</div>';
    echo '<form method="post" action="/verify_token"><input class="form-control mb-3" name="token" placeholder="토큰"><button class="btn btn-primary w-100">검증</button></form>';
    echo '</div></div>';
    layout_footer();
}

// ── 2) extract() 변수 주입 ────────────────────────────────────
// 사용자 입력 배열을 extract() 로 지역 변수에 풀어버려, 인증 플래그 변수를 덮어쓴다.
function page_settings_extract(): void {
    $is_admin = false;                 // 기본값
    // [VULN] extract() 로 사용자 입력을 변수로 전개 → $is_admin 덮어쓰기 가능
    extract($_GET);
    layout_header('환경설정');
    echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-sliders"></i> 환경설정</h4>';
    echo '<p class="text-secondary small">실습: <code>/settings?is_admin=1</code> 로 권한 플래그를 덮어써 보세요.</p>';
    if (!empty($is_admin)) echo '<div class="alert alert-dark">관리자 설정 잠금 해제됨 · ' . h(flag('extract_var_injection')) . '</div>';
    else echo '<div class="alert alert-secondary">일반 사용자 설정 화면입니다.</div>';
    echo '</div></div>';
    layout_footer();
}

// ── 3) 변수 함수 (Dynamic Call) — 임의 함수 실행 ───────────────
// 사용자 입력을 함수명으로 사용하는 변수 함수 호출 → 임의 PHP 함수 실행(RCE).
function page_dynamic_call(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $fn  = $_GET['fn']  ?? '';
    $arg = $_GET['arg'] ?? '';
    if ($fn === '') {
        echo "fn 파라미터가 필요합니다.\n예) /api/call?fn=phpinfo\n    /api/call?fn=system&arg=id\n    /api/call?fn=file_get_contents&arg=/etc/passwd";
        return;
    }
    // [VULN] 변수 함수 — 사용자 입력을 함수 이름으로 호출
    $out = ($arg === '') ? $fn() : $fn($arg);
    if ($fn === 'system' || $fn === 'passthru') return; // 이미 출력됨
    echo is_scalar($out) ? $out : var_export($out, true);
    echo "\n\n" . flag('variable_function_rce');
}

// ── 4) phpinfo() 정보 노출 ────────────────────────────────────
function page_phpinfo(): void {
    // [VULN] 운영 환경에 phpinfo() 노출 → 경로/확장/환경변수 등 정보 유출
    phpinfo();
}
