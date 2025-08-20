<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/response.php'; // jsonResponse($status,$message,$data)

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    jsonResponse('error', '許可されていないメソッドです');
}

// Content-Typeだけ軽く確認
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    http_response_code(415);
    jsonResponse('error', 'unsupported media type');
}

// 入力
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    jsonResponse('error', 'invalid json');
}

// 単体 or バッチ
$items = (isset($input['items']) && is_array($input['items'])) ? $input['items'] : [$input];

// status 文字列→数値, 数値→文字列
$statusMap    = ['present'=>1, 'absent'=>2, 'leave'=>3];
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

        // 更新フィールドを最小限に収集（sort/name/status/plan/comment）
        $set = []; $params = [];
        if (array_key_exists('sort', $row)) {
            $v = filter_var($row['sort'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($v === false) { $results[] = ['index'=>$idx,'ok'=>false,'error'=>'sort must be integer (>=0)']; continue; }
            $set[] = 'sort = ?'; $params[] = $v;
        }
        if (array_key_exists('name', $row)) {
            $name = (string)$row['name'];
            if (strlen($name) > 100) { $results[] = ['index'=>$idx,'ok'=>false,'error'=>'name must be <= 100 bytes']; continue; }
            $set[] = 'name = ?'; $params[] = $name;
        }
        if (array_key_exists('status', $row)) {
            $s = strtolower((string)$row['status']);
            if (!isset($statusMap[$s])) { $results[] = ['index'=>$idx,'ok'=>false,'error'=>'invalid status']; continue; }
            $set[] = 'status = ?'; $params[] = $statusMap[$s];
        }
        if (array_key_exists('plan', $row)) {
            $v = (string)$row['plan'];
            if (strlen($v) > 150) { $results[] = ['index'=>$idx,'ok'=>false,'error'=>'plan must be <= 150 bytes']; continue; }
            $set[] = 'plan = ?'; $params[] = $v;
        }
        if (array_key_exists('comment', $row)) {
            $v = (string)$row['comment'];
            if (strlen($v) > 150) { $results[] = ['index'=>$idx,'ok'=>false,'error'=>'comment must be <= 150 bytes']; continue; }
            $set[] = 'comment = ?'; $params[] = $v;
        }

        if (!$set) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'no updatable fields'];
            continue;
        }

        // UPDATE（必要最小限：個別トランザクションや楽観ロックは省略）
        $set[] = 'updated_at = UTC_TIMESTAMP()';
        $params[] = $accountId;

        $sql = 'UPDATE employee_info SET '.implode(', ',$set).' WHERE account_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // 反映後の確定値を返す（statusは文字列へ）
        $stmt = $pdo->prepare('SELECT account_id, sort, name, status, plan, comment, updated_at FROM employee_info WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'not found'];
            continue;
        }

        $results[] = [
            'index' => $idx,
            'ok'    => true,
            'data'  => [
                'account_id' => (int)$d['account_id'],
                'sort'       => isset($d['sort']) ? (int)$d['sort'] : null,
                'name'       => $d['name'],
                'status'     => $statusRevMap[(int)($d['status'] ?? 0)] ?? 'unknown',
                'plan'       => $d['plan'],
                'comment'    => $d['comment'],
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($d['updated_at']))
            ]
        ];
    }

    // 単体/バッチ で返却を寄せる（成功・失敗が混在しても 200 で返し、結果はresults内で判定）
    if (isset($input['items'])) {
        jsonResponse('success', 'updated', ['results' => $results]);
    } else {
        $r0 = $results[0] ?? null;
        if (!$r0 || !$r0['ok']) {
            http_response_code(400);
            jsonResponse('error', $r0['error'] ?? 'update failed');
        }
        jsonResponse('success', 'updated', $r0['data']);
    }

} catch (Throwable $e) {
    error_log('[ATTENDANCE_UPDATE_MIN] '.$e->getMessage());
    http_response_code(500);
    jsonResponse('error', 'Server Error');
}
