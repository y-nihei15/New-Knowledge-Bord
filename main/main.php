<?php declare(strict_types=1);
require_once __DIR__ . '/../top_api/config/db.php';

function e($v){
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = getDbConnection();

/* ===== APIエンドポイント（動的算出：サブディレクトリでも404にならない） ===== */
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'); // 例: /bbs/view
$apiEndpoint = $scriptDir . '/../top_api/attendance/update.php';     // 例: /bbs/top_api/attendance/update.php
$apiEndpoint = preg_replace('#/+#', '/', $apiEndpoint);              // 連続スラッシュを正規化

/* ===== 表示対象フロア ===== */
$locationId = isset($_GET['location_id']) ? max(1, (int)$_GET['location_id']) : null;
$min = $pdo->query("SELECT MIN(id) FROM location_mst")->fetchColumn();
if ($locationId === null) $locationId = ($min === null) ? null : (int)$min;

/* ===== 拠点情報 ===== */
$location = false;
if ($locationId !== null) {
  $st = $pdo->prepare("SELECT id, name FROM location_mst WHERE id = :lid");
  $st->bindValue(':lid', $locationId, PDO::PARAM_INT);
  $st->execute();
  $location = $st->fetch(PDO::FETCH_ASSOC);
}

/* ===== 社員一覧 ===== */
$rows = [];
if ($locationId !== null && $location) {
  $sql = "
    SELECT
      d.id   AS dept_id,
      d.name AS dept_name,
      ei.account_id,
      ei.name AS emp_name,
      ei.sort,
      ei.status,
      ei.plan,
      ei.comment
    FROM employee_info ei
    LEFT JOIN dept_mst d ON d.id = ei.dept_id
    WHERE ei.location_id = :lid
    ORDER BY (d.id IS NULL) ASC, d.id ASC, ei.sort ASC, ei.account_id ASC
  ";
  $q = $pdo->prepare($sql);
  $q->bindValue(':lid', $locationId, PDO::PARAM_INT);
  $q->execute();
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
}

/* ===== グルーピング ===== */
$groups = [];
foreach ($rows as $r) {
  $key = isset($r['dept_id']) ? (int)$r['dept_id'] : 0;
  if (!isset($groups[$key])) {
    $groups[$key] = ['dept_name' => $r['dept_name'] ?? '未設定', 'members'=>[]];
  }
  $groups[$key]['members'][] = $r;
}

/* ===== ステータス表示（1在席/2欠席/3休暇）→ data-status は 1/2/3 ===== */
function statusClass(int $tinyInt){
  switch ($tinyInt) {
    case 1: return ['class'=>'blue',  'data'=>1]; // present
    case 2: return ['class'=>'red',   'data'=>2]; // absent
    case 3: return ['class'=>'green', 'data'=>3]; // leave
    default:return ['class'=>'blue',  'data'=>1]; // 未設定→present(1)
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
  <div class="Sidebar">
    <div class="MenuBox">
      <!-- location_mst.id に合わせて調整 -->
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

  <div class="MainContent" id="MainContent"><!-- [NOTE] 差し替え時のアンカー -->
    <div class="Logout">
      <button onclick="Reflect()">反映</button>
      <button onclick="Logout()">ログアウト</button>
    </div>

    <?php if ($locationId === null): ?>
      <div class="ContentArea">
        <h1>拠点データがありません</h1>
        <p>location_mst にレコードを追加してください。</p>
      </div>

    <?php elseif (!$location): ?>
      <div class="ContentArea">
        <h1>拠点が見つかりません</h1>
        <p>location_id=<?= e((string)$locationId) ?> は存在しません。</p>
      </div>

    <?php else: ?>
      <?php foreach ($groups as $deptId => $g): ?>
        <div class="ContentArea">
          <h1><?= e($g['dept_name']) ?></h1>
          <?php foreach ($g['members'] as $m): $st = statusClass((int)$m['status']); ?>
            <div class="UserRow">
              <button
                class="Statusbutton <?= e($st['class']) ?>"
                data-status="<?= e((string)$st['data']) ?>"
                data-orig-status="<?= e((string)$st['data']) ?>"
                data-account-id="<?= (int)$m['account_id'] ?>"
              >
                <?= e($m['emp_name']) ?>
              </button>
              <div class="UserDetails">
                <?php $planNull = is_null($m['plan']); $commentNull = is_null($m['comment']); ?>
                <!-- maxlength はあくまで目安。実制御はJSで全角=2/半角=1換算150まで -->
                <input
                  type="text" placeholder="行先" maxlength="150"
                  value="<?= e((string)$m['plan']) ?>"
                  data-orig="<?= $planNull ? '' : e((string)$m['plan']) ?>"
                  data-orig-null="<?= $planNull ? '1' : '0' ?>"
                >
                <input
                  type="text" placeholder="コメント" maxlength="150"
                  value="<?= e((string)$m['comment']) ?>"
                  data-orig="<?= $commentNull ? '' : e((string)$m['comment']) ?>"
                  data-orig-null="<?= $commentNull ? '1' : '0' ?>"
                >
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($g['members'])): ?><p>所属メンバーがいません。</p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($groups)): ?><div class="ContentArea"><p>この拠点には表示可能な社員がいません。</p></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
// ===== 設定 =====
const API_ENDPOINT = <?= json_encode($apiEndpoint, JSON_UNESCAPED_SLASHES) ?>;

// URLの location_id を置き換えて再読み込み
function LoadFloor(id){
  const n = Number(id);
  if (!Number.isInteger(n) || n <= 0) {
    alert('location_id が不正です');
    return;
  }
  const u = new URL(location.href);
  u.searchParams.set('location_id', String(n));
  location.href = u.toString();
}

// 見た目クラス同期（1:blue, 2:red, 3:green）
function applyStatusClass(btn, n){
  btn.classList.remove('blue','red','green');
  if (n === 1) btn.classList.add('blue');
  else if (n === 2) btn.classList.add('red');
  else btn.classList.add('green');
}

// クリックでトグル（シンプル化版）
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest?.('.Statusbutton');
  if (!btn) return;

  let v = Number.parseInt(btn.dataset.status ?? '1', 10);
  if (!Number.isFinite(v) || v < 1 || v > 3) v = 1;
  v = (v % 3) + 1; // 1->2->3->1
  btn.dataset.status = String(v);
  applyStatusClass(btn, v);
});

