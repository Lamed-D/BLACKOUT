<?php
// 상점: 가격/수량/합계를 클라이언트 입력으로 신뢰 → 비즈니스 로직 취약점(가격·수량 조작, 음수 결제)
require_once __DIR__ . '/../lib.php';

function page_shop(): void {
    $u = current_user(); if (!$u) redirect('/login');
    $products = db()->query("SELECT * FROM products ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    layout_header('상점');
    echo '<div class="card mb-3"><div class="card-body"><h4 class="fw-bold"><i class="bi bi-bag"></i> 포인트 상점</h4>';
    echo '<p class="text-secondary mb-0">보유 포인트: <b>' . (int)$u['points'] . 'P</b> · 실습: 구매 요청의 <code>unit_price</code>/<code>quantity</code> 를 조작해 보세요.</p></div></div>';
    echo '<div class="row g-3">';
    foreach ($products as $p) {
        echo '<div class="col-md-4"><div class="card h-100"><div class="card-body">';
        echo '<h5 class="fw-bold">' . h($p['name']) . '</h5><p class="text-secondary small">' . h($p['description']) . '</p>';
        echo '<p class="fw-bold">' . (int)$p['price'] . 'P <span class="text-secondary small">재고 ' . (int)$p['stock'] . '</span></p>';
        echo '<form method="post" action="/shop/buy">';
        echo '<input type="hidden" name="product_id" value="' . (int)$p['id'] . '">';
        echo '<input type="hidden" name="unit_price" value="' . (int)$p['price'] . '">';   // [VULN] 클라이언트가 가격 보유
        echo '<div class="input-group input-group-sm mb-2"><span class="input-group-text">수량</span><input class="form-control" type="number" name="quantity" value="1"></div>';
        echo '<button class="btn btn-success w-100">구매</button></form>';
        echo '</div></div></div>';
    }
    echo '</div>';
    layout_footer();
}

function page_shop_buy(): void {
    $u = current_user(); if (!$u) redirect('/login');
    $pid = (int)($_POST['product_id'] ?? 0);
    // [VULN] Insecure Design — 가격/수량/합계를 서버가 재계산하지 않고 입력을 신뢰
    $qty = (int)($_POST['quantity'] ?? 1);
    $unit = (int)($_POST['unit_price'] ?? 0);
    $total = isset($_POST['total']) ? (int)$_POST['total'] : $unit * $qty;

    $pdo = db();
    $pdo->prepare("INSERT INTO orders (user_id,product_id,quantity,unit_price,total) VALUES (?,?,?,?,?)")
        ->execute([$u['id'], $pid, $qty, $unit, $total]);
    // 음수 total 이면 포인트가 오히려 증가 (취약)
    $pdo->prepare("UPDATE users SET points = points - ? WHERE id=?")->execute([$total, $u['id']]);

    $new = $pdo->prepare("SELECT points FROM users WHERE id=?"); $new->execute([$u['id']]);
    $pts = (int)$new->fetch()['points'];
    layout_header('구매 완료');
    echo '<div class="card"><div class="card-body p-4"><h4 class="fw-bold">구매 완료</h4>';
    echo '<p>결제 금액: <b>' . $total . 'P</b> (단가 ' . $unit . ' × 수량 ' . $qty . ')</p>';
    echo '<p>남은 포인트: <b>' . $pts . 'P</b></p>';
    if ($pts >= 1000000) echo '<div class="alert alert-success">' . h(flag('shop_logic_bypass')) . '</div>';
    echo '<a class="btn btn-outline-primary" href="/shop">상점으로</a></div></div>';
    layout_footer();
}
