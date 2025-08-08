<?php
// JWTライブラリの手動読み込み（composerなし）
require_once __DIR__ . '/../libs/php-jwt/JWT.php';
require_once __DIR__ . '/../libs/php-jwt/Key.php';
require_once __DIR__ . '/../libs/php-jwt/ExpiredException.php';
require_once __DIR__ . '/../libs/php-jwt/BeforeValidException.php';
require_once __DIR__ . '/../libs/php-jwt/SignatureInvalidException.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authenticate() {
    $headers = apache_request_headers();

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "認証に失敗しました"]);
        exit;
    }

    if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "認証に失敗しました"]);
        exit;
    }

    $token = $matches[1];
    $secret = 'your_jwt_secret';

    try {
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        return $decoded; // user_idなどが含まれる想定
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "無効なトークンです"]);
        exit;
    }
}