/* ===== 全角=2 / 半角=1 の換算で150以内に丸める（コメント・行先） =====
   - 半角150文字まで
   - 全角75文字まで
   - 混在時は半角=1, 全角=2の合算が150を超えないように制御
*/
function isHalfWidthAscii(ch){
  // ASCII可視文字 + スペース（0x20-0x7E）を半角とみなす
  return /[ -~]/.test(ch);
}
function trimToVisualLimit(s, maxLen=150){
  if (!s) return '';
  let len = 0, out = '';
  for (const ch of s){
    const add = isHalfWidthAscii(ch) ? 1 : 2;
    if (len + add > maxLen) break;
    len += add;
    out += ch;
  }
  return out;
}

// 入力中にその場で制限を適用
document.addEventListener('input', (ev) => {
  const el = ev.target;
  if (el.matches('.UserDetails input[type="text"]')) {
    const trimmed = trimToVisualLimit(el.value, 150); // 全角=2/半角=1換算で150以内
    if (trimmed !== el.value) el.value = trimmed;
  }
});

// 差分抽出（baseは送らない）
function buildDiffItem(row){
  const btn = row.querySelector('.Statusbutton');
  const id  = Number.parseInt(btn?.dataset?.accountId ?? '', 10);
  if (!Number.isFinite(id) || id <= 0) return null;

  const item = { account_id: id };

  // status（変更があれば送る）
  const curS  = Number.parseInt(btn.dataset.status ?? '1', 10);
  const origS = Number.parseInt(btn.dataset.origStatus ?? '1', 10);
  if (curS !== origS) {
    // APIは '1'|'2'|'3' も受ける
    item.status = String(curS);
  }

  // plan/comment
  const inputs = row.querySelectorAll('.UserDetails input');
  const planEl = inputs[0];
  const commEl = inputs[1];

  if (planEl) {
    const newVal  = trimToVisualLimit((planEl.value ?? '').trim(), 150);
    const origVal = planEl.dataset.orig ?? '';
    const origNull = planEl.dataset.origNull === '1';
    const changed = (origNull && newVal !== '') || (!origNull && newVal !== origVal);
    if (changed) {
      item.plan = (newVal === '') ? '' : newVal;
    }
  }
  if (commEl) {
    const newVal  = trimToVisualLimit((commEl.value ?? '').trim(), 150);
    const origVal = commEl.dataset.orig ?? '';
    const origNull = commEl.dataset.origNull === '1';
    const changed = (origNull && newVal !== '') || (!origNull && newVal !== origVal);
    if (changed) {
      item.comment = (newVal === '') ? '' : newVal;
    }
  }

  return (Object.keys(item).length > 1) ? item : null; // account_id だけなら送らない
}

