<?php
// 실습실: vulns.json 을 읽어 카테고리 탭 + 아코디언 가이드로 렌더 (데이터 주도)
require_once __DIR__ . '/../lib.php';

function page_practice(): void {
    $data = json_decode(@file_get_contents(__DIR__ . '/../vulns.json'), true);
    layout_header('모의해킹 실습실');
    echo '<style>.accordion-body code{white-space:pre-wrap;word-break:break-word}.accordion-body{font-size:.95rem}.accordion-button:not(.collapsed){background:#eff6ff}</style>';
    if (!$data) { echo '<p>vulns.json 을 불러오지 못했습니다.</p>'; layout_footer(); return; }

    $ENGINE = 'php';
    $cats = $data['categories'];
    $exfiles = json_decode(@file_get_contents(__DIR__ . '/../exploits.json'), true)['files'] ?? [];
    $engCfg = (json_decode(@file_get_contents(__DIR__ . '/../applicability.json'), true)['engines'][$ENGINE]) ?? ['default' => 'works'];
    $statusOf = function ($v) use ($ENGINE, $engCfg) {
        if (($v['engine'] ?? 'all') !== 'all') return ['works', ''];
        $ov = $engCfg['overrides'][(string)$v['id']] ?? null;
        if ($ov) return [$ov['status'], $ov['reason'] ?? ''];
        $st = $engCfg['default'] ?? 'works';
        return [$st, $st === 'na' ? ($engCfg['defaultReason'] ?? '') : ''];
    };
    $SB = ['works' => ['success', '✓ 동작'], 'partial' => ['warning', '△ 부분 동작'], 'na' => ['secondary', '✕ 미동작(안전)']];
    // 엔진 필터: 공통(all/미지정) + 이 엔진 전용만 표시
    $vulns = array_values(array_filter($data['vulns'], function ($v) use ($ENGINE) {
        $e = $v['engine'] ?? 'all';
        return $e === 'all' || $e === $ENGINE;
    }));
    echo '<div class="card section-sqli mb-4"><div class="card-header"><h3 class="fw-bold mb-0" style="color:var(--c-blue)"><i class="bi bi-shield-shaded"></i> ' . h($data['meta']['title']) . '</h3>';
    echo '<small class="text-muted">' . h($data['meta']['note']) . '</small></div>';
    echo '<div class="card-body p-4">';

    echo '<div class="d-flex vuln-tabs flex-wrap mb-4">';
    foreach ($cats as $i => $c)
        echo '<button class="btn-tab' . ($c['key'] === 'all' ? ' active' : '') . '" data-cat="' . h($c['key']) . '" onclick="filterVuln(this)">' . h($c['label']) . '</button>';
    echo '</div>';
    echo '<p class="mb-2 text-secondary">총 <strong>' . count($vulns) . '종</strong> 취약점. 각 항목에 이 엔진 동작 여부를 <b>배지</b>로 표시합니다(✓ 동작 / △ 부분 / ✕ 미동작). 공격 접근 과정은 <a href="/thoughts">사고들</a>에서.</p>';
    echo '<div class="mb-3"><button id="worksOnly" class="btn btn-outline-success btn-sm" onclick="toggleWorks(this)">✓ 이 엔진에서 동작하는 것만</button> <span class="small text-secondary ms-1">끄면 미동작(안전)·부분 항목도 설명과 함께 보입니다.</span></div>';

    // 예제 공격 파일 (다운로드/소스)
    echo '<div class="mb-4"><h6 class="fw-bold"><i class="bi bi-file-earmark-code"></i> 예제 공격 파일</h6><div class="row g-2">';
    foreach ($exfiles as $f) {
        echo '<div class="col-md-6"><div class="border rounded p-2 d-flex justify-content-between align-items-center">';
        echo '<div><code>' . h($f['name']) . '</code><span class="small text-secondary d-block">' . h($f['desc']) . '</span></div>';
        echo '<div class="text-nowrap ms-2"><a class="btn btn-sm btn-outline-primary" href="/exploit?name=' . urlencode($f['name']) . '">다운</a> ';
        echo '<a class="btn btn-sm btn-outline-secondary" href="/exploit?name=' . urlencode($f['name']) . '&raw=1" target="_blank">소스</a></div></div></div>';
    }
    echo '</div></div>';

    echo '<div class="accordion" id="vulnAcc">';
    foreach ($vulns as $v) {
        $diff = $v['difficulty'];
        $dmap = ['easy' => 'diff-easy', 'medium' => 'diff-medium', 'hard' => 'diff-hard', 'expert' => 'diff-expert'];
        [$st, $reason] = $statusOf($v);
        $sb = $SB[$st] ?? $SB['works'];
        echo '<div class="accordion-item vuln-row" data-cat="' . h($v['category']) . '" data-status="' . h($st) . '">';
        echo '<h2 class="accordion-header"><button class="accordion-button" data-vuln="' . h($v['category']) . '" type="button" data-bs-toggle="collapse" data-bs-target="#v' . (int)$v['id'] . '">';
        echo (int)$v['id'] . '. ' . h($v['title']) . ' <span class="diff-badge ' . ($dmap[$diff] ?? '') . '">' . h($diff) . '</span>';
        echo ' <span class="badge text-bg-' . $sb[0] . ' ms-1">' . $sb[1] . '</span>';
        $eng = $v['engine'] ?? 'all';
        if ($eng !== 'all') echo ' <span class="engine-badge engine-' . h($eng) . '">' . strtoupper(h($eng)) . ' 전용</span>';
        echo '</button></h2>';
        echo '<div id="v' . (int)$v['id'] . '" class="accordion-collapse collapse show"><div class="accordion-body">';
        if ($st !== 'works' && $reason !== '') echo '<div class="alert alert-' . ($st === 'na' ? 'secondary' : 'warning') . '"><b>' . ($st === 'na' ? '⛔ 이 엔진에서 동작하지 않습니다' : '△ 이 엔진에서는 부분만 동작합니다') . ':</b> ' . h($reason) . '</div>';
        echo '<p><strong>목표:</strong> ' . h($v['goal']) . '</p>';
        echo '<p class="small text-secondary">OWASP: ' . h($v['owasp']) . ' · 경로: <code>' . h($v['route']) . '</code> · <a href="' . h($v['ref']) . '" target="_blank" rel="noopener">참고</a></p>';
        if (!empty($v['detail'])) echo '<p>' . nl2br(h($v['detail'])) . '</p>';
        if (!empty($v['diagram'])) echo '<pre class="d-block" style="color:#cbd5e1">' . h($v['diagram']) . '</pre>';
        foreach ($v['steps'] as $i => $s) {
            echo '<div class="alert alert-dark small mb-2"><b>STEP ' . ($i + 1) . '.</b> ' . h($s['desc']);
            if (!empty($s['payload'])) echo '<div class="d-flex align-items-start gap-2 mt-2"><code class="flex-grow-1 payload">' . h($s['payload']) . '</code><button type="button" class="btn btn-sm btn-outline-secondary copy-btn">복사</button></div>';
            echo '</div>';
        }
        $vid = (int)$v['id'];
        $related = array_filter($exfiles, function ($f) use ($vid) { return in_array($vid, $f['items'] ?? []); });
        if ($related) {
            echo '<div class="mb-2"><b>예제 파일:</b> ';
            foreach ($related as $f) {
                echo '<a class="badge text-bg-primary text-decoration-none" href="/exploit?name=' . urlencode($f['name']) . '"><i class="bi bi-download"></i> ' . h($f['name']) . '</a> ';
                echo '<a class="badge text-bg-secondary text-decoration-none" href="/exploit?name=' . urlencode($f['name']) . '&raw=1" target="_blank">소스</a> ';
            }
            echo '</div>';
        }
        echo '<p class="mb-0"><span class="badge bg-success-subtle text-success border border-success-subtle">대응책</span> ' . h($v['fix']) . '</p>';
        echo '</div></div></div>';
    }
    echo '</div>';
    echo '</div></div>';

    echo '<script>var curCat="all",worksOnly=false;'
        . 'function applyFilters(){document.querySelectorAll(".vuln-row").forEach(function(r){var catOk=(curCat==="all"||r.dataset.cat===curCat);var stOk=(!worksOnly||r.dataset.status==="works");r.style.display=(catOk&&stOk)?"":"none";});}'
        . 'function filterVuln(btn){document.querySelectorAll(".vuln-tabs .btn-tab").forEach(b=>b.classList.remove("active"));btn.classList.add("active");curCat=btn.dataset.cat;applyFilters();}'
        . 'function toggleWorks(btn){worksOnly=!worksOnly;btn.classList.toggle("btn-success",worksOnly);btn.classList.toggle("btn-outline-success",!worksOnly);applyFilters();}'
        . 'document.querySelectorAll(".copy-btn").forEach(function(b){b.addEventListener("click",function(){var t=b.previousElementSibling.textContent;if(navigator.clipboard)navigator.clipboard.writeText(t);b.textContent="복사됨";setTimeout(function(){b.textContent="복사";},1200);});});</script>';
    layout_footer();
}
