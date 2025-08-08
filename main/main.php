<?php
// db.phpの読み込み（必要に応じて）
include 'db.php';
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>社内出席管理</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="Container">
        <!-- サイドバー -->
        <div class="Sidebar">
            <div class="MenuBox">
                <a onclick="LoadFloor('hq8f')">本社(8F)</a>
                <a onclick="LoadFloor('hq7f')">本社(7F)</a>
                <a onclick="LoadFloor('hq5f')">本社(5F)</a>
                <a onclick="LoadFloor('tsukuba')">つくばセンター</a>
                <a onclick="LoadFloor('narita')">成田センター</a>
                <a onclick="LoadFloor('sapporo')">札幌センター</a>
                <a onclick="LoadFloor('koriyama')">郡山センター</a>
                <a onclick="LoadFloor('suwa')">諏訪センター</a>
                <a onclick="LoadFloor('nagoya')">名古屋センター</a>
                <a onclick="LoadFloor('dispatch')">本社(出向/EBS/契約)</a>
                <div class="Spacer"></div>
                <a onclick="LoadPdf()">内線一覧</a>
            </div>
        </div>

        <!-- メイン画面 -->
        <div class="MainContent">
            <div class="Logout">
                <button onclick="Reflect()">反映</button>
                <button onclick="Logout()">ログアウト</button>
            </div>

            <div class="ContentArea">
                <h1>部署名</h1>
              
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>

                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>

                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
                
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
                
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
                
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
            </div>

            <div class="ContentArea">
                <h1>部署名</h1>
              
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>

                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>

                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
                
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
                
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
                
                <div class="UserRow">
                    <button class="Statusbutton red" data-status="0">名前</button>
                    <div class="UserDetails">
                        <input type="text" placeholder="行先">
                        <input type="text" placeholder="コメント">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
</body>
</html>
