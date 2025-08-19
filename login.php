<?php
// login.php
declare(strict_types=1);
session_start();

// ------- デモ用ユーザー（本番はDB/LDAPへ） -------
$USERS = [
    'employee01' => 'pass1234',
    'admin'      => 'admin1234',
];

// ------- ログアウト -------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ------- CSRF -------
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ------- 認証処理 -------
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId   = trim((string)($_POST['user_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf     = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
        $errors[] = '不正なリクエストです。';
    }
    if ($userId === '' || $password === '') {
        $errors[] = 'ユーザーIDとパスワードを入力してください。';
    }

    if (!$errors) {
        if (isset($USERS[$userId]) && hash_equals($USERS[$userId], $password)) {
            $_SESSION['authed'] = true;
            $_SESSION['user']   = $userId;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); // PRG
            exit;
        } else {
            $errors[] = 'ユーザーIDまたはパスワードが正しくありません。';
        }
    }
}
$isAuthed = !empty($_SESSION['authed']);
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>社内出欠管理｜ログイン</title>
  <link rel="stylesheet" href="login.css">
</head>
<body>
<?php if ($isAuthed): ?>
  <div class="card">
    <h1 class="title">社内出欠管理</h1>
    <p><?= htmlspecialchars($_SESSION['user']) ?> さん、ログインしています。</p>
    <p class="footer">（ここにダッシュボードや出欠入力画面へのリンクを配置）</p>
    <a class="logout" href="?action=logout">ログアウト</a>
  </div>
<?php else: ?>
  <div class="card" role="form" aria-labelledby="title">
    <h1 id="title" class="title">社内出欠管理</h1>

    <?php if ($errors): ?>
      <div class="errors">
        <?php foreach ($errors as $e): ?>
          <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <label for="user_id">ユーザーID</label>
      <input id="user_id" name="user_id" type="text" inputmode="latin" required>
      <label for="password">パスワード</label>
      <input id="password" name="password" type="password" required>
      <button class="btn" type="submit">ログイン</button>
    </form>
  </div>
<?php endif; ?>
</body>
</html>
