<?php

declare(strict_types=1);

require_once __DIR__ . '/jwt_service.php';
require_once __DIR__ . '/jwt_service.php';
require_once __DIR__ . '/../config/db.php';

function require_auth(): array {
    $cfg   = require __DIR__ . '/jwt_config.php';
    $realm = $cfg['realm'] ?? 'attendance-api';

    // --- Bearer 取得（ヘッダ優先、なければ Cookie）
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? null;
    if ($hdr === null && function_exists('apache_request_headers')) {
        $req = apache_request_headers();
        foreach ($req as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    $jwt = null;
    if (is_string($hdr) && stripos($hdr, 'Bearer ') === 0) {
        $jwt = trim(substr($hdr, 7));
    } elseif (!empty($cfg['cookie_name']) && !empty($_COOKIE[$cfg['cookie_name']])) {
        $jwt = (string)$_COOKIE[$cfg['cookie_name']];
    }
    if (!$jwt) _auth_fail('invalid_request', 'Missing bearer token', 401, $realm);

    // --- 署名/iss/aud/時刻検証
    try {
        $payload = jwt_verify($jwt, $cfg); // ★ 第2引数 cfg が必須
    } catch (Throwable $e) {
        _auth_fail('invalid_token', 'Invalid token', 401, $realm);
    }
    if (!is_array($payload)) _auth_fail('invalid_token', 'Invalid token payload', 401, $realm);

    // --- JTI 必須
    $jti = $payload['jti'] ?? '';
    if ($jti === '') _auth_fail('invalid_token', 'Missing jti in token', 401, $realm);

    // --- DB: 失効/期限切れ/未登録チェック
    try { $pdo = getDbConnection(); } catch (Throwable $e) {
        _auth_fail('temporarily_unavailable', 'Database connection error', 503, $realm);
    }
    try {
        if (jwt_db_is_revoked_or_expired($pdo, $jti)) {
            _auth_fail('invalid_token', 'Token is revoked or expired', 401, $realm);
        }
    } catch (Throwable $e) {
        _auth_fail('invalid_token', 'Token validation failed', 401, $realm);
    }

    return [
        'ok'         => true,
        'account_id' => isset($payload['account_id']) ? (int)$payload['account_id'] : null,
        'jwt_id'     => $jti,
        'exp'        => isset($payload['exp']) ? (int)$payload['exp'] : null,
        'sub'        => $payload['sub'] ?? null,
        'token'      => $jwt,
    ];
}

function _auth_fail(string $error, string $description, int $statusCode, string $realm): void {
    header(
        'WWW-Authenticate: ' .
        sprintf('Bearer realm="%s", error="%s", error_description="%s"', $realm, $error, $description),
        true,
        $statusCode
    );
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>$error,'message'=>$description], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

