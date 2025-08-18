<?php
// 共通：DB接続 & XSSエスケープ
require_once __DIR__ . '../top_api/config/db.php';
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$pdo = getDbConnection();

// —— 表示対象フロア（location）を決める ——
// JSの LoadFloor() から ?location_id= の形で遷移させます。
// 初期表示は最小IDの拠点。
$locationId = isset($_GET['location_id']) ? max(1, (int)$_GET['location_id']) : null;
if ($locationId === null) {
    $locationId = (int)$pdo->query("SELECT MIN(id) FROM location_mst")->fetchColumn();
}

// 拠点名
$locStmt = $pdo->prepare("SELECT id, name FROM location_mst WHERE id = :lid");
$locStmt->bindValue(':lid', $locationId, PDO::PARAM_INT);
$locStmt->execute();
$location = $locStmt->fetch(PDO::FETCH_ASSOC);

// 部門ごと社員一覧（並び順は employee_info.sort → account_id）
$listSql = "
  SELECT
    d.id   AS dept_id,       d.name AS dept_name,
    ei.account_id, ei.name AS emp_name, ei.sort,
    ei.status, ei.plan, ei.comment
  FROM employee_info ei
  LEFT JOIN dept_mst d ON d.id = ei.dept_id
  WHERE ei.location_id = :lid
  ORDER BY d.id ASC, ei.sort ASC, ei.account_id ASC
";
$list = $pdo->prepare($listSql);
$list->bindValue(':lid', $locationId, PDO::PARAM_INT);
$list->execute();
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

// 部門でグルーピング
$groups = [];
foreach ($rows as $r) {
    $groups[$r['dept_id']]['dept_name'] = $r['dept_name'] ?? '未設定';
    $groups[$r['dept_id']]['members'][] = $r;
}

// ステータス表現（TINYINT: 0未設定/1出勤/2欠勤/3有給）
function statusClass($tinyInt){
    switch ((int)$tinyInt) {
        case 1: return ['class'=>'blue','label'=>'在席','data'=>1];
        case 2: return ['class'=>'red','label'=>'欠席','data'=>2];
        case 3: return ['class'=>'green','label'=>'休暇','data'=>3];
        default:return ['class'=>'red','label'=>'未','data'=>0]; // 既存CSSに合わせ redを既定
    }
}
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
    <!-- サイドバー（クラス名はそのまま） -->
    <div class="Sidebar">
        <div class="MenuBox">
            <!-- location_id はダミー。実際のIDに合わせて map 内を調整 -->
            <a href="#" onclick="LoadFloor('WHERE ei.location_id = 1')">本社(8F)</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 2')">本社(7F)</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 3')">本社(5F)</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 4')">つくばセンター</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 5')">成田センター</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 6')">札幌センター</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 7')">郡山センター</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 8')">諏訪センター</a>
            <a onclick="LoadFloor('WHERE ei.location_id = 9')">名古屋センター</a>
            <a onclick="LoadFloor('WHERE ei.location_id = s10')">本社(出向/EBS/契約)</a>
            <div class="Spacer"></div>
            <a href="./Naisen_list.php">内線一覧</a>
        </div>
    </div>

    <!-- メイン画面（クラス名はそのまま） -->
    <div class="MainContent">
        <div class="Logout">
            <button onclick="Reflect()">反映</button>
            <button onclick="Logout()">ログアウト</button>
        </div>

        <?php if ($location): ?>
            <?php foreach ($groups as $deptId => $g): ?>
                <div class="ContentArea">
                    <h1><?= e($g['dept_name']) ?>（<?= e($location['name']) ?>）</h1>

                    <?php foreach ($g['members'] as $m):
                        $st = statusClass($m['status']); ?>
                        <div class="UserRow">
                            <!-- 既存クラス名を維持：Statusbutton + 色クラス。data-status も保持 -->
                            <button class="Statusbutton <?= e($st['class']) ?>"
                                    data-status="<?= e($st['data']) ?>"
                                    data-account-id="<?= (int)$m['account_id'] ?>">
                                <?= e($m['emp_name']) ?>
                            </button>
                            <div class="UserDetails">
                                <input type="text" placeholder="行先" value="<?= e($m['plan']) ?>">
                                <input type="text" placeholder="コメント" value="<?= e($m['comment']) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>


// 例：反映ボタンで選択行をまとめて送る（APIなし・同ページPOSTにする場合は適宜変更）
function Reflect(){
  // ここでは UI のまま。既存APIがあるなら fetch('/attendance/status', {...}) で送信してください。
  alert('変更内容を送信する処理を実装してください（API接続 or 同ページPOST）');
}
function Logout(){ /* 実装に合わせて */ }
</script>
</body>
</html>
