// ===== 共通のPOST(JSON)関数 =====
(() => {
  const token = sessionStorage.getItem("access_token");
  console.log("token check:", token);
  if (!token) {
    alert("ログインしてください");
    window.location.href = "../main/loginScreen.php";
  }
})();

// async function postJSON(url, body) {
//   const token = sessionStorage.getItem("access_token");
//   const res = await fetch(url, {
//     method: "POST",
//     headers: {
//       "Content-Type": "application/json",
//       "Authorization": `Bearer ${token}`
//     },
//     body: JSON.stringify(body)
//   });

//   const text = await res.text();
//   alert("サーバーレスポンス:\n" + text.substring(0, 500)); // 先頭500文字だけアラート表示
//   console.log("サーバーレスポンス全文:", text);

//   if (!res.ok) {
//     throw new Error(`HTTP ${res.status}`);
//   }

//   try {
//     return JSON.parse(text);
//   } catch (e) {
//     throw new Error("サーバーがJSONを返しませんでした");
//   }
// }


async function postJSON(url, body) {
  const token = sessionStorage.getItem('access_token');
  const res = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Authorization": `Bearer ${token}`
    },
    body: JSON.stringify(body)
  });

  if (!res.ok) {
    throw new Error(`HTTP ${res.status}`);
  }

  return await res.json();
}

document.addEventListener('DOMContentLoaded', () => {
  const buttons = document.querySelectorAll('.Statusbutton');

  buttons.forEach(button => {
    let status = parseInt(button.getAttribute('data-status'));
    if (isNaN(status)) {
      status = 0;
      button.setAttribute('data-status', status);
    }
    });
  });

// 出勤状態のスタイル適用
function applyStatusClass(btn, n) {
  btn.classList.remove('blue', 'red', 'green');
  if (n === 1) btn.classList.add('blue');
  else if (n === 2) btn.classList.add('red');
  else btn.classList.add('green');
}

// クリックでトグル（1→2→3→1）
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest?.('.Statusbutton');
  if (!btn) return;

  let v = Number.parseInt(btn.dataset.status ?? '1', 10);
  if (!Number.isFinite(v) || v < 1 || v > 3) v = 1;
  v = (v % 3) + 1;
  btn.dataset.status = String(v);
  applyStatusClass(btn, v);
});


function LoadFloor(locationId){
  const url = new URL('edit.php', location.href); // ← base を現在ページに
  url.search = '';                                // 既存クエリ消す
  url.searchParams.set('location_id', String(locationId));
  location.assign(url.toString());
}

// ====== CSVインポート結果のポップアップ共通関数 ======
// edit_script.js
// v6  ←（任意）バージョン表記だけ上げました
window.showImportResult = function (j) {
  try {
    const toInt = v => (Number.isFinite(Number(v)) ? Number(v) : 0);

    const updated       = toInt(j?.updated);
    const insertedUsers = toInt(j?.inserted);
    const skipped       = toInt(j?.skipped);
    const deleted       = toInt(j?.deleted); // ★ 追加: 削除件数

    const depts     = Array.isArray(j?.new_departments) ? j.new_departments : [];
    const deptAdded = depts.length;

    // 追加 = ユーザー追加 + 部署追加（従来仕様のまま）
    const addedTotal = insertedUsers + deptAdded;

    const lines = [];
    // ★ ここを修正：削除を含める
    lines.push(`インポート完了: 更新 ${updated} / 追加 ${addedTotal} / 削除 ${deleted} / スキップ ${skipped}`);

    if (deptAdded > 0) {
      const names = depts.map(d => d?.name).filter(Boolean).join(', ');
      lines.push('新規部署: ' + names);
    }

    alert(lines.join('\n'));
  } catch (e) {
    console.error('showImportResult error:', e, j);
    alert('インポート結果の表示に失敗しました');
  }
};






/* ===== 一括反映：差分のみ送信 ===== */
    async function Reflect() {
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
        const data = await postJSON(API_ENDPOINT, {
          items
        });
        const results = data?.data?.results ?? [];
        if (results.length) {
          const changed = results.filter(r => r.ok && r.changed).length;
          const failed = results.filter(r => !r.ok).length;
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
                inputs[0].dataset.origNull = (v === '' ? '1' : '0');
              }
              if (inputs[1]) {
                const v = inputs[1].value.trim();
                inputs[1].dataset.orig = v;
                inputs[1].dataset.origNull = (v === '' ? '1' : '0');
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

async function Logout(){
  try {
    const token = sessionStorage.getItem('access_token');  // ← ログイン時に保存したトークン
    const res = await fetch('../common_api/auth/logout.php', {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`   // ← これを追加！
      }
    });
    const data = await res.json();

    if (data.status === 'success') {
      alert('ログアウトしました');
      window.location.href = '../main/loginScreen.php'; // ログイン画面へ
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
