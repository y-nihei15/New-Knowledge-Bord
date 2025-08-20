<?php
// 共通：DB接続 & XSSエスケープ
require_once __DIR__ . '/../top_api/config/db.php';   // ← パスを統一
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
// ※data 属性の値は 0:present, 1:absent, 2:leave の“0始まり”で保持（既存仕様を維持）
function statusClass($tinyInt){
    switch ((int)$tinyInt) {
        case 1: return ['class'=>'blue',  'data'=>0]; // present
        case 2: return ['class'=>'red',   'data'=>1]; // absent
        case 3: return ['class'=>'green', 'data'=>2]; // leave
        default:return ['class'=>'red',   'data'=>0]; // 未/デフォルト→present相当で送る
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
            <a onclick="LoadFloor(1)">本社(8F)</a>
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
                    <h1><?= e($g['dept_name']) ?></h1>

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

<script src="./js/script.js"></script>

<script>
// URLの location_id を置き換えて再読み込み
function LoadFloor(id){
  const u = new URL(location.href);
  u.searchParams.set('location_id', String(id));
  location.href = u.toString();
}

// ダミー：ログアウト（必要に応じて実装）
function Logout(){ /* 実装に合わせて */ }
</script>
<!--<script>
// URLの location_id を置き換えて再読み込み
function LoadFloor(id){
   if(!Number.isInteger(id)){ alert('location_id が不正です'); return; }
   const url = new URL(window.location.href);
   url.searchParams.set('location_id', id);
   window.location.href = url.toString();
 }

// ダミー：送信・ログアウト（必要に応じて実装）
 function Reflect(){ /* fetchでAPIにPOST/PATCHする実装に差し替え */ }
 function Logout(){ /* 実装に合わせて */ }
</script> -->

<!--<script>
// URLの location_id を置き換えて再読み込み
function LoadFloor(id){
   if(!Number.isInteger(id)){ alert('location_id が不正です'); return; }
   const url = new URL(window.location.href);
   url.searchParams.set('location_id', id);
   window.location.href = url.toString();
 }

// ダミー：送信・ログアウト（必要に応じて実装）
 function Reflect(){ /* fetchでAPIにPOST/PATCHする実装に差し替え */ }
 function Logout(){ /* 実装に合わせて */ }
</script> -->


<!-- ここから：Reflect 実装（/attendance_update.php を叩く） -->
<script>
// data-status(0/1/2) → API仕様の文字列へ
function statusCodeToStr(n){
  const v = parseInt(n, 10);
  if (v === 0) return 'present';
  if (v === 1) return 'absent';
  if (v === 2) return 'leave';
  // 念のため1始まり(1/2/3)も受容
  if (v === 1) return 'absent';
  if (v === 3) return 'leave';
  return 'present';
}

// 一括反映：画面全件を収集して POST
async function Reflect(){
  const items = [];
  document.querySelectorAll('.UserRow').forEach(row => {
    const btn = row.querySelector('.Statusbutton');
    const inputs = row.querySelectorAll('input');
    const planVal    = (inputs[0]?.value ?? '').trim();
    const commentVal = (inputs[1]?.value ?? '').trim();

    items.push({
      account_id: parseInt(btn.dataset.accountId, 10),
      status: statusCodeToStr(btn.dataset.status), // present/absent/leave
      plan:    planVal === '' ? '' : planVal,      // 空文字→API側でNULL
      comment: commentVal === '' ? '' : commentVal
    });
  });

  try {
    const res  = await fetch('../top_api/attendance/attendance_update.php', {
      method : 'POST',
      headers: {'Content-Type':'application/json'},
      body   : JSON.stringify({items})
    });
    const data = await res.json();
    if (data.status === 'success') {
      alert('更新しました');
    } else {
      alert('更新に失敗: ' + (data.message ?? 'unknown error'));
    }
  } catch (e){
    console.error(e);
    alert('通信エラーが発生しました');
  }
}
</script>
</body>
</html>
