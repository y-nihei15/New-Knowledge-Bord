<?php
/***********************
 * 出席管理（統合版）
 * - 「1」をベースに、「2」の堅牢化/差分送信/文字数制御などを反映
 * - 追加: JWT運用に合わせたフロント側の自己修復＆Authorization付与
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

/* ===== APIエンドポイント（サブディレクトリでも404にならないよう動的算出） ===== */
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'); // 例: /bbs/view
$apiEndpoint = $scriptDir . '/../top_api/update.php';               // 例: /bbs/top_api/update.php
$apiEndpoint = preg_replace('#/+#', '/', $apiEndpoint);             // 連続スラッシュ正規化

/* 追加: source / login / logout のパスも算出（※デザイン変更なしの最小追記） */
$apiSource    = preg_replace('#/+#', '/', $scriptDir . '/../top_api/source.php');
$apiLogout    = preg_replace('#/+#', '/', $scriptDir . '/../top_api/logout.php');
$loginScreen  = preg_replace('#/+#', '/', $scriptDir . '/loginScreen.php');

/* ===== 表示対象フロア（location） ===== */
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
        ei.status, ei.plan, ei.comment
      FROM employee_info ei
      LEFT JOIN dept_mst d ON d.id = ei.dept_id
      WHERE ei.location_id = :lid
      ORDER BY (d.id IS NULL) ASC, d.id ASC, ei.sort ASC, ei.account_id ASC
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

