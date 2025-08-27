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

      <!-- JSでsubmitを止めてfetchする（method/actionは残してOK） -->
      <form id="loginForm" class="LoginFields" method="post" action="../common_api/auth/login.php" autocomplete="on" novalidate>
        <p class="LoginLabel">ユーザーID</p>
        <input class="LoginText" type="text" name="account_id" required autocomplete="username">

        <p class="LoginLabel">パスワード</p>
        <input class="LoginText" type="password" name="password" required autocomplete="current-password">

        <button class="LoginSubmit" type="submit">ログイン</button>
      </form>

    </section>
  </div>

  <script>
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
      e.preventDefault(); // 通常のフォーム送信を止める

      const fd = new FormData(e.target);
      const res = await fetch('../common_api/auth/login.php', {
        method: 'POST',
        body: new URLSearchParams(fd)
      });
      const data = await res.json().catch(() => null);

      // ← ここでチェックして main.html に遷移
      if (data && data.ok && data.token) {
        sessionStorage.setItem('access_token', data.token);
        location.href = './main.php'; // ← 殻ページに遷移
      } else {
        alert(data?.error || 'ログイン失敗');
      }
    });
  </script>
</body>

</html>