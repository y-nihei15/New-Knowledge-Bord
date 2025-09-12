<?php
/***********************
 * 出席管理（統合版）
 ***********************/
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../common_api/config/db.php';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$pdo = getDbConnection();

/* パス算出 */
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$apiEndpoint = preg_replace('#/+#', '/', $scriptDir . '/../top_api/update.php');
$apiEndpoint = preg_replace('#/+#', '/', $apiEndpoint);
$apiSource   = preg_replace('#/+#', '/', $scriptDir . '/../top_api/source.php');
$apiLogout   = preg_replace('#/+#', '/', $scriptDir . '/../top_api/logout.php');
$loginScreen = preg_replace('#/+#', '/', $scriptDir . '/loginScreen.php');

/* 表示対象フロア */
$locationId = isset($_GET['location_id']) ? max(1, (int)$_GET['location_id']) : null;
$minId = $pdo->query("SELECT MIN(id) FROM location_mst")->fetchColumn();
if ($locationId === null) $locationId = ($minId === null) ? null : (int)$minId;

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
    SELECT d.id AS dept_id, d.name AS dept_name,
           ei.account_id, ei.name AS emp_name, ei.sort,
           ei.status, ei.plan, ei.comment
    FROM employee_info ei
    LEFT JOIN dept_mst d ON d.id = ei.dept_id
    WHERE ei.location_id = :lid
    ORDER BY (d.id IS NULL) ASC, d.id ASC, ei.sort ASC, ei.account_id ASC";
  $list = $pdo->prepare($listSql);
  $list->bindValue(':lid', $locationId, PDO::PARAM_INT);
  $list->execute();
  $rows = $list->fetchAll(PDO::FETCH_ASSOC);
}

/* グルーピング */
$groups = [];
foreach ($rows as $r) {
  $key = isset($r['dept_id']) ? (int)$r['dept_id'] : 0;
  if (!isset($groups[$key])) $groups[$key] = ['dept_name' => $r['dept_name'] ?? '未設定', 'members' => []];
  $groups[$key]['members'][] = $r;
}

/* ステータス */
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
<!-- ★ 最初に置く（<link rel="stylesheet"> より前） -->
<script>
try {
  if (sessionStorage.getItem('editlink_sticky') === '1') {
    document.documentElement.classList.add('editlink-sticky'); // <html class="editlink-sticky">
  }
} catch (e) {}
</script>
<style>
/* sticky が立っているときは最初の描画から常に表示（inline style を上書き） */
.editlink-sticky #EditLink { display: inline-block !important; }
</style>

  <!-- 認証ガード -->
  <script>
  (function authGate(){
    const SOURCE = <?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>;
    const LOGIN  = <?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>;

    function saveTokenBoth(t, origin='unknown'){
      try{
        sessionStorage.setItem('jwt', t);
        sessionStorage.setItem('access_token', t);
        sessionStorage.setItem('token_origin', origin);
        window.dispatchEvent(new Event('token-updated'));
      }catch{}
    }

    // #token
    try{
      const m = location.hash.match(/(?:^#|&)?token=([^&]+)/);
      if(m && m[1]){
        const t = decodeURIComponent(m[1]);
        if(/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) saveTokenBoth(t,'url-hash');
        history.replaceState(null,'',location.pathname+location.search);
        return;
      }
    }catch{}

    // ?token
    try{
      const u = new URL(location.href);
      const t = u.searchParams.get('token');
      if(t){
        if(/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) saveTokenBoth(t,'url-query');
        u.searchParams.delete('token');
        history.replaceState(null,'',u.toString());
        return;
      }
    }catch{}

    // 形式チェック
    try{
      const t = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token');
      if(t && /^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) return;
      sessionStorage.removeItem('jwt'); sessionStorage.removeItem('access_token'); sessionStorage.removeItem('token_origin');
    }catch{}

    // source で自己修復
    (async()=>{
      try{
        const res = await fetch(SOURCE,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:'{}',credentials:'same-origin',redirect:'follow'});
        if((res.headers.get('content-type')||'').includes('application/json')){
          const j = await res.json();
          if(res.ok && j && j.ok===true && j.token){ saveTokenBoth(j.token,'source'); return; }
        }
      }catch{}
      location.replace(LOGIN);
    })();
  })();
  </script>

  <!-- 権限表示制御（sticky：一度出たらログアウトまで固定） -->
  <script>
  function parseJwtClaims(token){
    const parts=(token||'').split('.'); if(parts.length!==3) throw new Error('invalid_jwt');
    const b64=parts[1].replace(/-/g,'+').replace(/_/g,'/'); const json=decodeURIComponent(atob(b64).split('').map(c=>'%'+('00'+c.charCodeAt(0).toString(16)).slice(-2)).join(''));
    return JSON.parse(json);
  }
  function isTokenExpired(c){ const now=Math.floor(Date.now()/1000); return !!c.exp && c.exp<=now; }
  function isAdminFromClaims(c){ const ut=Number(c?.user_type ?? -1); if(ut===1) return true; const roles=Array.isArray(c?.roles)?c.roles:[]; return roles.includes('admin'); }

  function initAdminUI(){
    try{
      const el = document.getElementById('EditLink');

      // ★ sticky が立っていれば即表示＆return（以降のイベントでも消さない）
      if (sessionStorage.getItem('editlink_sticky') === '1') {
        if (el) el.style.display = '';
        return;
      }

      const t = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token');
      if (!t) return;
      const claims = parseJwtClaims(t);
      if (isTokenExpired(claims)) return;

      // 管理者なら sticky セット→表示。以降は常に表示維持
      if (isAdminFromClaims(claims)) {
        sessionStorage.setItem('editlink_sticky', '1');
        if (el) el.style.display = '';
      }

      // 任意：公開情報
      const origin = sessionStorage.getItem('token_origin') || 'unknown';
      window.AUTH = { account_id:Number(claims?.account_id ?? NaN), user_type:Number(claims?.user_type ?? NaN), origin };
    }catch{}
  }
  document.addEventListener('DOMContentLoaded', initAdminUI);
  window.addEventListener('token-updated', initAdminUI); // 来ても sticky 優先で非表示に戻らない
  </script>

  <!-- Authorization fetch（401自己修復） -->
  <script>
  async function authFetch(input, init = {}) {
    const token = sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token');
    if (!token) { location.replace(<?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>); throw new Error('No JWT'); }
    const headers = new Headers(init.headers || {}); headers.set('Authorization','Bearer '+token);
    let res = await fetch(input,{...init,headers});
    if(res.status===401){
      try{
        const rx=await fetch(<?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:'{}',credentials:'same-origin'});
        if(rx.ok && (rx.headers.get('content-type')||'').includes('application/json')){
          const jx=await rx.json();
          if(jx && jx.ok && jx.token){
            sessionStorage.setItem('jwt',jx.token); sessionStorage.setItem('access_token',jx.token); sessionStorage.setItem('token_origin','source');
            window.dispatchEvent(new Event('token-updated'));
            const h2=new Headers(init.headers||{}); h2.set('Authorization','Bearer '+jx.token);
            res = await fetch(input,{...init,headers:h2});
          }
        }
      }catch{}
    }
    if(res.status===401){ location.replace(<?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>); throw new Error('Unauthorized'); }
    return res;
  }
  </script>
