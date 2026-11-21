<?php
// 경량 JWT (HS256) — [VULN] 약한 시크릿('secret') + alg=none 허용
require_once __DIR__ . '/lib.php';

function b64url_encode(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_decode(string $s): string {
    $r = strlen($s) % 4; if ($r) $s .= str_repeat('=', 4 - $r);
    return base64_decode(strtr($s, '-_', '+/'));
}

function jwt_encode(array $payload, string $alg = 'HS256'): string {
    $h = b64url_encode(json_encode(['alg' => $alg, 'typ' => 'JWT']));
    $p = b64url_encode(json_encode($payload));
    if ($alg === 'none') return "$h.$p.";
    $sig = b64url_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    return "$h.$p.$sig";
}

// [VULN] alg=none 을 허용하고, HS256 은 약한 시크릿으로 검증
function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) < 2) return null;
    [$h, $p] = $parts; $sig = $parts[2] ?? '';
    $header = json_decode(b64url_decode($h), true);
    $payload = json_decode(b64url_decode($p), true);
    if (!is_array($header) || !is_array($payload)) return null;
    $alg = $header['alg'] ?? 'HS256';
    if ($alg === 'none') return $payload;                       // [VULN] 서명 검증 생략
    $expect = b64url_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (hash_equals($expect, $sig)) return $payload;
    return null;
}
