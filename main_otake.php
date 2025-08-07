<!--
[feat] 内線一覧PDF表示画面の新規追加:
システム: 内線情報をPDFとして表示するためのUIを新規作成
-->

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内線一覧画面</title>
    <link rel="stylesheet" href="style_otake.css">
</head>
<body>
    <div class="container">
        <!-- サイドメニュー -->
        <aside class="sidebar">
            <a href="#">本社(8F)</a>
            <a href="#">本社(7F)</a>
            <a href="#">本社(5F)</a>
            <a href="#">つくばセンター</a>
            <a href="#">成田センター</a>
            <a href="#">札幌センター</a>
            <a href="#">郡山センター</a>
            <a href="#">諏訪センター</a>
            <a href="#">名古屋センター</a>
            <a href="#">本社(出荷/EBS/契約)</a>
            <a href="index.php">内線一覧</a>
        </aside>

        <!-- メインエリア -->
        <main class="main">
            <button class="logout-btn">ログアウト</button>
            <h2>内線一覧PDF</h2>
            <div class="pdf-viewer">
                <iframe src="files/naisen_list.pdf" title="内線PDF"></iframe>
            </div>
        </main>
    </div>
</body>
</html>
