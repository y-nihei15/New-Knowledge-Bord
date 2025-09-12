<?php
declare(strict_types=1);
require_once __DIR__ . '/../common_api/config/db.php';

header('X-Content-Type-Options: nosniff');

$pdo = getDbConnection();

/** 認証（既存と同じ方式をここで必ず実施）
 * 例）verifyBearerOrExit(); // ←あなたの共通関数名に合わせて
 */

$locationId = isset($_GET['location_id']) ? max(1, (int)$_GET['location_id']) : null;
if ($locationId === null) {
  http_response_code(400);
  echo "location_id required";
  exit;
}

// CSV出力列（dept_id も除外して dept_name のみ）
$sql = "
  SELECT
    li.user_id,
    ei.name AS emp_name,
    COALESCE(d.name,'') AS dept_name,
    ei.sort
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
$st = $pdo->prepare($sql);
$st->bindValue(':lid', $locationId, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// 出力カラム定義（dept_id は削除）
$columns = ['user_id','emp_name','dept_name','sort'];

$filename = sprintf('attendance_%d_%s.csv', $locationId, date('Ymd_His'));
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$fp = fopen('php://output', 'w');
// Excel対策：UTF-8 BOM
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, $columns);

foreach ($rows as $r) {
  $line = [];
  foreach ($columns as $c) $line[] = $r[$c] ?? '';
  fputcsv($fp, $line);
}
fclose($fp);
