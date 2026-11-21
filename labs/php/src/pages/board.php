<?php
// 게시판: 홈/검색(SQLi·XSS)/글 CRUD(저장형 XSS·업로드)/댓글/좋아요/공지
require_once __DIR__ . '/../lib.php';

function upload_dir(): string { $d = '/var/www/html/uploads'; @mkdir($d, 0777, true); return $d; }

function page_home(): void {
    $pdo = db();
    $notices = $pdo->query("SELECT * FROM notices ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $q = $_GET['q'] ?? '';
    $search_results = null; $err = null;
    if ($q !== '') {
        // [VULN] SQL Injection (문자열 연결) + 결과 반사형/저장형 XSS
        $sql = "SELECT id, title, author FROM posts WHERE title LIKE '%$q%' OR content LIKE '%$q%' ORDER BY id DESC";
        try { $search_results = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { $err = $e->getMessage() . "\n쿼리: " . $sql; } // [VULN] verbose 오류
    }
    // 페이지네이션 (최신글 우선)
    $per = 6;
    $total = (int)$pdo->query("SELECT COUNT(*) c FROM posts")->fetch()['c'];
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $per;
    $posts = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id) AS ccount
                          FROM posts p ORDER BY p.id DESC LIMIT $per OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

    layout_header('자유 게시판');
    echo '<div class="card mb-4"><div class="card-body p-5">';
    echo '<h2 class="fw-bold mb-2"><i class="bi bi-chat-square-text text-info"></i> 자유 게시판</h2>';
    echo '<p class="text-secondary mb-3">자유롭게 글을 남겨주세요. 제목/본문/댓글/검색이 다양한 공격(XSS·SQLi)에 취약합니다.</p>';
    echo '<form class="d-flex gap-2" method="get" action="/"><input class="form-control" name="q" placeholder="게시글 검색 (SQLi 실습)" value="' . h($q) . '"><button class="btn btn-primary">검색</button></form>';
    echo '</div></div>';

    if ($q !== '') {
        echo '<div class="card mb-4"><div class="card-body">';
        echo '<h5 class="fw-bold">검색 결과: <span class="text-info">' . $q . '</span></h5>'; // [VULN] Reflected XSS (이스케이프 안 함)
        if ($err) {
            echo '<pre class="text-danger">' . h($err) . '</pre>';
        } elseif ($search_results) {
            echo '<ul class="list-group">';
            foreach ($search_results as $r) {
                // [VULN] 결과(제목 컬럼)를 이스케이프 없이 출력 → UNION 데이터/XSS 노출
                echo '<li class="list-group-item"><b>#' . h($r['id']) . '</b> ' . $r['title'] . ' <span class="text-secondary small">(' . h($r['author']) . ')</span></li>';
            }
            echo '</ul>';
        } else { echo '<p class="text-secondary">결과가 없습니다.</p>'; }
        echo '</div></div>';
    }

    echo '<div class="card"><div class="card-body p-0"><table class="table table-hover text-center align-middle mb-0">';
    echo '<thead class="table-dark"><tr><th>번호</th><th>제목</th><th>작성자</th><th>조회</th><th>작성일</th></tr></thead><tbody>';
    foreach ($notices as $n) {
        echo '<tr class="table-warning"><td class="text-danger fw-bold"><i class="bi bi-megaphone-fill"></i> 공지</td>';
        echo '<td class="text-start ps-4"><a class="fw-bold text-dark text-decoration-none" href="/notice/' . (int)$n['id'] . '">' . $n['title'] . '</a></td>';
        echo '<td><span class="badge bg-danger">관리자</span></td><td class="small text-secondary">' . (int)$n['views'] . '</td><td class="small text-secondary">' . h($n['created_at']) . '</td></tr>';
    }
    foreach ($posts as $p) {
        // [VULN] Stored XSS — 제목을 이스케이프 없이 출력
        $cc = (int)($p['ccount'] ?? 0);
        echo '<tr><td class="text-secondary fw-bold">' . (int)$p['id'] . '</td>';
        echo '<td class="text-start ps-4"><a class="fw-bold text-info text-decoration-none" href="/post/' . (int)$p['id'] . '">' . $p['title'] . '</a>';
        if ($cc > 0) echo ' <span class="badge bg-info-subtle text-info">' . $cc . '</span>';
        echo '</td><td><span class="badge bg-secondary">' . h($p['author']) . '</span></td><td class="small text-secondary">' . (int)$p['views'] . '</td><td class="small text-secondary">' . h($p['created_at']) . '</td></tr>';
    }
    if (!$posts && !$notices) echo '<tr><td colspan="5" class="py-5 text-muted">등록된 게시글이 없습니다.</td></tr>';
    echo '</tbody></table></div>';
    // 번호형 페이지네이션 (1 2 3 … N, 현재 주변 윈도우 + 양끝 + 말줄임)
    if ($pages > 1) {
        $win = [];
        for ($i = 1; $i <= $pages; $i++)
            if ($i === 1 || $i === $pages || abs($i - $page) <= 2) $win[] = $i;
        echo '<div class="card-footer d-flex justify-content-center"><nav aria-label="게시판 페이지"><ul class="pagination flex-wrap justify-content-center gap-1 mb-0">';
        echo '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="/?page=' . max(1, $page - 1) . '">&laquo;</a></li>';
        $last = 0;
        foreach ($win as $i) {
            if ($i - $last > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="/?page=' . $i . '">' . $i . '</a></li>';
            $last = $i;
        }
        echo '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="/?page=' . min($pages, $page + 1) . '">&raquo;</a></li>';
        echo '</ul></nav></div>';
    }
    echo '<div class="card-footer text-end"><a class="btn btn-warning fw-bold text-dark" href="/post/write"><i class="bi bi-pencil-fill"></i> 글쓰기</a></div></div>';
    layout_footer();
}

function render_post(array $post, array $files, array $comments, int $likes, bool $is_notice): void {
    layout_header($post['title']);
    echo '<div class="card mb-3"><div class="card-body p-4">';
    echo '<h3 class="fw-bold">' . $post['title'] . '</h3>'; // [VULN] Stored XSS
    echo '<p class="text-secondary small">작성자 ' . h($post['author']) . ' · 조회 ' . (int)$post['views'] . ' · ' . h($post['created_at']) . '</p><hr>';
    echo '<div style="min-height:80px;">' . nl2br($post['content']) . '</div>'; // [VULN] Stored XSS
    if ($files) {
        echo '<hr><h6>첨부파일</h6><ul>';
        foreach ($files as $f) echo '<li><a href="/file/' . (int)$f['id'] . '">' . h($f['orig_name']) . '</a></li>';
        echo '</ul>';
    }
    if (!$is_notice) {
        echo '<div class="mt-3 d-flex gap-2">';
        echo '<form method="post" action="/post/' . (int)$post['id'] . '/like"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-heart"></i> 좋아요 ' . $likes . '</button></form>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="/post/' . (int)$post['id'] . '/edit">수정</a>';
        echo '<form method="post" action="/post/' . (int)$post['id'] . '/delete" onsubmit="return confirm(\'삭제?\')"><button class="btn btn-outline-danger btn-sm">삭제</button></form>';
        echo '</div>';
    }
    echo '</div></div>';

    if (!$is_notice) {
        echo '<div class="card"><div class="card-body"><h5 class="fw-bold">댓글</h5>';
        foreach ($comments as $c) {
            echo '<div class="border-bottom py-2"><b>' . h($c['author']) . '</b> <span class="text-secondary small">' . h($c['created_at']) . '</span><br>';
            echo $c['content'] . '</div>'; // [VULN] Stored XSS (댓글)
        }
        if (current_user()) {
            echo '<form method="post" action="/post/' . (int)$post['id'] . '/comment" class="mt-3 d-flex gap-2">';
            echo '<input class="form-control" name="content" placeholder="댓글 입력"><button class="btn btn-primary">등록</button></form>';
        } else { echo '<p class="text-secondary mt-2">로그인 후 댓글을 쓸 수 있습니다.</p>'; }
        echo '</div></div>';
    }
    layout_footer();
}

function page_post_view(int $id): void {
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM posts WHERE id=?"); $st->execute([$id]);
    $post = $st->fetch(PDO::FETCH_ASSOC);
    if (!$post) { http_response_code(404); echo '게시글을 찾을 수 없습니다.'; return; }
    $pdo->prepare("UPDATE posts SET views=views+1 WHERE id=?")->execute([$id]);
    $fs = $pdo->prepare("SELECT * FROM files WHERE post_id=?"); $fs->execute([$id]);
    $cs = $pdo->prepare("SELECT * FROM comments WHERE post_id=? ORDER BY id"); $cs->execute([$id]);
    $lk = $pdo->prepare("SELECT COUNT(*) c FROM post_likes WHERE post_id=?"); $lk->execute([$id]);
    render_post($post, $fs->fetchAll(PDO::FETCH_ASSOC), $cs->fetchAll(PDO::FETCH_ASSOC), (int)$lk->fetch()['c'], false);
}

function page_notice_view(int $id): void {
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM notices WHERE id=?"); $st->execute([$id]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) { http_response_code(404); echo '공지를 찾을 수 없습니다.'; return; }
    $pdo->prepare("UPDATE notices SET views=views+1 WHERE id=?")->execute([$id]);
    render_post($n, [], [], 0, true);
}

function page_post_write(): void {
    $u = current_user(); if (!$u) redirect('/login');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = $_POST['title'] ?? ''; $content = $_POST['content'] ?? '';
        $pdo = db();
        $pdo->prepare("INSERT INTO posts (title,content,author_id,author) VALUES (?,?,?,?)")
            ->execute([$title, $content, $u['id'], $u['username']]);
        $pid = (int)$pdo->lastInsertId();
        // [VULN] 무제한 파일 업로드 — 확장자/타입 검증 없음
        if (!empty($_FILES['file']['name'])) {
            $name = basename($_FILES['file']['name']);
            move_uploaded_file($_FILES['file']['tmp_name'], upload_dir() . '/' . $name);
            $pdo->prepare("INSERT INTO files (post_id,filename,orig_name,size) VALUES (?,?,?,?)")
                ->execute([$pid, $name, $name, (int)($_FILES['file']['size'] ?? 0)]);
        }
        redirect('/post/' . $pid);
    }
    layout_header('새 글 쓰기');
    echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3">새 글 쓰기</h4>';
    echo '<form method="post" enctype="multipart/form-data" action="/post/write">';
    echo '<input class="form-control mb-2" name="title" placeholder="제목" required>';
    echo '<textarea class="form-control mb-2" name="content" rows="8" placeholder="내용 (HTML 허용 — 저장형 XSS 실습)"></textarea>';
    echo '<input class="form-control mb-3" type="file" name="file">';
    echo '<button class="btn btn-primary">등록</button></form></div></div>';
    layout_footer();
}

function page_post_edit(int $id): void {
    $u = current_user(); if (!$u) redirect('/login');
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM posts WHERE id=?"); $st->execute([$id]);
    $post = $st->fetch(PDO::FETCH_ASSOC);
    if (!$post) { http_response_code(404); echo '없음'; return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->prepare("UPDATE posts SET title=?,content=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$_POST['title'] ?? '', $_POST['content'] ?? '', $id]);
        redirect('/post/' . $id);
    }
    layout_header('글 수정');
    echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3">글 수정</h4>';
    echo '<form method="post" action="/post/' . $id . '/edit">';
    echo '<input class="form-control mb-2" name="title" value="' . h($post['title']) . '">';
    echo '<textarea class="form-control mb-3" name="content" rows="8">' . h($post['content']) . '</textarea>';
    echo '<button class="btn btn-primary">저장</button></form></div></div>';
    layout_footer();
}

function page_post_delete(int $id): void {
    if (!current_user()) redirect('/login');
    $pdo = db();
    $pdo->prepare("DELETE FROM comments WHERE post_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM post_likes WHERE post_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([$id]);
    redirect('/');
}

function page_comment(int $id): void {
    $u = current_user(); if (!$u) redirect('/login');
    $content = $_POST['content'] ?? '';
    if ($content !== '') {
        db()->prepare("INSERT INTO comments (post_id,author_id,author,content) VALUES (?,?,?,?)")
            ->execute([$id, $u['id'], $u['username'], $content]);
    }
    redirect('/post/' . $id);
}

function page_like(int $id): void {
    $u = current_user(); if (!$u) { http_response_code(401); echo 'login required'; return; }
    $pdo = db();
    $st = $pdo->prepare("SELECT 1 FROM post_likes WHERE post_id=? AND user_id=?"); $st->execute([$id, $u['id']]);
    if ($st->fetch()) $pdo->prepare("DELETE FROM post_likes WHERE post_id=? AND user_id=?")->execute([$id, $u['id']]);
    else $pdo->prepare("INSERT INTO post_likes (post_id,user_id) VALUES (?,?)")->execute([$id, $u['id']]);
    redirect('/post/' . $id);
}
