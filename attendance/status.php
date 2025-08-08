<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../middleware/auth.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PATCH");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    jsonResponse("error", "許可されていないメソッドです");
}

$authUser = authenticate();
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['status'])) {
    http_response_code(400);
    jsonResponse("error", "リクエストの形式が不正です");
}

$status = $input['status'];
$validStatuses = ['present', 'absent', 'leave'];

if (!in_array($status, $validStatuses)) {
    http_response_code(400);
    jsonResponse("error", "Invalid status");
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("UPDATE attendance_status SET status = :status WHERE user_id = :user_id");
    $stmt->execute([
        ':status' => $status,
        ':user_id' => $authUser->user_id
    ]);

    jsonResponse("success", "出勤状態を更新しました");

} catch (Exception $e) {
    http_response_code(500);
    jsonResponse("error", "Server Error");
}
