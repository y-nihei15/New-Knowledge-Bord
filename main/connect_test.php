<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$path = __DIR__ . '/../top_api/config/db.php';
echo "db.php: $path<br>";
echo "exists? " . (file_exists($path) ? 'YES' : 'NO') . "<br>";

require_once $path;

try {
    $pdo = getDbConnection();
    echo "PDO OK<br>";
    // テーブル一発叩いてみる（任意の存在テーブルでOK）
    $stmt = $pdo->query("SELECT 1 FROM location_mst LIMIT 1");
    echo "location_mst OK<br>";
} catch (Throwable $e) {
    echo "FAILED<br>";
    echo "Code: " . ($e->getCode() ?? 'n/a') . "<br>";
    echo "Msg: "  . $e->getMessage() . "<br>";
}
