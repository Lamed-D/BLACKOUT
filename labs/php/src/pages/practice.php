<?php
// 실습실: vulns.json 을 읽어 카테고리 탭 + 아코디언 가이드로 렌더 (데이터 주도)
require_once __DIR__ . '/../lib.php';

function page_practice(): void {
    $data = json_decode(@file_get_contents(__DIR__ . '/../vulns.json'), true);
    layout_header('모의해킹 실습실');
    if (!$data) { echo '<p>vulns.json 을 불러오지 못했습니다.</p>'; layout_footer(); return; }

    $cats = $data['categories']; $vulns = $data['vulns'];
    echo '<div class="card section-sqli mb-4"><div class="card-header"><h3 class="fw-bold mb-0" style="color:var(--c-blue)"><i class="bi bi-shield-shaded"></i> ' . h($data['meta']['title']) . '</h3>';
    echo '<small class="text-muted">' . h($data['meta']['note']) . '</small></div>';
    echo '<div class="card-body p-4">';

    echo '<div class="d-flex vuln-tabs flex-wrap mb-4">';
    foreach ($cats as $i => $c)
        echo '<button class="btn-tab' . ($c['key'] === 'all' ? ' active' : '') . '" data-cat="' . h($c['key']) . '" onclick="filterVuln(this)">' . h($c['label']) . '</button>';
    echo '</div>';
    echo '<p class="mb-4 text-secondary">총 <strong>' . count($vulns) . '종</strong> 취약점. 각 항목을 펼쳐 목표·단계·페이로드·대응책을 확인하세요.</p>';

    echo '<div class="accordion" id="vulnAcc">';
    foreach ($vulns as $v) {
        $diff = $v['difficulty'];
        $dmap = ['easy' => 'diff-easy', 'medium' => 'diff-medium', 'hard' => 'diff-hard', 'expert' => 'diff-expert'];
        echo '<div class="accordion-item vuln-row" data-cat="' . h($v['category']) . '">';
        echo '<h2 class="accordion-header"><button class="accordion-button collapsed" data-vuln="' . h($v['category']) . '" type="button" data-bs-toggle="collapse" data-bs-target="#v' . (int)$v['id'] . '">';
        echo (int)$v['id'] . '. ' . h($v['title']) . ' <span class="diff-badge ' . ($dmap[$diff] ?? '') . '">' . h($diff) . '</span></button></h2>';
        echo '<div id="v' . (int)$v['id'] . '" class="accordion-collapse collapse" data-bs-parent="#vulnAcc"><div class="accordion-body">';
        echo '<p><strong>목표:</strong> ' . h($v['goal']) . '</p>';
        echo '<p class="small text-secondary">OWASP: ' . h($v['owasp']) . ' · 경로: <code>' . h($v['route']) . '</code> · <a href="' . h($v['ref']) . '" target="_blank" rel="noopener">참고</a></p>';
        foreach ($v['steps'] as $i => $s) {
            echo '<div class="alert alert-dark small mb-2"><b>STEP ' . ($i + 1) . '.</b> ' . h($s['desc']);
            if (!empty($s['payload'])) echo '<code class="d-block mt-2 text-warning">' . h($s['payload']) . '</code>';
            echo '</div>';
        }
        echo '<p class="mb-0"><span class="badge bg-success-subtle text-success border border-success-subtle">대응책</span> ' . h($v['fix']) . '</p>';
        echo '</div></div></div>';
    }
    echo '</div>';
    echo '</div></div>';

    echo '<script>function filterVuln(btn){document.querySelectorAll(".vuln-tabs .btn-tab").forEach(b=>b.classList.remove("active"));btn.classList.add("active");var c=btn.dataset.cat;document.querySelectorAll(".vuln-row").forEach(function(r){r.style.display=(c==="all"||r.dataset.cat===c)?"":"none";});}</script>';
    layout_footer();
}
