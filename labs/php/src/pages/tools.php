<?php
// 서버 도구: ping(명령주입) / download(경로탐색) / url_preview(SSRF·오픈리다이렉트) / xss(반사·DOM) / file(첨부)
require_once __DIR__ . '/../lib.php';

function page_ping(): void {
    $output = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ip = $_POST['ip'] ?? '';
        // [VULN] OS Command Injection — 입력을 셸에 그대로 전달
        $cmd = (stripos(PHP_OS, 'WIN') === 0) ? "ping -n 2 $ip" : "ping -c 2 $ip";
        $output = shell_exec($cmd . ' 2>&1');
    }
    layout_header('서버 진단(ping)');
    echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-terminal"></i> 서버 진단 도구</h4>';
    echo '<p class="text-secondary small">호스트로 ping 을 보냅니다. (명령 주입 실습: <code>127.0.0.1; id</code>)</p>';
    echo '<form method="post" action="/ping" class="d-flex gap-2 mb-3"><input class="form-control" name="ip" placeholder="127.0.0.1"><button class="btn btn-primary">실행</button></form>';
    if ($output !== '') echo '<pre>' . h($output) . '</pre>';
    echo '</div></div>';
    layout_footer();
}

function page_download(): void {
    $file = $_GET['file'] ?? '';
    if ($file === '') {
        layout_header('자료 다운로드');
        echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3">자료실</h4>';
        echo '<p class="text-secondary small">파일명을 지정해 다운로드합니다. (경로 탐색 실습: <code>?file=../lib.php</code>)</p>';
        echo '<a class="btn btn-outline-primary" href="/download?file=sample.txt">sample.txt 받기</a></div></div>';
        layout_footer(); return;
    }
    // [VULN] Path Traversal — 기준 디렉터리 검증 없이 경로 결합
    $path = '/var/www/html/uploads/' . $file;
    if (is_file($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($path); exit;
    }
    http_response_code(404); echo "파일을 찾을 수 없습니다: " . h($path);
}

function page_file(int $id): void {
    $st = db()->prepare("SELECT * FROM files WHERE id=?"); $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) { http_response_code(404); echo '파일 없음'; return; }
    $path = '/var/www/html/uploads/' . $f['filename'];
    if (!is_file($path)) { http_response_code(404); echo '파일 없음'; return; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($f['orig_name']) . '"');
    readfile($path); exit;
}

function page_url_preview(): void {
    $target = $_GET['url'] ?? '';
    if ($target === '') { echo 'No URL provided. 예: /utils/url_preview?url=http://127.0.0.1:8101/'; return; }
    // [VULN] SSRF / Open Redirect — 임의 URL 요청/리다이렉트
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: BLACKOUT-Lab\r\n"]]);
    $content = @file_get_contents($target, false, $ctx);
    if ($content !== false) { header('Content-Type: text/plain; charset=utf-8'); echo $content; exit; }
    header('Location: ' . $target); exit;   // [VULN] Open Redirect
}

function page_theme(): void {
    $name = $_GET['name'] ?? '';
    layout_header('테마 미리보기');
    echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-palette"></i> 테마 미리보기</h4>';
    echo '<p class="text-secondary small">테마 파일을 불러옵니다. (LFI 실습: <code>?name=/etc/passwd</code>, 소스: <code>?name=php://filter/convert.base64-encode/resource=lib.php</code>, 웹셸: <code>?name=uploads/shell.php</code>)</p>';
    echo '<form class="d-flex gap-2 mb-3" method="get" action="/theme"><input class="form-control" name="name" value="' . h($name) . '" placeholder="테마 이름 (예: lib.php)"><button class="btn btn-primary">불러오기</button></form>';
    if ($name !== '') {
        echo '<div class="border rounded p-3 bg-light"><pre>';
        @chdir('/var/www/html');
        include $name;   // [VULN] LFI — 사용자 입력을 그대로 include (경로/래퍼 제한 없음)
        echo '</pre></div>';
    }
    echo '</div></div>';
    layout_footer();
}

function page_xss(): void {
    $input = $_GET['input'] ?? '';
    layout_header('XSS 실습');
    echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-bug"></i> XSS 실습</h4>';
    echo '<form class="d-flex gap-2 mb-3" method="get" action="/xss"><input class="form-control" name="input" value="' . h($input) . '" placeholder="입력"><button class="btn btn-primary">반영</button></form>';
    echo '<div class="alert alert-secondary"><b>Reflected 출력:</b><br>' . $input . '</div>'; // [VULN] Reflected XSS
    // [VULN] DOM XSS — location.hash 를 innerHTML 로 삽입
    echo '<div class="alert alert-secondary"><b>DOM 출력(#해시):</b> <span id="domsink"></span></div>';
    echo '<p class="text-secondary small">DOM 실습: 주소 끝에 <code>#&lt;img src=x onerror=alert(1)&gt;</code> 추가</p>';
    echo '<script>document.getElementById("domsink").innerHTML = decodeURIComponent(location.hash.slice(1));</script>';
    echo '</div></div>';
    layout_footer();
}
