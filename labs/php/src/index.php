<?php
// ============================================================
// BLACKOUT PHP 엔진 — 프런트 컨트롤러 (라우팅)
//   ⚠️ 의도적으로 취약한 학습용 코드. 로컬에서만 구동할 것.
// ============================================================
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/pages/board.php';
require_once __DIR__ . '/pages/auth.php';
require_once __DIR__ . '/pages/tools.php';
require_once __DIR__ . '/pages/admin.php';
require_once __DIR__ . '/pages/shop.php';
require_once __DIR__ . '/pages/practice.php';
require_once __DIR__ . '/pages/api.php';
require_once __DIR__ . '/pages/phpvuln.php';
require_once __DIR__ . '/pages/oauth.php';
require_once __DIR__ . '/pages/community.php';

db(); // 최초 요청 시 스키마/시드 보장

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($path === '') $path = '/';

// PHP 전용 취약점 API (제너릭 /api 디스패치보다 먼저)
if ($path === '/api/call') { page_dynamic_call(); exit; }

// /api/<action>
if (preg_match('#^/api/([a-z_]+)$#', $path, $m)) { api_dispatch($m[1]); exit; }

switch (true) {
    case preg_match('#^/oauth/(google|kakao|naver)/login$#', $path, $m) === 1: page_oauth_login($m[1]); break;
    case preg_match('#^/oauth/(google|kakao|naver)/callback$#', $path, $m) === 1: page_oauth_callback($m[1]); break;
    case $path === '/':                 page_home(); break;
    case $path === '/account':          page_account_overview(); break;
    case $path === '/login':            page_login(); break;
    case $path === '/register':         page_register(); break;
    case $path === '/logout':           page_logout(); break;
    case $path === '/find_id':          page_find_id(); break;
    case $path === '/find_pw':          page_find_pw(); break;
    case $path === '/reset_pw':         page_reset_pw(); break;
    case $path === '/change_pw':        page_change_pw(); break;
    case $path === '/profile':          page_profile(); break;
    case $path === '/profile/edit':     page_profile_edit(); break;

    case $path === '/post/write':       page_post_write(); break;
    case preg_match('#^/post/(\d+)/edit$#', $path, $m) === 1:    page_post_edit((int)$m[1]); break;
    case preg_match('#^/post/(\d+)/delete$#', $path, $m) === 1:  page_post_delete((int)$m[1]); break;
    case preg_match('#^/post/(\d+)/comment$#', $path, $m) === 1: page_comment((int)$m[1]); break;
    case preg_match('#^/post/(\d+)/like$#', $path, $m) === 1:    page_like((int)$m[1]); break;
    case preg_match('#^/post/(\d+)$#', $path, $m) === 1:         page_post_view((int)$m[1]); break;
    case preg_match('#^/notice/(\d+)$#', $path, $m) === 1:       page_notice_view((int)$m[1]); break;
    case preg_match('#^/file/(\d+)$#', $path, $m) === 1:         page_file((int)$m[1]); break;

    case $path === '/download':         page_download(); break;
    case $path === '/ping':             page_ping(); break;
    case $path === '/utils/url_preview':page_url_preview(); break;
    case $path === '/xss':              page_xss(); break;
    case $path === '/theme':            page_theme(); break;

    // PHP 전용 취약점
    case $path === '/verify_token':     page_verify_token(); break;
    case $path === '/settings':         page_settings_extract(); break;
    case $path === '/phpinfo':          page_phpinfo(); break;

    case $path === '/shop':             page_shop(); break;
    case $path === '/shop/buy':         page_shop_buy(); break;

    case $path === '/admin':            page_admin(); break;
    case $path === '/admin/export.csv': page_admin_export(); break;
    case preg_match('#^/admin/user/(\d+)/role$#', $path, $m) === 1:   page_admin_user_role((int)$m[1]); break;
    case preg_match('#^/admin/user/(\d+)/delete$#', $path, $m) === 1: page_admin_user_delete((int)$m[1]); break;
    case $path === '/admin/notice/create': page_admin_notice_create(); break;

    case $path === '/practice':         page_practice(); break;

    default:
        http_response_code(404);
        layout_header('404');
        echo '<div class="card"><div class="card-body p-5 text-center"><h3>404 · 페이지를 찾을 수 없습니다</h3><a href="/">홈으로</a></div></div>';
        layout_footer();
}
