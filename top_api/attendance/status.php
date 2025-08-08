<?php
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../utils/response.php';
// require_once __DIR__ . '/../config/db.php'; ← DBがまだならコメントアウト

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PATCH");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    jsonResponse("error", "許可されていないメソッドです");
}

$user = authenticate();

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['status'])) {
    http_response_code(400);
    jsonResponse("error", "リクエストの形式が不正です");
}

$status = $input['status'];
$valid = ['present', 'absent', 'leave'];
if (!in_array($status, $valid)) {
    http_response_code(400);
    jsonResponse("error", "Invalid status");
}

// 本来はDB更新だが、今は仮レスポンス
// $pdo = getDbConnection();
// ... SQL省略

jsonResponse("success", "（仮）ステータス「{$status}」が設定されました");
