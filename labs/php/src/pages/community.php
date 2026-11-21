<?php
require_once __DIR__ . '/../lib.php';

function page_account_overview(): void {
    $user = current_user();
    if (!$user) redirect('/login');
    $pdo = db();
    $posts = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE author_id=?');
    $posts->execute([$user['id']]);
    $comments = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE author_id=?');
    $comments->execute([$user['id']]);
    $likes = $pdo->prepare('SELECT COUNT(*) FROM post_likes WHERE user_id=?');
    $likes->execute([$user['id']]);
    $recent = $pdo->prepare('SELECT id,title,created_at FROM posts WHERE author_id=? ORDER BY id DESC LIMIT 5');
    $recent->execute([$user['id']]);
    $replies = $pdo->prepare('SELECT c.id,c.post_id,c.author,c.created_at,p.title FROM comments c JOIN posts p ON p.id=c.post_id WHERE p.author_id=? AND c.author_id<>? ORDER BY c.id DESC LIMIT 8');
    $replies->execute([$user['id'], $user['id']]);
    $items = $replies->fetchAll(PDO::FETCH_ASSOC);
    layout_header('마이페이지');
    echo '<div class="card mb-4"><div class="card-body p-4 d-flex align-items-center gap-3">';
    if (!empty($user['avatar']) && filter_var($user['avatar'], FILTER_VALIDATE_URL) &&
        in_array(parse_url($user['avatar'], PHP_URL_SCHEME), ['http', 'https'], true)) {
        echo '<img class="account-avatar" src="' . h($user['avatar']) . '" alt="">';
    } else {
        echo '<span class="account-avatar avatar-initial" aria-hidden="true">' . h(mb_first_letter($user['name'] ?: $user['username'])) . '</span>';
    }
    echo '<div><h2 class="h4 fw-bold mb-1">' . h($user['name'] ?: $user['username']) . '</h2>';
    echo '<p class="text-secondary mb-0">@' . h($user['username']) . ' · 가입일 ' . h(substr($user['created_at'], 0, 10)) . '</p></div>';
    echo '<a class="btn btn-outline-primary ms-auto" href="/profile?user_id=' . (int)$user['id'] . '">프로필 보기</a></div></div>';
    echo '<div class="row g-3 mb-4">';
    foreach ([['내 글', $posts->fetchColumn()], ['내 댓글', $comments->fetchColumn()], ['누른 좋아요', $likes->fetchColumn()], ['포인트', $user['points']]] as [$label, $count]) {
        echo '<div class="col-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary small">' . h($label) . '</div><div class="fs-3 fw-bold">' . (int)$count . '</div></div></div></div>';
    }
    echo '</div><div class="row g-4"><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h3 class="h5 fw-bold">최근 작성한 글</h3>';
    $own = $recent->fetchAll(PDO::FETCH_ASSOC);
    if (!$own) echo '<p class="text-secondary">아직 작성한 글이 없습니다.</p>';
    foreach ($own as $post) echo '<a class="d-block border-bottom py-2 text-decoration-none" href="/post/' . (int)$post['id'] . '">' . h($post['title']) . ' <small class="text-secondary">' . h(substr($post['created_at'], 0, 10)) . '</small></a>';
    echo '</div></div></div><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h3 class="h5 fw-bold">내 글의 새 댓글</h3>';
    if (!$items) echo '<p class="text-secondary">새 댓글이 없습니다.</p>';
    foreach ($items as $item) echo '<a class="d-block border-bottom py-2 text-decoration-none" href="/post/' . (int)$item['post_id'] . '">' . h($item['author']) . '님이 ' . h($item['title']) . '에 댓글을 남겼습니다. <small class="text-secondary">' . h(substr($item['created_at'], 0, 16)) . '</small></a>';
    echo '</div></div></div></div>';
    layout_footer();
}

function mb_first_letter(string $value): string {
    return preg_match('/^./us', $value, $match) ? $match[0] : '?';
}