/* ===== fetch ラッパ（タイムアウト & JSON以外も捕捉） ===== */
// AbortControllerに理由を渡さず、内部フラグでtimeoutを判定
async function postJSON(url, payload, {timeoutMs=15000}={}){
  const controller = new AbortController();
  let timedOut = false;
  const to = setTimeout(() => {
    timedOut = true;
    controller.abort(); // 理由は渡さない（実装差を避ける）
  }, timeoutMs);

  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(payload),
      signal: controller.signal
      // 認証が必要になったら credentials を明示的に付与
    });

    const raw = await res.text(); // PHP警告混入対策でtext→手動JSON
    let data = null;
    if (raw) {
      try {
        data = JSON.parse(raw);
      } catch {
        throw new Error(`Invalid JSON (HTTP ${res.status}): ${raw.slice(0,200)}`);
      }
    }
    if (!res.ok) {
      const msg = (data && data.message) ? data.message : raw.slice(0,200);
      throw new Error(`HTTP ${res.status} ${msg}`);
    }
    return data;

  } catch (err) {
    if (timedOut) throw new Error('timeout');
    throw err;

  } finally {
    clearTimeout(to);
  }
}

// 一括反映：差分のみ送信（先勝ち仕様なし）
async function Reflect(){
  const items = [];
  document.querySelectorAll('.UserRow').forEach(row => {
    const it = buildDiffItem(row);
    if (it) items.push(it);
  });
  if (!items.length) {
    alert('変更がありません');
    return;
  }
  try {
    const data = await postJSON(API_ENDPOINT, { items });
    const results = data?.data?.results ?? [];
    if (results.length) {
      const changed = results.filter(r => r.ok && r.changed).length;
      const failed  = results.filter(r => !r.ok).length;
      if (failed > 0) {
        alert(`一部失敗：変更 ${changed} 件 / 失敗 ${failed} 件`);
      } else if (changed > 0) {
        alert(`更新しました（変更 ${changed} 件）`);
        // 成功後：orig を最新に同期
        document.querySelectorAll('.UserRow').forEach(row => {
          const btn = row.querySelector('.Statusbutton');
          if (btn) btn.dataset.origStatus = btn.dataset.status ?? '1';
          const inputs = row.querySelectorAll('.UserDetails input');
          if (inputs[0]) {
            const v = inputs[0].value.trim();
            inputs[0].dataset.orig = v;
            inputs[0].dataset.origNull = (v===''?'1':'0');
          }
          if (inputs[1]) {
            const v = inputs[1].value.trim();
            inputs[1].dataset.orig = v;
            inputs[1].dataset.origNull = (v===''?'1':'0');
          }
        });
      } else {
        alert('保存済みの内容と同一でした（変更なし）');
      }
    } else {
      const c = data?.data?.changed ? 1 : 0;
      alert(c ? '更新しました（1件）' : '保存済みの内容と同一でした（変更なし）');
    }
  } catch (err) {
    console.error('通信エラー詳細:', err);
    alert('通信エラー: ' + (err?.message ?? '不明なエラー'));
  }
}

// ダミー：ログアウト
function Logout(){ /* 実装に合わせて */ }
</script>
</body>
</html>
