<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8" />
  <title>社内出欠管理 - ログイン</title>
  <link rel="stylesheet" href="login_otake.css" />
</head>
<body>
  <div class="login-container">
    <div class="login-box">
      <h2>社内出欠管理</h2>
      <form action="login.php" method="post">
        <label for="user">ユーザーID</label>
        <input type="text" id="user" name="user" required />

        <label for="pass">パスワード</label>
        <input type="password" id="pass" name="pass" required />

        <button type="submit">ログイン</button>
      </form>
    </div>
  </div>
</body>
</html>
