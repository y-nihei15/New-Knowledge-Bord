<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../vendor/autoload.php';

function _getAuthorizationHeader() {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    } else {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
    }
    return $headers['Authorization'] ?? $headers['authorization'] ?? null;
}

function authenticate() {
    $authHeader = _getAuthorizationHeader();
    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "認証に失敗しました"]);
        exit;
    }

    $jwt = $m[1];
    $secret = 'your_jwt_secret';

    try {
        $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
        // ここで必要に応じてexp/iat/audの検証を追加
        return $decoded; // 期待: { user_id: int, ... }
    } catch (Throwable $e) {
        error_log("[AUTH] " . $e->getMessage()); // 詳細はログのみ
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "認証に失敗しました"]);
        exit;
    }
}
