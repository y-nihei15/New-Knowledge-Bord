<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    jsonResponse("error", "許可されていないメソッドです");
}

$authUser = authenticate();

try {
    $pdo = getDbConnection();

    // 1) 本人の出勤状態
    $stmt = $pdo->prepare(
        "SELECT status, updated_at
           FROM attendance_status
          WHERE user_id = :uid
          LIMIT 1"
    );
    $stmt->bindValue(':uid', $authUser->user_id, PDO::PARAM_INT);
    $stmt->execute();
    $me = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2) 全社員分の出勤状態（ページング）
    //    ?limit=100&offset=0 で制御（上限ガード付き）
    $limit  = isset($_GET['limit'])  ? max(1, min( (int)$_GET['limit'], 200)) : 200;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // 可能なら users に name/department 等の列を用意
    $sql = "SELECT
                u.id   AS user_id,
                u.name AS name,
                a.status,
                a.updated_at
            FROM users u
            LEFT JOIN attendance_status a
                   ON a.user_id = u.id
            ORDER BY u.id ASC
            LIMIT :limit OFFSET :offset";

    $listStmt = $pdo->prepare($sql);
    $listStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $members = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        "self" => [
            "user_id"    => (int)$authUser->user_id,
            "attendance" => $me ? [
                "status"     => $me['status'],
                "updated_at" => $me['updated_at']
            ] : null
        ],
        "members" => $members,
        "paging" => [
            "limit"  => $limit,
            "offset" => $offset
        ]
    ];

    jsonResponse("success", "TOP画面の取得に成功しました", $data);
} catch (Throwable $e) {
    error_log("[DASHBOARD] ".$e->getMessage());
    http_response_code(500);
    jsonResponse("error", "Server Error");
}
