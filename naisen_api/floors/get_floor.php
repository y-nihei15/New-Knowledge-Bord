<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = $_GET['id'] ?? null;
if ($id === null || $id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing id'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 環境に合わせてここを設定（MySQL接続）======
$dsn  = 'mysql:host=localhost;dbname=attendance;charset=utf8mb4';
$user = 'db_user';
$pass = 'db_password';
// ================================================

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * location_mst: 例) id(VARCHAR: 'tokyo'), name(VARCHAR: '東京')
 * employee_info: 例) location_id(VARCHAR), name(VARCHAR), status(INTやVARCHARのコード)
 *
 * もし主キーやカラム名が違うなら、下のSQLを実テーブルに合わせて書き換えてください。
 */

// フロア情報（location_mst から取得）
$sqlFloor = 'SELECT id, name FROM location_mst WHERE id = :id LIMIT 1';
$stmt = $pdo->prepare($sqlFloor);
$stmt->execute([':id' => $id]);
$floor = $stmt->fetch();

if (!$floor) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Floor not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 出席者（employee_info から取得）※ status はコードのまま返す
$sqlAtt = 'SELECT name, status FROM employee_info WHERE location_id = :id ORDER BY name';
$stmt = $pdo->prepare($sqlAtt);
$stmt->execute([':id' => $id]);
$attendance = $stmt->fetchAll();

// レスポンス組み立て
$data = [
    'id'         => $floor['id'],
    'name'       => $floor['name'],
    'attendance' => $attendance, // 例: [{ "name": "山田太郎", "status": 1 }, ...]
];

http_response_code(200);
echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
