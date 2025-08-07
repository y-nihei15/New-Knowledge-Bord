<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>社内掲示板</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <aside class="SideBar">
    <ul class="SideBarList">
      <li>本社(8F)</li>
      <li>本社(7F)</li>
      <li>本社(6F)</li>
      <li>つくばセンター</li>
      <li>成田センター</li>
      <li>札幌センター</li>
      <li>郡山センター</li>
      <li>西東京センター</li>
      <li>名古屋センター</li>
      <li>本社(出向/EBS/契約)</li>
      <li>内容一覧</li>
    </ul>
  </aside>

  <main class="MainContainer">
    <div class="HeaderArea">
      <button class="Button ButtonReflect">反映</button>
      <button class="Button ButtonLogout">ログアウト</button>
    </div>

    <section class="DepartmentSection">
      <h2 class="DepartmentTitle">第一開発部</h2>

      <div class="MemberCard">
        <div class="NameBox NameBoxBlue">田中</div>
        <div class="InputArea">
          <input type="text" placeholder="行先">
          <input type="text" placeholder="コメント">
        </div>
      </div>

      <div class="MemberCard">
        <div class="NameBox NameBoxRed">佐藤</div>
        <div class="InputArea">
          <input type="text" placeholder="行先">
          <input type="text" placeholder="コメント">
        </div>
      </div>
    </section>
  </main>

  <script src="Script.js"></script>
</body>
</html>
