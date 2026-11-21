<?php
// /api/* : JWT 검증 / CORS 오설정 / XXE / 역직렬화(객체주입) / SSTI 유사 / 쿠키 수집
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../jwt.php';

// [VULN] 역직렬화 가젯 — __wakeup 에서 cmd 실행 (PHP 객체 주입 시연)
class Evil {
    public $cmd = '';
    public $note = '';
    public function __wakeup() { if ($this->cmd) { echo "[gadget] " . shell_exec($this->cmd); } }
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}

function api_dispatch(string $action): void {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true) ?: [];

    switch ($action) {
        case 'jwt_verify':
            $token = $json['token'] ?? ($_POST['token'] ?? '');
            if ($token === '') json_out(['error' => 'No token'], 400);
            $p = jwt_decode($token);
            if ($p === null) json_out(['error' => 'invalid token'], 401);
            if (($p['role'] ?? '') === 'admin')
                json_out(['status' => 'SUCCESS', 'message' => '관리자 권한 획득!', 'flag' => flag('jwt_forged'), 'payload' => $p]);
            json_out(['status' => 'OK', 'message' => '일반 사용자 토큰', 'payload' => $p]);

        case 'user_data':
            // [VULN] CORS 오설정 — Origin 반사 + credentials 허용
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
            json_out(['sensitive' => true, 'api_key' => 'USER_API_KEY_54321', 'flag' => flag('cors_leak'), 'credit' => 500]);

        case 'import':
            // [VULN] XXE — 외부 엔티티 허용
            if ($raw === '') json_out(['error' => 'No XML'], 400);
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            // LIBXML_NOENT|LIBXML_DTDLOAD 로 외부 엔티티 해석 허용 → XXE
            $ok = $dom->loadXML($raw, LIBXML_NOENT | LIBXML_DTDLOAD);
            if (!$ok) json_out(['error' => 'XML parse error'], 500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "XML 파싱 결과:\n" . $dom->textContent; exit;

        case 'config':
            // [VULN] 안전하지 않은 역직렬화 (PHP 객체 주입)
            $data = $json['data'] ?? ($_POST['data'] ?? '');
            if ($data === '') json_out(['error' => 'No data'], 400);
            header('Content-Type: text/plain; charset=utf-8');
            $obj = unserialize(base64_decode($data));   // 신뢰 불가 데이터 역직렬화
            echo "역직렬화 완료: " . gettype($obj) . "\n" . print_r($obj, true); exit;

        case 'render':
            // [VULN] 템플릿 인젝션(SSTI 유사) — {{ expr }} 를 평가
            $theme = $json['theme'] ?? ($_GET['theme'] ?? '');
            header('Content-Type: text/plain; charset=utf-8');
            $out = preg_replace_callback('/\{\{(.+?)\}\}/', function ($m) {
                try { return (string)eval('return ' . $m[1] . ';'); } catch (Throwable $e) { return '[err]'; }
            }, $theme);
            echo $out; exit;

        case 'collect':
            // 저장형 XSS 쿠키 탈취 시연용 수집 엔드포인트 (로컬)
            $c = $_GET['c'] ?? '';
            @file_put_contents('/tmp/collected.log', date('c') . ' ' . $c . "\n", FILE_APPEND);
            json_out(['ok' => true]);

        default:
            json_out(['error' => 'unknown action'], 404);
    }
}
