<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    jsonResponse("error", "許可されていないメソッドです");
}

$authUser = authenticate();
$accountId = isset($authUser->account_id) ? (int)$authUser->account_id
             : (isset($authUser->user_id) ? (int)$authUser->user_id : 0); // 後方互換

// TINYINT(0:未設定,1:出勤,2:欠勤,3:有給) → 表示用ラベル
function mapStatusLabel(?int $v): string {
    $map = [0 => "unset", 1 => "present", 2 => "absent", 3 => "leave"];
    return $map[$v ?? 0] ?? "unset";
}

try {
    $pdo = getDbConnection();

    // 1) 本人情報（employee_info を基準に取得）
    $meSql = "
        SELECT
            ei.account_id,
            ei.name,
            ei.dept_id,
            d.name  AS dept_name,
            ei.location_id,
            l.name  AS location_name,
            ei.sort,
            ei.status,
            ei.plan,
            ei.comment,
            ei.updated_at
        FROM employee_info ei
        LEFT JOIN dept_mst d     ON d.id = ei.dept_id
        LEFT JOIN location_mst l ON l.id = ei.location_id
        WHERE ei.account_id = :aid
        LIMIT 1
    ";
    $meStmt = $pdo->prepare($meSql);
    $meStmt->bindValue(':aid', $accountId, PDO::PARAM_INT);
    $meStmt->execute();
    $meRow = $meStmt->fetch(PDO::FETCH_ASSOC);

    $self = null;
    if ($meRow) {
        $self = [
            "account_id"   => (int)$meRow["account_id"],
            "name"         => $meRow["name"],
            "dept"         => ["id" => $meRow["dept_id"], "name" => $meRow["dept_name"]],
            "location"     => ["id" => $meRow["location_id"], "name" => $meRow["location_name"]],
            "sort"         => (int)$meRow["sort"],
            "status"       => mapStatusLabel(isset($meRow["status"]) ? (int)$meRow["status"] : null),
            "plan"         => $meRow["plan"],
            "comment"      => $meRow["comment"],
            "updated_at"   => $meRow["updated_at"]
        ];
    }

    // 2) 全社員一覧（employee_info + マスタ結合、ページング）
    $limit  = isset($_GET['limit'])  ? max(1, min((int)$_GET['limit'], 200)) : 200;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    // 任意フィルタ（必要に応じて利用可）
    $deptFilter     = isset($_GET['dept_id'])     ? (int)$_GET['dept_id']     : null;
    $locationFilter = isset($_GET['location_id']) ? (int)$_GET['location_id'] : null;
    $nameQuery      = isset($_GET['q'])           ? trim((string)$_GET['q'])  : null;

    $where = [];
    $params = [];
    if ($deptFilter !== null)     { $where[] = "ei.dept_id = :dept_id";           $params[':dept_id'] = [$deptFilter, PDO::PARAM_INT]; }
    if ($locationFilter !== null) { $where[] = "ei.location_id = :location_id";   $params[':location_id'] = [$locationFilter, PDO::PARAM_INT]; }
    if ($nameQuery !== null && $nameQuery !== "") {
        $where[] = "ei.name LIKE :q";
        $params[':q'] = ["%{$nameQuery}%", PDO::PARAM_STR];
    }
    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    $listSql = "
        SELECT
            ei.account_id,
            ei.name,
            ei.dept_id,
            d.name  AS dept_name,
            ei.location_id,
            l.name  AS location_name,
            ei.sort,
            ei.status,
            ei.plan,
            ei.comment,
            ei.updated_at
        FROM employee_info ei
        LEFT JOIN dept_mst d     ON d.id = ei.dept_id
        LEFT JOIN location_mst l ON l.id = ei.location_id
        {$whereSql}
        ORDER BY ei.sort ASC, ei.account_id ASC
        LIMIT :limit OFFSET :offset
    ";
    $listStmt = $pdo->prepare($listSql);
    foreach ($params as $k => [$v, $type]) {
        $listStmt->bindValue($k, $v, $type);
    }
    $listStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $members = array_map(function($r) {
        return [
            "account_id" => (int)$r["account_id"],
            "name"       => $r["name"],
            "dept"       => ["id" => $r["dept_id"], "name" => $r["dept_name"]],
            "location"   => ["id" => $r["location_id"], "name" => $r["location_name"]],
            "sort"       => (int)$r["sort"],
            "status"     => mapStatusLabel(isset($r["status"]) ? (int)$r["status"] : null),
            "plan"       => $r["plan"],
            "comment"    => $r["comment"],
            "updated_at" => $r["updated_at"]
        ];
    }, $rows);

    $data = [
        "self"   => $self,
        "members"=> $members,
        "paging" => ["limit" => $limit, "offset" => $offset]
    ];

    jsonResponse("success", "TOP画面の取得に成功しました", $data);
} catch (Throwable $e) {
    error_log("[DASHBOARD] ".$e->getMessage());
    http_response_code(500);
    jsonResponse("error", "Server Error");
}
