<?php
// login.php
declare(strict_types=1);
require_once __DIR__ . '../config/db.php';  // $pdo 利用可能

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Method Not Allowed');
}

$id = $_POST['id'] ?? '';
$pw = $_POST['password'] ?? '';

if ($id === '' || $pw === '') {
    exit('IDとパスワードを入力してください');
}

try {
    // user_id をログインIDとして使う想定
    $stmt = $pdo->prepare(
        'SELECT account_id, user_id, password, user_type, user_role
           FROM users
          WHERE user_id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    // ユーザーが存在し、パスワードが一致すればログイン成功
    if ($row && password_verify($pw, $row['password'])) {
        // 成功したら main.php へリダイレクト
        header('Location: ../../main/main.php');
        exit;
    }

    // 失敗した場合
    exit('ログイン失敗');

} catch (Throwable $e) {
    error_log('[auth] error: ' . $e->getMessage());
    exit('システムエラー');
}
