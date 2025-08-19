<?php
// 共通：DB接続 & XSSエスケープ
require_once __DIR__ . '/../top_api/config/db.php'; 
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$pdo = getDbConnection();

// —— 表示対象フロア（location） ——
// ?location_id= の指定が無ければ最小ID
$locationId = isset($_GET['location_id']) ? max(1, (int)$_GET['location_id']) : null;
if ($locationId === null) {
    $locationId = (int)$pdo->query("SELECT MIN(id) FROM location_mst")->fetchColumn();
}

// 拠点名
$locStmt = $pdo->prepare("SELECT id, name FROM location_mst WHERE id = :lid");
$locStmt->bindValue(':lid', $locationId, PDO::PARAM_INT);
$locStmt->execute();
$location = $locStmt->fetch(PDO::FETCH_ASSOC);

// 部門ごと社員一覧
$listSql = "
  SELECT
    d.id   AS dept_id, d.name AS dept_name,
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

// ステータス表現（0未設定/1在席/2欠席/3休暇）
function statusClass($tinyInt){
    switch ((int)$tinyInt) {
        case 1: return ['class'=>'blue',/*'label'=>'在席',*/'data'=>1];
        case 2: return ['class'=>'red',/*'label'=>'欠席',*/'data'=>2];
        case 3: return ['class'=>'green',/*'label'=>'休暇',*/'data'=>3];
        default:return ['class'=>'red',/*'label'=>'未',*/'data'=>0];
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
    <!-- サイドバー -->
    <div class="Sidebar">
        <div class="MenuBox">
            <!-- location_mst.id に合わせて数字を調整してください -->
            <a href="#" onclick="LoadFloor(1)">本社(8F)</a>
            <a onclick="LoadFloor(2)">本社(7F)</a>
            <a onclick="LoadFloor(3)">本社(5F)</a>
            <a onclick="LoadFloor(4)">つくばセンター</a>
            <a onclick="LoadFloor(5)">成田センター</a>
            <a onclick="LoadFloor(6)">札幌センター</a>
            <a onclick="LoadFloor(7)">郡山センター</a>
            <a onclick="LoadFloor(8)">諏訪センター</a>
            <a onclick="LoadFloor(9)">名古屋センター</a>
            <a onclick="LoadFloor(10)">本社(出向/EBS/契約)</a>
            <div class="Spacer"></div>
            <a href="./Naisen_list.php">内線一覧</a>
        </div>
    </div>

    <!-- メイン画面 -->
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

<script src="./js/script.js">
// URLの location_id を置き換えて再読み込み
function LoadFloor(id){
  if(!Number.isInteger(id)){ alert('location_id が不正です'); return; }
  const url = new URL(window.location.href);
  url.searchParams.set('location_id', id);
  window.location.href = url.toString();
}

// ダミー：送信・ログアウト（必要に応じて実装）
// function Reflect(){ /* fetchでAPIにPOST/PATCHする実装に差し替え */ }
// function Logout(){ /* 実装に合わせて */ }
</script>
</body>
</html>
