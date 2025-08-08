<?php
require_once '../jwt.php';

header('Content-Type: application/json');
$headers = getallheaders();

if (!verifyToken($headers)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Floor not found'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id || !in_array($id, ['tokyo', 'narita'])) {  // 仮データ
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Floor not found'
    ]);
    exit;
}

// 仮のフロアデータ
$data = [
    'id' => $id,
    'name' => ($id === 'tokyo') ? '東京' : '成田',
    'attendance' => [
        ['name' => '山田太郎', 'status' => '出席'],
        ['name' => '佐藤花子', 'status' => '欠席']
    ]
];

http_response_code(200);
echo json_encode([
    'status' => 'success',
    'data' => $data
]);
?>
