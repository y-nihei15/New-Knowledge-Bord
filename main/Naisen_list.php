<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内線一覧</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="Container">
    <!-- 左メニュー -->
    <div class="Sidebar">
        <div class="MenuBox">
            <a href="./main.php" onclick="LoadFloor('hq8f')">本社(8F)</a>
            <a onclick="LoadFloor('hq7f')">本社(7F)</a>
            <a onclick="LoadFloor('hq5f')">本社(5F)</a>
            <a onclick="LoadFloor('tsukuba')">つくばセンター</a>
            <a onclick="LoadFloor('narita')">成田センター</a>
            <a onclick="LoadFloor('sapporo')">札幌センター</a>
            <a onclick="LoadFloor('koriyama')">郡山センター</a>
            <a onclick="LoadFloor('suwa')">諏訪センター</a>
            <a onclick="LoadFloor('nagoya')">名古屋センター</a>
            <a onclick="LoadFloor('hqEbs')">本社(出向/EBS/契約)</a>

            <div class="Spacer"></div>

            <a href="#">内線一覧</a>
        </div>
    </div>

    <!-- メインコンテンツ -->
    <div class="MainContent">
        <div class="Logout">
            <button onclick="Logout()">ログアウト</button>
        </div>

        <div class="ContentArea">
            <div class="PdfViewer">

                <iframe src="./naisen.pdf" id="PdfFrame" title="内線一覧PDF"></iframe>

                <iframe id="PdfFrame" title="内線一覧PDF"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
const Token = localStorage.getItem('token');

/**
 * PDF取得・表示
 */
function LoadPdf() {
    if (!Token) {
        alert('認証トークンがありません。ログインしてください。');
        return;
    }

    fetch('/extensions/pdf', {
        headers: { 'Authorization': 'Bearer ' + Token }
    })
    .then(response => {
        if (response.status === 200) {
            return response.blob();
        } else {
            return response.json().then(err => {
                throw new Error(err.message || 'PDF取得エラー');
            });
        }
    })
    .then(blob => {
        const url = URL.createObjectURL(blob);
        document.getElementById('PdfFrame').src = url;
    })
    .catch(error => {
        alert('PDF表示に失敗しました: ' + error.message);
    });
}

/**
 * 拠点データ取得（表示なし）
 */
function LoadFloor(floorId) {
    if (!Token) {
        alert('認証トークンがありません。ログインしてください。');
        return;
    }

    fetch(`/floors/${floorId}`, {
        headers: { 'Authorization': 'Bearer ' + Token }
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            alert(data.message || '出欠情報取得に失敗しました。');
        }
    })
    .catch(err => {
        alert('拠点情報取得エラー: ' + err.message);
    });
}

/**
 * ログアウト処理
 */
function Logout() {
    if (!Token) {
        window.location.href = 'login.php';
        return;
    }

    fetch('/auth/logout', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + Token }
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            localStorage.removeItem('token');
            window.location.href = 'login.php';
        } else {
            alert('ログアウト失敗: ' + data.message);
        }
    })
    .catch(err => {
        alert('通信エラー: ' + err.message);
    });
}
</script>

</body>
</html>
