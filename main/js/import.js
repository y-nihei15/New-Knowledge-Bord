(() => {
  const btn = document.getElementById('btn-output');
  const input = document.getElementById('csv-file');
  const form  = document.getElementById('csv-import-form');
  const out  = document.getElementById('import-result');
  if (!btn || !form || !out) return;

  function show(msg) {
    out.textContent = msg;
  }

  btn.addEventListener('click', async () => {
    try {
      // 固定CSVだけで運用する場合は FormData を空にしてPOSTすればOK
      const fd = new FormData(form);
      show('取り込み中…');

      const res = await fetch('/../top_api/import_csv.php', {
        method: 'POST',
        body: fd
      });
      const json = await res.json();

      if (!res.ok || !json.ok) {
        show(`エラー: ${json.error || res.statusText}`);
        return;
      }
      const { inserted, skipped } = json;
      show(`取り込み完了：登録 ${inserted} 件 / スキップ ${skipped} 件`);
      // 必要ならここで画面のリストを再描画する処理を呼ぶ
    } catch (e) {
      show('通信エラーが発生しました');
      console.error(e);
    }
  });
})();
