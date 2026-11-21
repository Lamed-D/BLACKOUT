<?php
// 독립 SSO 경로. 기존 로컬 로그인 흐름은 변경하지 않는다.
require_once __DIR__ . '/../lib.php';

function oauth_config(string $provider): ?array {
    $providers = [
        'google' => ['GOOGLE', 'https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://www.googleapis.com/oauth2/v2/userinfo', 'openid email profile'],
        'kakao' => ['KAKAO', 'https://kauth.kakao.com/oauth/authorize', 'https://kauth.kakao.com/oauth/token', 'https://kapi.kakao.com/v2/user/me', 'profile_nickname account_email'],
        'naver' => ['NAVER', 'https://nid.naver.com/oauth2.0/authorize', 'https://nid.naver.com/oauth2.0/token', 'https://openapi.naver.com/v1/nid/me', ''],
    ];
    if (!isset($providers[$provider])) return null;
    [$prefix, $authorize, $token, $profile, $scope] = $providers[$provider];
    return ['id' => getenv($prefix . '_CLIENT_ID') ?: '', 'secret' => getenv($prefix . '_CLIENT_SECRET') ?: '',
        'authorize' => $authorize, 'token' => $token, 'profile' => $profile, 'scope' => $scope];
}

function oauth_callback_url(string $provider): string {
    return rtrim(getenv('OAUTH_REDIRECT_BASE') ?: 'http://127.0.0.1:8101', '/') . '/oauth/' . $provider . '/callback';
}

function oauth_request(string $url, ?array $form = null, ?string $bearer = null): array {
    $headers = ['Accept: application/json'];
    if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($bearer !== null) $headers[] = 'Authorization: Bearer ' . $bearer;
    $context = stream_context_create(['http' => [
        'method' => $form === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $form === null ? '' : http_build_query($form),
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || strlen($body) > 1048576) throw new RuntimeException('공급자 응답을 읽을 수 없습니다.');
    $status = $http_response_header[0] ?? '';
    if (!preg_match('/^HTTP\/\S+ 2\d\d\b/', $status)) throw new RuntimeException('공급자 요청이 실패했습니다.');
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('공급자 응답 형식이 올바르지 않습니다.');
    return $data;
}

function oauth_profile(string $provider, array $data): array {
    if ($provider === 'google') {
        return [(string)($data['id'] ?? ''), (string)($data['name'] ?? ''), (string)($data['picture'] ?? ''),
            !empty($data['verified_email']) ? (string)($data['email'] ?? '') : ''];
    }
    if ($provider === 'kakao') {
        $account = $data['kakao_account'] ?? [];
        return [(string)($data['id'] ?? ''), (string)($account['profile']['nickname'] ?? ''), (string)($account['profile']['profile_image_url'] ?? ''),
            !empty($account['is_email_verified']) && !empty($account['is_email_valid']) ? (string)($account['email'] ?? '') : ''];
    }
    $response = $data['response'] ?? [];
    // Naver 응답에는 검증된 이메일 표시가 없으므로 기존 계정 이메일 매칭에 사용하지 않는다.
    return [(string)($response['id'] ?? ''), (string)($response['name'] ?? $response['nickname'] ?? ''), (string)($response['profile_image'] ?? ''), ''];
}

function page_oauth_login(string $provider): void {
    $config = oauth_config($provider);
    if ($config === null) { http_response_code(404); return; }
    if ($config['id'] === '' || $config['secret'] === '') {
        $_SESSION['oauth_message'] = '키가 설정되지 않았습니다. 관리자에게 문의하세요.';
        redirect('/login');
    }
    $state = bin2hex(random_bytes(32));
    $_SESSION['oauth_state_' . $provider] = $state;
    $params = ['client_id' => $config['id'], 'redirect_uri' => oauth_callback_url($provider),
        'response_type' => 'code', 'state' => $state];
    if ($config['scope'] !== '') $params['scope'] = $config['scope'];
    redirect($config['authorize'] . '?' . http_build_query($params));
}

function page_oauth_callback(string $provider): void {
    $config = oauth_config($provider);
    if ($config === null) { http_response_code(404); return; }
    $expected = $_SESSION['oauth_state_' . $provider] ?? '';
    unset($_SESSION['oauth_state_' . $provider]);
    if ($expected === '' || !is_string($_GET['state'] ?? null) ||
        !hash_equals($expected, $_GET['state']) || !is_string($_GET['code'] ?? null) || $_GET['code'] === '') {
        http_response_code(400);
        layout_header('SSO 오류'); echo '<div class="alert alert-danger">로그인 요청을 확인할 수 없습니다.</div>'; layout_footer();
        return;
    }
    try {
        $form = ['grant_type' => 'authorization_code', 'code' => $_GET['code'],
            'redirect_uri' => oauth_callback_url($provider), 'client_id' => $config['id'], 'client_secret' => $config['secret']];
        if ($provider === 'naver') $form['state'] = $_GET['state'];
        $token = oauth_request($config['token'], $form);
        if (empty($token['access_token']) || !is_string($token['access_token'])) throw new RuntimeException('토큰이 없습니다.');
        $profile = oauth_request($config['profile'], null, $token['access_token']);
        [$remoteId, $name, $avatar, $verifiedEmail] = oauth_profile($provider, $profile);
        if ($remoteId === '' || strlen($remoteId) > 255) throw new RuntimeException('계정 ID가 없습니다.');
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM users WHERE oauth_provider=? AND oauth_id=? LIMIT 1');
        $st->execute([$provider, $remoteId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user && $verifiedEmail !== '') {
            $st = $pdo->prepare('SELECT * FROM users WHERE email=? AND (oauth_provider IS NULL OR oauth_provider=?) LIMIT 1');
            $st->execute([$verifiedEmail, $provider]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($user) {
            $pdo->prepare('UPDATE users SET oauth_provider=?,oauth_id=?,avatar=? WHERE id=?')
                ->execute([$provider, $remoteId, $avatar, $user['id']]);
        } else {
            $username = $provider . '_' . bin2hex(random_bytes(8));
            $safeName = strlen($name) <= 80 ? $name : $username;
            $pdo->prepare('INSERT INTO users (username,password,name,email,role,avatar,oauth_provider,oauth_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$username, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    $safeName ?: $username, $verifiedEmail ?: null, 'user', $avatar, $provider, $remoteId]);
            $st = $pdo->prepare('SELECT * FROM users WHERE id=?');
            $st->execute([(int)$pdo->lastInsertId()]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        setcookie('role', $user['role'], 0, '/');
        redirect('/');
    } catch (Throwable $error) {
        $_SESSION['oauth_message'] = 'SSO 로그인에 실패했습니다. 다시 시도해 주세요.';
        redirect('/login');
    }
}
