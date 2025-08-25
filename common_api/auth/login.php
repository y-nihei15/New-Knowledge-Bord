<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// プリフライトリクエスト対応
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 直アクセス時はログイン画面に飛ばす
    header('Location: ../../main/loginScreen.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
    exit;
}


// POST以外は許可しない
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../jwt/token_issue.php'; // issue_token() を定義
require_once __DIR__ . '/../config/db.php';       // getDbConnection() を定義

// フォーム送信で受け取る
$accountId = $_POST['account_id'] ?? '';
$password  = $_POST['password'] ?? '';

// 未入力チェック
if ($accountId === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'IDとパスワードを入力してください']);
    exit;
}

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // DB検索
    $stmt = $pdo->prepare('SELECT account_id, password FROM login_info WHERE account_id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'ユーザー未存在']);
        exit;
    }

    // パスワード検証（DBが password_hash() 済み前提）
    if (!password_verify($password, $row['password'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'パスワード不一致']);
        exit;
    }

    // JWT発行
    $result = issue_token((int)$row['account_id']);

    echo json_encode([
        'ok'    => true,
        'token' => $result['token'],
        'exp'   => $result['exp']
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    error_log('[auth/login] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'システムエラー']);
    exit;
}
