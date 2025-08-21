
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="./css/style.css">
  <title>社内出欠管理 ログイン</title>
</head>

<body>
  <div class="LoginWrapper">
    <section class="LoginPanel">
      <h1>社内出欠管理</h1>

      <form class="LoginFields" method="post" action="" autocomplete="on">
        <p class="LoginLabel">ユーザーID</p>
        <input class="LoginText" type="text" name="userId" required autocomplete="username">

        <p class="LoginLabel">パスワード</p>
        <input class="LoginText" type="password" name="password" required autocomplete="current-password">

        <button class="LoginSubmit" type="submit">ログイン</button>
      </form>
    </section>
  </div>
  
  <script src="./js/login.js"></script>
</body>
</html>