</head>
<body>
  <div class="Container">
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
        <a href="./Naisen_list.php">内線一覧</a>
        <!-- 初期非表示。sticky or 管理者で表示 -->
        <a id="EditLink" href="./edit.php" style="display:none">編集</a>
      </div>
    </div>

    <div class="MainContent" id="MainContent">
      <div class="Logout">
        <button onclick="Reflect()">反映</button>
        <button onclick="Logout()">ログアウト</button>
      </div>

      <?php if ($locationId === null): ?>
        <div class="ContentArea"><h1>拠点データがありません</h1><p>location_mst にレコードを追加してください。</p></div>
      <?php elseif (!$location): ?>
        <div class="ContentArea"><h1>拠点が見つかりません</h1><p>location_id=<?= e((string)$locationId) ?> は存在しません。</p></div>
      <?php else: ?>
        <?php foreach ($groups as $deptId => $g): ?>
          <div class="ContentArea">
            <h1><?= e($g['dept_name']) ?></h1>
            <?php foreach ($g['members'] as $m): $st = statusClass((int)$m['status']); ?>
              <div class="UserRow">
                <button class="Statusbutton <?= e($st['class']) ?>"
                        data-status="<?= e((string)$st['data']) ?>"
                        data-orig-status="<?= e((string)$st['data']) ?>"
                        data-account-id="<?= (int)$m['account_id'] ?>">
                  <?= e($m['emp_name']) ?>
                </button>
                <div class="UserDetails">
                  <?php $planNull=is_null($m['plan']); $commentNull=is_null($m['comment']); ?>
                  <input type="text" placeholder="行先" maxlength="150"
                         value="<?= e((string)$m['plan']) ?>"
                         data-orig="<?= $planNull ? '' : e((string)$m['plan']) ?>"
                         data-orig-null="<?= $planNull ? '1' : '0' ?>">
                  <input type="text" placeholder="コメント" maxlength="150"
                         value="<?= e((string)$m['comment']) ?>"
                         data-orig="<?= $commentNull ? '' : e((string)$m['comment']) ?>"
                         data-orig-null="<?= $commentNull ? '1' : '0' ?>">
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
    const API_ENDPOINT = <?= json_encode($apiEndpoint, JSON_UNESCAPED_SLASHES) ?>;
    const API_SOURCE   = <?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>;
    const API_LOGOUT   = <?= json_encode($apiLogout, JSON_UNESCAPED_SLASHES) ?>;
    const LOGIN_URL    = <?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>;

    function LoadFloor(id){
      const n=Number(id);
      if(!Number.isInteger(n)||n<=0){ alert('location_id が不正です'); return; }
      const u=new URL(location.href); u.searchParams.set('location_id', String(n)); location.href=u.toString();
    }

    function isHalfWidthAscii(ch){ return /[ -~]/.test(ch); }
    function trimToVisualLimit(s,maxLen=150){
      if(!s) return ''; let len=0,out='';
      for(const ch of s){ const add=isHalfWidthAscii(ch)?1:2; if(len+add>maxLen) break; len+=add; out+=ch; }
      return out;
    }
    document.addEventListener('input', (ev)=> {
      const el=ev.target;
      if (el.matches('.UserDetails input[type="text"]')) {
        const trimmed=trimToVisualLimit(el.value,150);
        if(trimmed!==el.value) el.value=trimmed;
      }
    });

    function buildDiffItem(row){
      const btn=row.querySelector('.Statusbutton');
      const id=Number.parseInt(btn?.dataset?.accountId ?? '',10);
      if(!Number.isFinite(id)||id<=0) return null;
      const item={account_id:id};

      const curS=Number.parseInt(btn.dataset.status ?? '1',10);
      const origS=Number.parseInt(btn.dataset.origStatus ?? '1',10);
      if(curS!==origS) item.status=String(curS);

      const inputs=row.querySelectorAll('.UserDetails input');
      const planEl=inputs[0], commEl=inputs[1];

      if(planEl){
        const newVal=trimToVisualLimit((planEl.value ?? '').trim(),150);
        const origVal=planEl.dataset.orig ?? '';
        const origNull=planEl.dataset.origNull==='1';
        const changed=(origNull && newVal!=='') || (!origNull && newVal!==origVal);
        if(changed) item.plan=(newVal==='')?'':newVal;
      }
      if(commEl){
        const newVal=trimToVisualLimit((commEl.value ?? '').trim(),150);
        const origVal=commEl.dataset.orig ?? '';
        const origNull=commEl.dataset.origNull==='1';
        const changed=(origNull && newVal!=='') || (!origNull && newVal!==origVal);
        if(changed) item.comment=(newVal==='')?'':newVal;
      }
      return (Object.keys(item).length>1)?item:null;
    }

    async function postJSON(url,payload,{timeoutMs=15000}={}){
      const controller=new AbortController(); let timedOut=false;
      const to=setTimeout(()=>{ timedOut=true; controller.abort(); }, timeoutMs);
      const makeHeaders=(tkn)=>{ const h=new Headers({'Content-Type':'application/json'}); if(tkn) h.set('Authorization','Bearer '+tkn); return h; };

      try{
        let token=sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token') || '';
        let res=await fetch(url,{method:'POST',headers:makeHeaders(token),body:JSON.stringify(payload),signal:controller.signal,credentials:'same-origin'});

        if(res.status===401){
          try{
            const rx=await fetch(API_SOURCE,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:'{}',credentials:'same-origin'});
            if(rx.ok && (rx.headers.get('content-type')||'').includes('application/json')){
              const jx=await rx.json();
              if(jx && jx.ok && jx.token){
                sessionStorage.setItem('jwt',jx.token); sessionStorage.setItem('access_token',jx.token); sessionStorage.setItem('token_origin','source');
                window.dispatchEvent(new Event('token-updated'));
                token=jx.token;
                res=await fetch(url,{method:'POST',headers:makeHeaders(token),body:JSON.stringify(payload),signal:controller.signal,credentials:'same-origin'});
              }
            }
          }catch{}
        }

        const raw=await res.text(); let data=null;
        if(raw){ try{ data=JSON.parse(raw); } catch{ throw new Error(`Invalid JSON (HTTP ${res.status}): ${raw.slice(0,200)}`); } }
        if(!res.ok){
          if(res.status===401) location.replace(LOGIN_URL);
          const msg=(data && data.message)?data.message:raw.slice(0,200);
          throw new Error(`HTTP ${res.status} ${msg}`);
        }
        return data;
      } catch(err){
        if(timedOut) throw new Error('timeout');
        throw err;
      } finally{
        clearTimeout(to);
      }
    }

    async function Logout(){
      const t=sessionStorage.getItem('jwt') || sessionStorage.getItem('access_token') || '';
      try{
        if(t){ await fetch(API_LOGOUT,{method:'POST',headers:{'Authorization':'Bearer '+t,'Content-Type':'application/json'},body:'{}',credentials:'same-origin'}); }
      }catch{}
      try{
        sessionStorage.removeItem('jwt'); sessionStorage.removeItem('access_token'); sessionStorage.removeItem('token_origin');
        sessionStorage.removeItem('editlink_sticky'); // ★ sticky リセット
      }catch{}
      location.replace(LOGIN_URL);
    }
  </script>

  <script src="./js/script.js"></script>
</body>
</html>
