<?php
declare(strict_types=1);

require_once __DIR__ . '/../../common_api/config/db.php';

// 共通JSONレスポンス
function jsonResponse(string $status, ?string $message = null, $data = null): void {
    header('Content-Type: application/json; charset=UTF-8');
    $out = ['status' => $status];
    if ($message !== null) $out['message'] = $message;
    if ($data !== null)    $out['data']    = $data;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * POST /attendance/update
 * - 単体: { account_id, status?, plan?, comment?, sort?, name? }
 * - まとめて: { items: [ { ... }, ... ] }
 * - status: "present"|"absent"|"leave"（内部保存は 1/2/3）
 * - plan/comment: 空文字 "" を受けたら NULL に更新（削除）
 * - 値が実際に変わったときだけ updated_at を更新（WHERE で比較）
 * - レスポンス:
 *   - 単体:   { "status":"success", "message":"updated", "data": { ... , "changed": true|false } }
 *   - バッチ: { "status":"success", "message":"updated", "data": { "results": [ { index, ok, changed, data? | error? }, ... ] } }
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    jsonResponse('error', '許可されていないメソッドです');
}

if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    http_response_code(415);
    jsonResponse('error', 'unsupported media type');
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    jsonResponse('error', 'invalid json');
}

$isBatch = isset($input['items']) && is_array($input['items']);
$items   = $isBatch ? $input['items'] : [$input];

// 数値文字 '1' '2' '3' も受け入れ（保険）
$statusMap    = ['present'=>1, 'absent'=>2, 'leave'=>3, '1'=>1, '2'=>2, '3'=>3];
$statusRevMap = [0=>'unknown', 1=>'present', 2=>'absent', 3=>'leave'];

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $results = [];

    foreach ($items as $idx => $row) {
        if (!is_array($row)) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'invalid item'];
            continue;
        }

        // 必須: account_id
        $accountId = filter_var($row['account_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$accountId) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'account_id must be positive integer'];
            continue;
        }

        // 更新フィールド収集（plan/comment は "" → NULL）
        $set = []; $params = [];
        // 変更有無判定のための比較句（NULLセーフ等価 <=> と IS NULL を使う）
        $cmp = []; $cmpParams = [];

        if (array_key_exists('status', $row)) {
            $s = strtolower((string)$row['status']);
            if (!isset($statusMap[$s])) {
                $results[] = ['index'=>$idx,'ok'=>false,'error'=>'invalid status'];
                continue;
            }
            $val = $statusMap[$s];
            $set[]      = 'status = ?';     $params[]    = $val;
            $cmp[]      = 'status <=> ?';   $cmpParams[] = $val;
        }

        if (array_key_exists('plan', $row)) {
            $v = $row['plan'];
            if (!is_string($v)) {
                $results[] = ['index'=>$idx,'ok'=>false,'error'=>'plan must be string'];
                continue;
            }
            if ($v === '') { // 削除（NULL化）
                $set[] = 'plan = NULL';
                $cmp[] = 'plan IS NULL';
            } else {
                if (strlen($v) > 150) {
                    $results[] = ['index'=>$idx,'ok'=>false,'error'=>'plan must be <= 150 bytes'];
                    continue;
                }
                $set[]      = 'plan = ?';     $params[]    = $v;
                $cmp[]      = 'plan <=> ?';   $cmpParams[] = $v;
            }
        }

        if (array_key_exists('comment', $row)) {
            $v = $row['comment'];
            if (!is_string($v)) {
                $results[] = ['index'=>$idx,'ok'=>false,'error'=>'comment must be string'];
                continue;
            }
            if ($v === '') { // 削除（NULL化）
                $set[] = 'comment = NULL';
                $cmp[] = 'comment IS NULL';
            } else {
                if (strlen($v) > 150) {
                    $results[] = ['index'=>$idx,'ok'=>false,'error'=>'comment must be <= 150 bytes'];
                    continue;
                }
                $set[]      = 'comment = ?';   $params[]    = $v;
                $cmp[]      = 'comment <=> ?'; $cmpParams[] = $v;
            }
        }

        if (array_key_exists('sort', $row)) {
            $v = filter_var($row['sort'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($v === false) {
                $results[] = ['index'=>$idx,'ok'=>false,'error'=>'sort must be integer (>=0)'];
                continue;
            }
            $set[]      = 'sort = ?';    $params[]    = $v; // 重複可（ORDER BYで安定化）
            $cmp[]      = 'sort <=> ?';  $cmpParams[] = $v;
        }

        if (array_key_exists('name', $row)) {
            $name = (string)$row['name'];
            if (strlen($name) > 100) {
                $results[] = ['index'=>$idx,'ok'=>false,'error'=>'name must be <= 100 bytes'];
                continue;
            }
            $set[]      = 'name = ?';    $params[]    = $name;
            $cmp[]      = 'name <=> ?';  $cmpParams[] = $name;
        }

        if (!$set) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'no updatable fields'];
            continue;
        }

        // 値が実際に変わった場合だけ updated_at を進める
        $set[] = 'updated_at = UTC_TIMESTAMP()';

        // UPDATE 実行（すべて同じなら更新しない）
        $sql =
            'UPDATE employee_info SET '.implode(', ', $set).
            ' WHERE account_id = ?'.
            ($cmp ? ' AND NOT ('.implode(' AND ', $cmp).')' : '');

        $paramsAll = array_merge($params, [$accountId], $cmpParams);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsAll);

        $changed = ($stmt->rowCount() > 0);

        // 確定値取得
        $stmt = $pdo->prepare('SELECT account_id, sort, name, status, plan, comment, updated_at FROM employee_info WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'not found'];
            continue;
        }

        $results[] = [
            'index'   => $idx,
            'ok'      => true,
            'changed' => $changed,
            'data'    => [
                'account_id' => (int)$d['account_id'],
                'sort'       => isset($d['sort']) ? (int)$d['sort'] : null,
                'name'       => $d['name'],
                'status'     => $statusRevMap[(int)($d['status'] ?? 0)] ?? 'unknown',
                'plan'       => $d['plan'],     // NULL のまま返す（削除結果が分かる）
                'comment'    => $d['comment'],  // 同上
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$d['updated_at'])),
                'changed'    => $changed,       // 単体レスポンス互換のため data 内にも格納
            ]
        ];
    }

    // 返却（成功・失敗混在でも results に詰める）
    if ($isBatch) {
        jsonResponse('success', 'updated', ['results' => $results]);
    } else {
        $r0 = $results[0] ?? null;
        if (!$r0 || !$r0['ok']) {
            http_response_code(400);
            jsonResponse('error', $r0['error'] ?? 'update failed');
        }
        // 単体は data を返す（互換維持）。changed は data.changed で参照可能。
        jsonResponse('success', 'updated', $r0['data']);
    }

} catch (Throwable $e) {
    error_log('[ATTENDANCE_UPDATE] '.$e->getMessage());
    http_response_code(500);
    jsonResponse('error', 'Server Error');
}
