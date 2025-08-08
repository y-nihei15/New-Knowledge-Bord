<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../vendor/autoload.php';

function authenticate() {
    $headers = apache_request_headers();
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "認証に失敗しました"]);
        exit;
    }

    $authHeader = $headers['Authorization'];
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "認証に失敗しました"]);
        exit;
    }

    $jwt = $matches[1];
    $secret = 'your_jwt_secret';

    try {
        $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
        return $decoded;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "認証に失敗しました"]);
        exit;
    }
}