/* ステータス表現（1在席/2欠席/3休暇）→ data-status は 1/2/3 を採用 */
function statusClass(int $tinyInt) {
  switch ($tinyInt) {
    case 1: return ['class' => 'blue',  'data' => 1]; // present
    case 2: return ['class' => 'red',   'data' => 2]; // absent
    case 3: return ['class' => 'green', 'data' => 3]; // leave
    default:return ['class' => 'red',   'data' => 2]; // 未設定→absent(2)
  }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>社内出席管理</title>
  <link rel="stylesheet" href="css/style.css">

  <!-- ===== 認証ガード（自己修復→失敗でログイン画面へ。アラート無し） =====
       ※ 変更点：#token=... を最優先で取り込み、次に ?token=... 互換、最後に source.php で自己修復 -->
  <script>
  (function authGate(){
    const SOURCE = <?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>;
    const LOGIN  = <?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>;

    function saveTokenBoth(t){
      try {
        sessionStorage.setItem('jwt', t);
        sessionStorage.setItem('access_token', t); // 互換: 古いコード対策
      } catch {}
    }

    // (A) #token=... を最優先で保存してURLから即除去（★追加）
    try {
      const m = location.hash.match(/(?:^#|&)?token=([^&]+)/);
      if (m && m[1]) {
        const t = decodeURIComponent(m[1]);
        if (/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) {
          saveTokenBoth(t);
        }
        history.replaceState(null, '', location.pathname + location.search); // ハッシュ消去
        return;
      }
    } catch {}

    // 1) URL ?token=... を保存してURLから除去（従来互換）
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

    // 2) 形式チェック（壊れていたら捨てる）／両キー対応で読む
    try {
      const t = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token');
      if (t && /^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) return;
      sessionStorage.removeItem('jwt');
      sessionStorage.removeItem('access_token');
    } catch {}

    // 3) 無ければ source.php(JSON) で取得リトライ
    (async () => {
      try {
        const res = await fetch(SOURCE, {
          method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json'},
          body:'{}',
          credentials:'same-origin',
          redirect:'follow'
        });
        if ((res.headers.get('content-type')||'').includes('application/json')) {
          const j = await res.json();
          if (res.ok && j && j.ok === true && j.token) {
            saveTokenBoth(j.token);
            return;
          }
        }
      } catch {}
      // 4) だめならログイン画面へ
      location.replace(LOGIN);
    })();
  })();
  </script>

  <!-- ===== Authorization: Bearer 自動付与（401は一度だけ自己修復→リトライ） ===== -->
  <script>
  async function authFetch(input, init = {}) {
    const token = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token');
    if (!token) {
      location.replace(<?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>);
      throw new Error('No JWT token');
    }
    const headers = new Headers(init.headers || {});
    headers.set('Authorization', 'Bearer ' + token);

    let res = await fetch(input, { ...init, headers });

    // 401 は一度だけ自己修復して再試行
    if (res.status === 401) {
      try {
        const rx = await fetch(<?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>, {
          method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json'},
          body:'{}',
          credentials:'same-origin'
        });
        if (rx.ok && (rx.headers.get('content-type')||'').includes('application/json')) {
          const jx = await rx.json();
          if (jx && jx.ok && jx.token) {
            sessionStorage.setItem('jwt', jx.token);
            sessionStorage.setItem('access_token', jx.token); // 互換
            headers.set('Authorization', 'Bearer ' + jx.token);
            res = await fetch(input, { ...init, headers }); // リトライ
          }
        }
      } catch {}
    }

    if (res.status === 401) {
      location.replace(<?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>);
      throw new Error('Unauthorized');
    }
    return res;
  }
  </script>
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
    <div class="MainContent" id="MainContent">
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
                  <?php $planNull = is_null($m['plan']);
                  $commentNull = is_null($m['comment']); ?>
                  <!-- maxlength は目安。実制御はJSで全角=2/半角=1換算150まで -->
                  <input
                    type="text" placeholder="行先" maxlength="150"
                    value="<?= e((string)$m['plan']) ?>"
                    data-orig="<?= $planNull ? '' : e((string)$m['plan']) ?>"
                    data-orig-null="<?= $planNull ? '1' : '0' ?>">
                  <input
                    type="text" placeholder="コメント" maxlength="150"
                    value="<?= e((string)$m['comment']) ?>"
                    data-orig="<?= $commentNull ? '' : e((string)$m['comment']) ?>"
                    data-orig-null="<?= $commentNull ? '1' : '0' ?>">
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($g['members'])): ?><p>所属メンバーがいません。</p><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (empty($groups)): ?><div class="ContentArea">
            <p>この拠点には表示可能な社員がいません。</p>
          </div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <script>
    /* ===== 設定 ===== */
    const API_ENDPOINT = <?= json_encode($apiEndpoint, JSON_UNESCAPED_SLASHES) ?>;
    const API_SOURCE   = <?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>;
    const API_LOGOUT   = <?= json_encode($apiLogout, JSON_UNESCAPED_SLASHES) ?>;
    const LOGIN_URL    = <?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>;

    /* ===== URLの location_id を置き換えて再読み込み ===== */
    function LoadFloor(id) {
      const n = Number(id);
      if (!Number.isInteger(n) || n <= 0) {
        alert('location_id が不正です');
        return;
      }
      const u = new URL(location.href);
      u.searchParams.set('location_id', String(n));
      location.href = u.toString();
    }

    /* ===== 全角=2 / 半角=1 の換算で150以内に丸める（コメント・行先） ===== */
    function isHalfWidthAscii(ch) { return /[ -~]/.test(ch); } // ASCII可視文字 + スペース
    function trimToVisualLimit(s, maxLen = 150) {
      if (!s) return '';
      let len = 0, out = '';
      for (const ch of s) {
        const add = isHalfWidthAscii(ch) ? 1 : 2;
        if (len + add > maxLen) break;
        len += add; out += ch;
      }
      return out;
    }
    // 入力中にその場で制限を適用
    document.addEventListener('input', (ev) => {
      const el = ev.target;
      if (el.matches('.UserDetails input[type="text"]')) {
        const trimmed = trimToVisualLimit(el.value, 150);
        if (trimmed !== el.value) el.value = trimmed;
      }
    });

    /* ===== 差分抽出（baseは送らない） ===== */
    function buildDiffItem(row) {
      const btn = row.querySelector('.Statusbutton');
      const id = Number.parseInt(btn?.dataset?.accountId ?? '', 10);
      if (!Number.isFinite(id) || id <= 0) return null;

      const item = { account_id: id };

      // status（変更があれば送る）: '1'|'2'|'3'
      const curS  = Number.parseInt(btn.dataset.status ?? '1', 10);
      const origS = Number.parseInt(btn.dataset.origStatus ?? '1', 10);
      if (curS !== origS) item.status = String(curS);

      // plan/comment
      const inputs = row.querySelectorAll('.UserDetails input');
      const planEl = inputs[0], commEl = inputs[1];

      if (planEl) {
        const newVal  = trimToVisualLimit((planEl.value ?? '').trim(), 150);
        const origVal = planEl.dataset.orig ?? '';
        const origNull= planEl.dataset.origNull === '1';
        const changed = (origNull && newVal !== '') || (!origNull && newVal !== origVal);
        if (changed) item.plan = (newVal === '') ? '' : newVal;
      }
      if (commEl) {
        const newVal  = trimToVisualLimit((commEl.value ?? '').trim(), 150);
        const origVal = commEl.dataset.orig ?? '';
        const origNull= commEl.dataset.origNull === '1';
        const changed = (origNull && newVal !== '') || (!origNull && newVal !== origVal);
        if (changed) item.comment = (newVal === '') ? '' : newVal;
      }

      return (Object.keys(item).length > 1) ? item : null; // account_id だけなら送らない
    }

    /* ===== fetch ラッパ（Authorization 付与＋401自己修復＋タイムアウト＋JSON厳格化） ===== */
    async function postJSON(url, payload, { timeoutMs = 15000 } = {}) {
      const controller = new AbortController();
      let timedOut = false;
      const to = setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);

      const makeHeaders = (tkn) => {
        const h = new Headers({ 'Content-Type':'application/json' });
        if (tkn) h.set('Authorization', 'Bearer ' + tkn);
        return h;
      };

      try {
        // 1) まずは手持ちトークンで投げる
        let token = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token') || '';
        let res   = await fetch(url, {
          method: 'POST',
          headers: makeHeaders(token),
          body: JSON.stringify(payload),
          signal: controller.signal,
          credentials: 'same-origin'
        });

        // 2) 401 なら一度だけ token 再取得→リトライ
        if (res.status === 401) {
          try {
            const rx = await fetch(API_SOURCE, {
              method:'POST',
              headers:{'Content-Type':'application/json','Accept':'application/json'},
              body:'{}',
              credentials:'same-origin'
            });
            if (rx.ok && (rx.headers.get('content-type')||'').includes('application/json')) {
              const jx = await rx.json();
              if (jx && jx.ok && jx.token) {
                sessionStorage.setItem('jwt', jx.token);
                sessionStorage.setItem('access_token', jx.token); // 互換
                token = jx.token;
                res = await fetch(url, {
                  method:'POST',
                  headers: makeHeaders(token),
                  body: JSON.stringify(payload),
                  signal: controller.signal,
                  credentials:'same-origin'
                });
              }
            }
          } catch {}
        }

        const raw = await res.text(); // PHPの警告混入対策
        let data = null;
        if (raw) {
          try { data = JSON.parse(raw); }
          catch { throw new Error(`Invalid JSON (HTTP ${res.status}): ${raw.slice(0,200)}`); } // ← バッククォートに修正
        }
        if (!res.ok) {
          if (res.status === 401) location.replace(LOGIN_URL);
          const msg = (data && data.message) ? data.message : raw.slice(0, 200);
          throw new Error(`HTTP ${res.status} ${msg}`); // ← バッククォートに修正
        }
        return data;

      } catch (err) {
        if (timedOut) throw new Error('timeout');
        throw err;

      } finally {
        clearTimeout(to);
      }
    }

    /* ===== ログアウト（★追加：Bearer付きで失効→トークン破棄→ログインへ） ===== */
    async function Logout() {
      const t = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token') || '';
      try {
        if (t) {
          await fetch(API_LOGOUT, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + t, 'Content-Type': 'application/json' },
            body: '{}',
            credentials: 'same-origin'
          });
        }
      } catch {}
      try {
        sessionStorage.removeItem('jwt');
        sessionStorage.removeItem('access_token');
      } catch {}
      location.replace(LOGIN_URL);
    }
  </script>

  <script src="./js/script.js"></script>

  <!-- 旧「ログインしてください」アラート判定は削除（authGate が担当） -->
</body>
</html>
