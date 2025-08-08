<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内線一覧画面</title>
    <link rel="stylesheet" href="style_otake.css"> 
</head>
<body>
    <div class="container">
        <!-- サイドバー -->
        <div class="sidebar">
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

            <!-- スペース -->
            <div class="spacer"></div>

            <a href="index.html">内線一覧</a>
        </div>

        <!-- メイン -->
        <div class="main">
            <button class="logout-btn">ログアウト</button>

            <div class="pdf-viewer">
                <iframe src="files/naisen_list.pdf" title="内線PDF"></iframe>
            </div>
        </div>
    </div>
</body>
</html>
