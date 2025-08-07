<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>社内掲示板</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <ul>
                <li>本社(8F)</li>
                <li>本社(7F)</li>
                <li>本社(6F)</li>
                <li>つくばセンター</li>
                <li>成田センター</li>
                <li>札幌センター</li>
                <li>郡山センター</li>
                <li>西東京センター</li>
                <li>名古屋センター</li>
                <li>本社(他EBS/契約)</li>
                <li>内容一覧</li>
            </ul>
        </aside>
        <main class="content">
            <div class="top-buttons">
                <button>反映</button>
                <button>ログアウト</button>
            </div>

            <?php for ($i = 0; $i < 2; $i++): ?>
            <section class="department-block">
                <h2>部署名</h2>
                <?php for ($j = 0; $j < 6; $j++): ?>
                <div class="row">
                    <div class="label <?php echo ['blue', 'red', 'blue', 'green', 'blue', 'red'][$j]; ?>">名前</div>
                    <div class="inputs">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
                <?php endfor; ?>
            </section>
            <?php endfor; ?>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>
