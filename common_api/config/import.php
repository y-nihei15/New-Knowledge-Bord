<?php
// サーバー上の“指定CSV”のパス（固定読み込み用）
define('DEFAULT_CSV_PATH', __DIR__ . '/../storage/naisen.csv'); // 置き場は自由

// 取り込み先テーブル名（必要に応じて変更）
define('importTable', 'employee_info');

// CSVの列マッピング（左から順にCSVを想定）
define('CSV_COLUMNS', [
    'name',  // 名前
    'dept_Id',   // 部署ID
    'location_Id',   // 拠点ID
    'sort',  // 並び順
    'plan',  // 行先
    'comment',      // コメント
]);
