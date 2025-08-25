<?php
// 使い方（例・main.phpの先頭）:
//   require_once __DIR__.'/../jwt/require_auth.php';
//   $auth = require_auth(); // 失敗時は 401 を返して exit
require_once __DIR__ . '/jwt_service.php';

function require_auth(): array {
    $cfg = require __DIR__ . '/jwt_config.php';
    $pdo = getDbConnection();

    // Authorization: Bearer <token> or Cookie
    $jwt = null;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\\s+(.*)$/i', $auth, $m)) {
        $jwt = trim($m[1]);
    } else {
        $cookieName = $cfg['cookie_name'];
        if (!empty($_COOKIE[$cookieName] ?? '')) $jwt = $_COOKIE[$cookieName];
    }
    if (!$jwt) unauthorized('Missing token');

    $payload = jwt_verify($jwt, $cfg);
    if (!$payload) unauthorized('Invalid token');

    // DBの手動失効・期限切れも確認（jwt_tokens要件）
    if (jwt_db_is_revoked_or_expired($pdo, $payload['jti'])) unauthorized('Revoked or expired');

    // 認証OK → 呼び出し側で使う情報を返却
    return ['account_id' => (int)$payload['sub'], 'jwt_id'=>$payload['jti'], 'exp'=>$payload['exp']];
}

function unauthorized(string $msg) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}
