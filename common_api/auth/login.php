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
    $stmt = $pdo->prepare(
        'SELECT id AS login_id, user_id, account_name, role, password_hash, status
           FROM users
          WHERE login_id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    // ユーザーが存在してアクティブで、パスワード一致ならログイン成功
    // if ($row && ($row['status'] ?? '') === 'active' && password_verify($pw, $row['password_hash'])) {
    //     header('Location: ../../main/main.php');
    //     exit;
    // }

    
    if ($row && ($row['status'] ?? '') === 'active' && $pw === $row['password_hash']) {
    header('Location: ../../main/main.php');
    exit;
}


    // 失敗時
    exit('ログイン失敗');

} catch (Throwable $e) {
    error_log('[auth] error: ' . $e->getMessage());
    exit('システムエラー');
}
