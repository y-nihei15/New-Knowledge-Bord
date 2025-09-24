<?php
// 使い方（例・logout.php や タイムアウト検知時）:
//   require_once __DIR__.'/../jwt/token_reset.php';
//   reset_token_from_header(); // または reset_token($jwt)

require_once __DIR__ . '/jwt_service.php';

function reset_token(string $jwt): bool {
    $cfg = require __DIR__ . '/jwt_config.php';
    $pdo = getDbConnection();

    $payload = jwt_verify($jwt, $cfg);
    if (!$payload) return false;

    // DBレコードを手動失効に
    $ok = jwt_db_revoke($pdo, $payload['jti']);

    return $ok;
}

function reset_token_from_header(): bool {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\\s+(.*)$/i', $auth, $m)) {
        return reset_token(trim($m[1]));
    }
    
    return false;
}
