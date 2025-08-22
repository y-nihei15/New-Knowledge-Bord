document.addEventListener('DOMContentLoaded', () => {
  const buttons = document.querySelectorAll('.Statusbutton');

  buttons.forEach(button => {
    let status = parseInt(button.getAttribute('data-status'));
    if (isNaN(status)) {
      status = 0;
      button.setAttribute('data-status', status);
    }

    applyStatusStyle(button, status);

    button.addEventListener('click', () => {
      let current = parseInt(button.getAttribute('data-status'));
      let next = (current + 1) % 3;
      button.setAttribute('data-status', next);
      applyStatusStyle(button, next);
    });
  });
});

function applyStatusStyle(button, status) {
  button.classList.remove('red', 'blue', 'green');

  switch (status) {
    case 0:
      button.classList.add('red');
      break;
    case 1:
      button.classList.add('blue');
      break;
    case 2:
      button.classList.add('green');
      break;
  }
}


function LoadFloor(locationId){
  const url = new URL('main.php', location.href); // ← base を現在ページに
  url.search = '';                                // 既存クエリ消す
  url.searchParams.set('location_id', String(locationId));
  location.assign(url.toString());
}

async function Logout(){
  try {
    const res = await fetch('../common_api/auth/logout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });
    const data = await res.json();

    if (data.status === 'success') {
      alert('ログアウトしました');
      window.location.href = '../main/loginScreen.php'; // ログイン画面に遷移
    } else {
      alert('ログアウトに失敗しました: ' + (data.message ?? 'unknown error'));
    }
  } catch (err) {
    console.error(err);
    alert('通信エラーが発生しました');
  }
}


// // /js/app.js などに配置（関数名=PascalCase禁止なら camelCase に）
// function LoadFloor(floorId, locationId){
//   const p = new URLSearchParams();
//   p.set('floor_id', String(floorId));
//   if (locationId != null) p.set('location_id', String(locationId));
//   location.href = './Naisen_list.php?' + p.toString();
// }
// function LoadLocation(locationId){
//   location.href = './Naisen_list.php?location_id=' + encodeURIComponent(locationId);
// }
