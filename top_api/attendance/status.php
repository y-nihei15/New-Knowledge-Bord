<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    jsonResponse("error", "許可されていないメソッドです");
}


// 受信JSON
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || !isset($input['status'])) {
    http_response_code(400);
    jsonResponse("error", "リクエストの形式が不正です");
}

$status = $input['status'];
$validStatuses = ['present', 'absent', 'leave']; // 設計書どおり

if (!in_array($status, $validStatuses, true)) {
    http_response_code(400);
    jsonResponse("error", "Invalid status");
}

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // ユーザーの行がなければINSERT、あればUPDATE（安全なUPSERT）
    // MySQL 5.7+ なら ON DUPLICATE KEY UPDATE を使用（user_id UNIQUE を想定）
    $sql = "INSERT INTO attendance_status (user_id, status, updated_at)
            VALUES (:user_id, :status, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $authUser->user_id, PDO::PARAM_INT);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->execute();

    $pdo->commit();
    jsonResponse("success", "出勤状態を更新しました");
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log("[ATTENDANCE_STATUS] " . $e->getMessage()); // 内部ログのみ詳細
    http_response_code(500);
    jsonResponse("error", "Server Error");
}
