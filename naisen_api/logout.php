<?php
//require_once '../core/jwt.php';

header('Content-Type: application/json');
/*$headers = getallheaders();

if (!verifyToken($headers)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid token'
    ]);
    exit;
}*/

// トークン無効化処理が必要ならここで実装

http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'Logged out successfully'
]);
?>
