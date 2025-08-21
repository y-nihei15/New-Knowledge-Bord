<?php
// login.php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';  // $pdo を利用可能にする

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Method Not Allowed');
}

// フォームから受け取る
$userId = $_POST['id'] ?? '';
$pw     = $_POST['password'] ?? '';

if ($userId === '' || $pw === '') {
    exit('IDとパスワードを入力してください');
}

try {
    // users テーブルから user_id をキーに検索
    $stmt = $pdo->prepare(
        'SELECT account_id, user_id, password, user_type, user_role
           FROM users
          WHERE user_id = :user_id
          LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch();

    // ユーザーが存在し、パスワードが一致したらログイン成功
    if ($row && password_verify($pw, $row['password'])) {
        // 成功したら main.php へリダイレクト
        header('Location: ../../main/main.php');
        exit;
    }

    // 認証失敗
    exit('ログイン失敗');

} catch (Throwable $e) {
    error_log('[auth] error: ' . $e->getMessage());
    exit('システムエラー');
}
