<?php
require_once '../jwt.php';

header('Content-Type: application/json');
$headers = getallheaders();

if (!verifyToken($headers)) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid token'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// 通常はセッション削除やトークン無効化を行う
echo json_encode(['status' => 'success']);
http_response_code(200);
?>
