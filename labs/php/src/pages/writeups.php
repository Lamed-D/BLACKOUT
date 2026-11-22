<?php
// 예제 공격 파일 다운로드(/exploit) + 사고들 페이지(/thoughts)
require_once __DIR__ . '/../lib.php';

function exploits_meta(): array {
    $d = json_decode(@file_get_contents(__DIR__ . '/../exploits.json'), true);
    return $d['files'] ?? [];
}

// 예제 파일 서빙: 일부는 .b64 로 저장(호스트 보안 오탐 방지) → 복원해서 text/plain 으로 제공
function page_exploit(): void {
    $name = basename($_GET['name'] ?? '');
    $meta = null;
    foreach (exploits_meta() as $f) if (($f['name'] ?? '') === $name) { $meta = $f; break; }
    if (!$meta) { http_response_code(404); echo '예제 파일을 찾을 수 없습니다.'; return; }
    $store = $meta['store'] ?? $meta['name'];
    $path = __DIR__ . '/../exploit_files/' . basename($store);
    if (!is_file($path)) { http_response_code(404); echo '예제 파일을 찾을 수 없습니다.'; return; }
    $data = file_get_contents($path);
    if (!empty($meta['encoded'])) $data = base64_decode($data);
    header('Content-Type: text/plain; charset=utf-8');
    if (empty($_GET['raw'])) header('Content-Disposition: attachment; filename="' . $name . '"');
    echo $data;
}

function page_thoughts(): void {
    $data = json_decode(@file_get_contents(__DIR__ . '/../approaches.json'), true);
    layout_header('사고들');
    if (!$data) { echo '<p>approaches.json 을 불러오지 못했습니다.</p>'; layout_footer(); return; }
    echo '<div class="card mb-4"><div class="card-body p-4"><h1 class="h3 fw-bold"><i class="bi bi-lightbulb text-warning"></i> 사고들 — AI 공격 접근 기록</h1>';
    echo '<p class="text-secondary mb-0">' . h($data['intro']) . '</p></div></div>';
    foreach ($data['groups'] as $group) {
        echo '<h4 class="fw-bold mt-4 mb-2">' . h($group['title']) . '</h4><div class="row g-3">';
        foreach ($group['entries'] as $e) {
            echo '<div class="col-lg-6"><div class="card h-100"><div class="card-body">';
            echo '<div class="d-flex justify-content-between align-items-start mb-1"><h5 class="fw-bold mb-0">' . h($e['title']) . '</h5><div class="text-nowrap">';
            foreach (($e['engines'] ?? []) as $en) echo '<span class="engine-badge engine-' . h($en) . '">' . h($en) . '</span> ';
            if (!empty($e['ref'])) echo '<a class="badge text-bg-info text-decoration-none" href="/practice#v' . (int)$e['ref'] . '">#' . (int)$e['ref'] . '</a>';
            echo '</div></div>';
            echo '<p class="small text-secondary mb-2"><b>가설:</b> ' . h($e['thought']) . '</p>';
            foreach (($e['steps'] ?? []) as $s) {
                echo '<div class="mb-2"><code class="d-block" style="white-space:pre-wrap">' . h($s['cmd']) . '</code>';
                echo '<div class="small text-success ps-2">↳ ' . h($s['result']) . '</div></div>';
            }
            echo '<p class="mb-0 mt-2"><span class="badge bg-dark">결과</span> ' . h($e['result']) . '</p>';
            echo '</div></div></div>';
        }
        echo '</div>';
    }
    echo '<p class="small text-secondary mt-4">전부 로컬 검증 결과입니다. 번호는 <a href="/practice">실습실</a> 과 연결됩니다.</p>';
    layout_footer();
}
