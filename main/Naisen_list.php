<?php
declare(strict_types=1);

require_once __DIR__ . '/../common_api/config/db.php';

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$pdo = getDbConnection();

/* パス算出（main と同様） */
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$apiSource   = preg_replace('#/+#', '/', $scriptDir . '/../top_api/source.php');
$apiLogout   = preg_replace('#/+#', '/', $scriptDir . '/../top_api/logout.php');
$loginScreen = preg_replace('#/+#', '/', $scriptDir . '/loginScreen.php');
?>
<!DOCTYPE html>
<html lang="ja" class="NaisenBody">
<head>
  <meta charset="UTF-8">
  <title>内線一覧</title>
  <link rel="stylesheet" href="css/style.css">

  <!-- 認証ガード（main と同等） -->
  <script>
  (function authGate(){
    const SOURCE = <?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>;
    const LOGIN  = <?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>;

    function saveTokenBoth(t, origin='unknown'){
      try{ sessionStorage.setItem('jwt',t); sessionStorage.setItem('access_token',t); sessionStorage.setItem('token_origin',origin); window.dispatchEvent(new Event('token-updated')); }catch{}
    }

    try{ const m=location.hash.match(/(?:^#|&)?token=([^&]+)/); if(m&&m[1]){ const t=decodeURIComponent(m[1]); if(/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) saveTokenBoth(t,'url-hash'); history.replaceState(null,'',location.pathname+location.search); return; } }catch{}
    try{ const u=new URL(location.href); const t=u.searchParams.get('token'); if(t){ if(/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) saveTokenBoth(t,'url-query'); u.searchParams.delete('token'); history.replaceState(null,'',u.toString()); return; } }catch{}
    try{ const t=sessionStorage.getItem('jwt')||sessionStorage.getItem('access_token'); if(t&&/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(t)) return;
         sessionStorage.removeItem('jwt'); sessionStorage.removeItem('access_token'); sessionStorage.removeItem('token_origin'); }catch{}

    (async()=>{ try{ const res=await fetch(SOURCE,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:'{}',credentials:'same-origin',redirect:'follow'});
      if((res.headers.get('content-type')||'').includes('application/json')){ const j=await res.json(); if(res.ok && j && j.ok===true && j.token){ saveTokenBoth(j.token,'source'); return; } } }catch{} location.replace(LOGIN); })();
  })();
  </script>

  <!-- sticky 表示（JWT解析不要） -->
  <script>
  function showEditIfSticky(){
    if (sessionStorage.getItem('editlink_sticky') === '1') {
      const el=document.getElementById('EditLink'); if (el) el.style.display='';
    }
  }
  document.addEventListener('DOMContentLoaded', showEditIfSticky);
  window.addEventListener('token-updated', showEditIfSticky);
  </script>
</head>

<body class="NaisenBody">
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
        <!-- sticky で表示 -->
        <a id="EditLink" href="./edit.php" style="display:none">編集</a>
      </div>
    </div>

    <div class="MainContent">
      <div class="Logout">
        <button onclick="Logout()">ログアウト</button>
      </div>

      <div class="ContentArea">
        <div class="PdfViewer">
          <iframe src="./naisen.pdf" id="PdfFrame" title="内線一覧PDF"></iframe>
        </div>
      </div>
    </div>
  </div>

  <script>
    const API_SOURCE = <?= json_encode($apiSource, JSON_UNESCAPED_SLASHES) ?>;
    const API_LOGOUT = <?= json_encode($apiLogout, JSON_UNESCAPED_SLASHES) ?>;
    const LOGIN_URL  = <?= json_encode($loginScreen, JSON_UNESCAPED_SLASHES) ?>;

    function LoadFloor(id){
      const n=Number(id);
      if(!Number.isInteger(n)||n<=0){ alert('location_id が不正です'); return; }
      const u=new URL('main.php', location.href);
      u.searchParams.set('location_id', String(n));
      location.href=u.toString();
    }

    async function Logout(){
      const t=sessionStorage.getItem('jwt')||sessionStorage.getItem('access_token')||'';
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
