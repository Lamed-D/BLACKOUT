<?php
header('X-Content-Type-Options: nosniff');
session_start();
$settings = [
    'display_errors' => '오류 상세가 응답에 표시되면 파일 경로가 노출될 수 있습니다.',
    'log_errors' => '화면에서 감춘 오류도 서버에서는 조사할 수 있어야 합니다.',
    'expose_php' => '응답 헤더가 PHP 버전을 알려 주는지 확인하세요.',
    'session.cookie_httponly' => '개발자 도구에서 세션 쿠키의 HttpOnly 속성을 확인하세요.',
    'session.cookie_samesite' => '다른 사이트에서 시작한 요청에 적용되는 쿠키 정책을 확인하세요.',
];
?>
<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BLACKOUT · PHP 설정 관찰</title>
<style>body{max-width:54rem;margin:3rem auto;padding:0 1rem;font:1rem/1.7 system-ui}dt{margin-top:1rem;font-weight:bold}code{background:#edf2ec;padding:.2rem}</style>
<h1>PHP 설정 관찰</h1><p>아래 값은 이 요청을 처리한 PHP에서 읽었습니다. 브라우저 개발자 도구의 네트워크 탭에서 페이지를 새로 고치고 응답과 대조하세요.</p>
<dl><?php foreach ($settings as $key => $hint): ?>
<dt><?= htmlspecialchars($key) ?> <code><?= htmlspecialchars(ini_get($key) ?: 'Off') ?></code></dt><dd><?= htmlspecialchars($hint) ?></dd>
<?php endforeach; ?></dl>
<h2>콘텐츠 유형 검사</h2><p>응답 헤더에서 X-Content-Type-Options 값을 찾으세요. nosniff는 브라우저가 선언된 콘텐츠 유형을 따르도록 합니다.</p></html>
