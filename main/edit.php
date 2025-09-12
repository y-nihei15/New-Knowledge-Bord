<?php
/***********************
 * 出席管理（統合版）
 * - JWT運用に合わせたフロント側の自己修復＆Authorization付与
 * - 管理者のみ「編集」リンクを表示（見た目のみ）
 ***********************/
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../common_api/config/db.php';

/* 共通ユーティリティ */
function e($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* DB 接続を取得 */
$pdo = getDbConnection();

/* ===== APIエンドポイント算出 ===== */
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$apiEndpoint = preg_replace('#/+#', '/', $scriptDir . '/../top_api/update.php');
$apiSource   = preg_replace('#/+#', '/', $scriptDir . '/../top_api/source.php');
$apiLogout   = preg_replace('#/+#', '/', $scriptDir . '/../top_api/logout.php');
$loginScreen = preg_replace('#/+#', '/', $scriptDir . '/loginScreen.php');

/* ===== 表示対象フロア ===== */
$locationId = isset($_GET['location_id']) ? max(1, (int)$_GET['location_id']) : null;
$minId = $pdo->query("SELECT MIN(id) FROM location_mst")->fetchColumn();
if ($locationId === null) {
  $locationId = ($minId === null) ? null : (int)$minId;
}

/* 拠点情報 */
$location = false;
if ($locationId !== null) {
  $locStmt = $pdo->prepare("SELECT id, name FROM location_mst WHERE id = :lid");
  $locStmt->bindValue(':lid', $locationId, PDO::PARAM_INT);
  $locStmt->execute();
  $location = $locStmt->fetch(PDO::FETCH_ASSOC);
}

/* 社員一覧 */
$rows = [];
if ($locationId !== null && $location) {
  $listSql = "
  SELECT
    d.id   AS dept_id, d.name AS dept_name,
    ei.account_id, ei.name AS emp_name, ei.sort,
    ei.status, ei.plan, ei.comment,
    li.user_id
  FROM employee_info ei
  LEFT JOIN dept_mst   d  ON d.id  = ei.dept_id
  LEFT JOIN login_info li ON li.account_id = ei.account_id
  WHERE ei.location_id = :lid
  ORDER BY
    (d.id IS NULL) ASC,
    d.id ASC,
    ei.sort ASC,
    CAST(li.user_id AS UNSIGNED) ASC
";
  $list = $pdo->prepare($listSql);
  $list->bindValue(':lid', $locationId, PDO::PARAM_INT);
  $list->execute();
  $rows = $list->fetchAll(PDO::FETCH_ASSOC);
}

/* 部門でグルーピング */
$groups = [];
foreach ($rows as $r) {
  $key = isset($r['dept_id']) ? (int)$r['dept_id'] : 0;
  if (!isset($groups[$key])) {
    $groups[$key] = ['dept_name' => $r['dept_name'] ?? '未設定', 'members' => []];
  }
  $groups[$key]['members'][] = $r;
}

/* ステータス表現 */
function statusClass(int $tinyInt) {
  switch ($tinyInt) {
    case 1: return ['class' => 'blue',  'data' => 1];
    case 2: return ['class' => 'red',   'data' => 2];
    case 3: return ['class' => 'green', 'data' => 3];
    default:return ['class' => 'red',   'data' => 2];
  }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>社内出席管理</title>
  <link rel="stylesheet" href="css/style.css">

  <!-- ===== 認証ガード ===== -->
  <script>
  (function authGate(){
    const SOURCE = <?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>;
    const LOGIN  = <?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>;
    function saveTokenBoth(t){
      try {
        sessionStorage.setItem('jwt', t);
        sessionStorage.setItem('access_token', t);
      } catch {}
    }
    try {
      const m = location.hash.match(/(?:^#|&)?token=([^&]+)/);
      if (m && m[1]) {
        const t = decodeURIComponent(m[1]);
        if (/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) {
          saveTokenBoth(t);
        }
        history.replaceState(null, '', location.pathname + location.search);
        return;
      }
    } catch {}
    try {
      const u = new URL(location.href);
      const t = u.searchParams.get('token');
      if (t) {
        if (/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) {
          saveTokenBoth(t);
        }
        u.searchParams.delete('token');
        history.replaceState(null, '', u.toString());
        return;
      }
    } catch {}
    try {
      const t = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token');
      if (t && /^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) return;
      sessionStorage.removeItem('jwt');
      sessionStorage.removeItem('access_token');
    } catch {}
    (async () => {
      try {
        const res = await fetch(SOURCE, {
          method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json'},
          body:'{}', credentials:'same-origin'
        });
        if ((res.headers.get('content-type')||'').includes('application/json')) {
          const j = await res.json();
          if (res.ok && j && j.ok === true && j.token) {
            saveTokenBoth(j.token); return;
          }
        }
      } catch {}
      location.replace(LOGIN);
    })();
  })();
  </script>

  <!-- ===== JWTペイロードを読む関数 ===== -->
  <script>
  function b64urlDecode(s){
    if (!s) return '';
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    const pad = s.length % 4;
    if (pad === 2) s += '==';
    else if (pad === 3) s += '=';
    else if (pad === 1) s += '===';
    return atob(s);
  }
  function readJwtRole(debug=false) {
    try {
      const t = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token');
      if (!t) return null;
      const parts = t.split('.');
      if (parts.length !== 3) return null;
      const json = b64urlDecode(parts[1]);
      const obj  = JSON.parse(json);
      if (typeof obj.role === 'number') return obj.role;
      if (obj.role != null && !isNaN(Number(obj.role))) return Number(obj.role);
      if (typeof obj.user_type === 'number') return obj.user_type;
      if (obj.user_type != null && !isNaN(Number(obj.user_type))) return Number(obj.user_type);
      if (obj.is_admin === true) return 1;
      if (obj.is_admin === false) return 0;
      return null;
    } catch { return null; }
  }
  </script>
  <script>
  const EXPORT_URL  = <?= json_encode($scriptDir . '/../top_api/export_csv.php', JSON_UNESCAPED_SLASHES) ?>;
  const CURRENT_LOCATION_ID = <?= (int)$locationId ?>;
  // Reflect() から参照するためグローバル公開
  window.API_ENDPOINT = <?= json_encode($apiEndpoint, JSON_UNESCAPED_SLASHES) ?>;
</script>

</head>
<body>
  <div class="Container">
    <!-- サイドバー -->
    <div class="Sidebar">
      <div class="MenuBox">
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
      </div>
    </div>

    <!-- メイン画面 -->
    <div class="MainContent" id="MainContent">
      <div class="Logout">
        <button onclick="openCsvPicker()">読込</button>
        <button onclick="exportCsv()">出力</button>
        <button onclick="Reflect()">反映</button>
      </div>

      <!-- ▼ここに追加（見えないinputなので場所はここが一番わかりやすい） -->
      <input id="CsvFileInput" type="file" accept=".csv,text/csv" style="display:none">

      <?php if ($locationId === null): ?>
        <div class="ContentArea">
          <h1>拠点データがありません</h1>
        </div>
      <?php elseif (!$location): ?>
        <div class="ContentArea">
          <h1>拠点が見つかりません</h1>
        </div>
      <?php else: ?>
        <?php foreach ($groups as $g): ?>
          <div class="ContentArea">
            <h1><?= e($g['dept_name']) ?></h1>
            <?php foreach ($g['members'] as $m):
              $st = statusClass((int)$m['status']); ?>
              <div class="UserRow">
                <button
                  class="Statusbutton <?= e($st['class']) ?>"
                  data-status="<?= e((string)$st['data']) ?>"
                  data-orig-status="<?= e((string)$st['data']) ?>"
                  data-account-id="<?= (int)$m['account_id'] ?>">
                  <?= e($m['emp_name']) ?>
                </button>
                <div class="UserDetails">
                  <input type="text" placeholder="行先" maxlength="150"
                    value="<?= e((string)$m['plan']) ?>">
                  <input type="text" placeholder="コメント" maxlength="150"
                    value="<?= e((string)$m['comment']) ?>">
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- 管理者だけ「編集」リンクを表示（トークン到着を待つ＋role.phpフォールバック） -->
  <script>
  const ROLE_URL = <?= json_encode($scriptDir . '/../top_api/role.php', JSON_UNESCAPED_SLASHES) ?>;

  function showEditLink(asAdmin) {
    const link = document.getElementById('AdminEditLink');
    if (!link) return;
    if (asAdmin) {
      link.style.display = 'inline';
      document.body.dataset.role = 'admin';
    } else {
      document.body.dataset.role = 'user';
    }
  }

  async function refetchRoleOnce() {
    try {
      const t = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token') || '';
      const res = await fetch(ROLE_URL, {
        method:'POST',
        headers:{
          'Content-Type':'application/json',
          'Accept':'application/json',
          ...(t ? {'Authorization':'Bearer '+t} : {})
        },
        body:'{}',
        credentials:'same-origin'
      });
      if (!res.ok) return null;
      const j = await res.json();
      if (j && j.ok === true && (j.role === 0 || j.role === 1 || j.role === '0' || j.role === '1')) {
        return Number(j.role);
      }
    } catch {}
    return null;
  }

  function tryToggleOnce(){
    const role = readJwtRole(false);
    if (role === 1) { showEditLink(true);  return true; }
    if (role === 0) { showEditLink(false); return true; }
    return false;
  }

  document.addEventListener('DOMContentLoaded', async () => {
    if (tryToggleOnce()) return;

    let tries = 0;
    const iv = setInterval(async () => {
      tries++;
      if (tryToggleOnce() || tries >= 60) {
        clearInterval(iv);
        if (document.body.dataset.role !== 'admin' && document.body.dataset.role !== 'user') {
          const srvRole = await refetchRoleOnce();
          if (srvRole === 1) showEditLink(true);
          else if (srvRole === 0) showEditLink(false);
        }
      }
    }, 200);
  });
  </script>

<script>
function getToken(){
  try { return sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token') || ''; }
  catch { return ''; }
}

// 出力（CSVダウンロード）
async function exportCsv(){
  if (!CURRENT_LOCATION_ID) { alert('拠点が未選択です'); return; }
  const t = getToken();
  try {
    const url = `${EXPORT_URL}?location_id=${encodeURIComponent(CURRENT_LOCATION_ID)}`;
    const res = await fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'text/csv', ...(t ? {'Authorization': 'Bearer '+t} : {}) },
      credentials: 'same-origin'
    });
    if (!res.ok) throw new Error('エクスポートに失敗しました');

    const blob = await res.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `attendance_${CURRENT_LOCATION_ID}_${new Date().toISOString().replace(/[:T]/g,'-').slice(0,19)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  } catch(e) {
    alert(e.message || 'ダウンロードに失敗しました');
  }
}
</script>

<script>
// ファイル選択ダイアログを開く
function openCsvPicker(){
  const inp = document.getElementById('CsvFileInput');
  inp.value = '';
  inp.onchange = () => {
    const file = inp.files && inp.files[0];
    if (file) importCsv(file);
  };
  inp.click();
}

// アップロード→DB反映→画面リロード
async function importCsv(file){
  if (!CURRENT_LOCATION_ID) { alert('拠点が未選択です'); return; }
  const t = getToken();
  const fd = new FormData();
  fd.append('file', file);
  fd.append('location_id', String(CURRENT_LOCATION_ID));

  try {
    const res = await fetch(<?= json_encode($scriptDir . '/../top_api/import_csv.php', JSON_UNESCAPED_SLASHES) ?>, {
      method: 'POST',
      headers: { 'Accept': 'application/json', ...(t ? {'Authorization':'Bearer '+t} : {}) },
      body: fd,
      credentials: 'same-origin'
    });
    const j = await res.json().catch(()=>null);
    if (!res.ok || !j || j.ok !== true) {
      throw new Error((j && j.error) || 'インポートに失敗しました');
    }

    // 詳細ポップアップ（新規部署も表示）
    if (typeof window.showImportResult === 'function') {
      window.showImportResult(j);
    } else {
      // フォールバック（万一未定義なら）
      const msg = `インポート完了: 更新 ${j?.updated ?? 0} / 追加 ${j?.inserted ?? 0} / スキップ ${j?.skipped ?? 0}`;
      alert(msg);
    }

    // 同じ拠点IDで再読込（サーバ側レンダリングに合わせてページ遷移が簡単）
    const u = new URL(location.href);
    u.searchParams.set('location_id', String(CURRENT_LOCATION_ID));
    location.assign(u.toString());
  } catch(e) {
    alert(e.message || 'アップロードに失敗しました');
  }
}
</script>

  <script src="./js/edit_script.js?v=8"></script>

</body>
</html>
