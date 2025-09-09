<?php
declare(strict_types=1);

require_once __DIR__ . '/../common_api/jwt/require_auth.php';
$claims = require_auth(['json' => true]); // OKならここを通過、NGなら401 JSON返して終了

/**
 * 出席管理: 更新API (JSON/POST 専用)
 * - 単体  : { account_id, status?, plan?, comment?, sort?, name? }
 * - バッチ: { items: [ { ... }, ... ] }
 * 仕様:
 * - Content-Type: application/json（;charset=UTF-8 等のパラメータ許容）
 * - status: "present"|"absent"|"leave" or "1"|"2"|"3"
 * - plan/comment: 空文字 "" を受けたら NULL に更新
 * - 実際に値が変わったときのみ updated_at を進める
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../common_api/config/db.php';
require_once __DIR__ . '/../common_api/jwt/require_auth.php';
require_once __DIR__ . '/../common_api/utils/response.php'; // jsonResponse($status, $message = null, $data = null)

header('Content-Type: application/json; charset=UTF-8');

/* ========= 共通ユーティリティ ========= */

/** 半角=1, 全角=2 の長さを返す */
function mbLengthZenkakuAware(string $s): int {
    $len = 0;
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) return 0;
    foreach ($chars as $ch) {
        // ASCII可視文字と空白は半角=1、それ以外は全角=2
        $len += preg_match('/[ -~]/u', $ch) ? 1 : 2;
    }
    return $len;
}

/** Content-Type が application/json かどうか（パラメータ付き許容） */
function isJsonContentType(?string $ct): bool {
    if ($ct === null) return false;
    $semi = strpos($ct, ';');
    $mime = ($semi === false) ? trim($ct) : trim(substr($ct, 0, $semi));
    return strcasecmp($mime, 'application/json') === 0;
}

/** JSON を安全に読む */
function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        jsonResponse('error', 'empty body');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        jsonResponse('error', 'invalid json');
    }
    return $data;
}

/* ========= 認可・メソッドチェック ========= */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    // CORS プリフライト等を考慮（必要ならここでヘッダ追加）
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    jsonResponse('error', '許可されていないメソッドです');
}

if (!isJsonContentType($_SERVER['CONTENT_TYPE'] ?? null)) {
    http_response_code(415);
    jsonResponse('error', 'unsupported media type');
}

// Authorization: Bearer <JWT> 必須
$auth = require_auth(); // 値は以降使わなくても、ここで検証・失効チェックが走る

/* ========= 入力解析 ========= */

$input   = readJsonBody();
$isBatch = isset($input['items']) && is_array($input['items']);
$items   = $isBatch ? $input['items'] : [$input];

$statusMap    = ['present'=>1, 'absent'=>2, 'leave'=>3, '1'=>1, '2'=>2, '3'=>3];
$statusRevMap = [0=>'unknown', 1=>'present', 2=>'absent', 3=>'leave'];

/* ========= DB 接続 ========= */

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (Throwable $e) {
    error_log('[DB_CONNECT_ERROR] '.$e->getMessage());
    http_response_code(500);
    jsonResponse('error', 'DB connection error');
}

/* ========= 主処理 ========= */

$results = [];

try {
    foreach ($items as $idx => $row) {
        if (!is_array($row)) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'invalid item'];
            continue;
        }

        // 必須: account_id（正の整数）
        $accountId = filter_var($row['account_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$accountId) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'account_id must be positive integer'];
            continue;
        }

        $set        = [];
        $params     = [];
        $cmp        = [];
        $cmpParams  = [];

        // status
        if (array_key_exists('status', $row)) {
            $s = strtolower((string)$row['status']);
            if (!isset($statusMap[$s])) {
                $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'invalid status'];
                continue;
            }
            $val = $statusMap[$s];
            $set[] = 'status = ?';
            $params[] = $val;
            $cmp[] = 'status <=> ?';
            $cmpParams[] = $val;
        }

        // plan
        if (array_key_exists('plan', $row)) {
            $v = $row['plan'];
            if (!is_string($v)) {
                $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'plan must be string'];
                continue;
            }
            if ($v === '') {
                $set[] = 'plan = NULL';
                $cmp[] = 'plan IS NULL';
            } else {
                if (mbLengthZenkakuAware($v) > 150) {
                    $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'plan must be <= 150 (zenkaku75/han150)'];
                    continue;
                }
                $set[] = 'plan = ?';
                $params[] = $v;
                $cmp[] = 'plan <=> ?';
                $cmpParams[] = $v;
            }
        }

        // comment
        if (array_key_exists('comment', $row)) {
            $v = $row['comment'];
            if (!is_string($v)) {
                $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'comment must be string'];
                continue;
            }
            if ($v === '') {
                $set[] = 'comment = NULL';
                $cmp[] = 'comment IS NULL';
            } else {
                if (mbLengthZenkakuAware($v) > 150) {
                    $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'comment must be <= 150 (zenkaku75/han150)'];
                    continue;
                }
                $set[] = 'comment = ?';
                $params[] = $v;
                $cmp[] = 'comment <=> ?';
                $cmpParams[] = $v;
            }
        }

        // sort
        if (array_key_exists('sort', $row)) {
            $v = filter_var($row['sort'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($v === false) {
                $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'sort must be integer (>=0)'];
                continue;
            }
            $set[] = 'sort = ?';
            $params[] = $v;
            $cmp[] = 'sort <=> ?';
            $cmpParams[] = $v;
        }

        // name
        if (array_key_exists('name', $row)) {
            $name = (string)$row['name'];
            // 従来仕様: バイト長100以内
            if (strlen($name) > 100) {
                $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'name must be <= 100 bytes'];
                continue;
            }
            $set[] = 'name = ?';
            $params[] = $name;
            $cmp[] = 'name <=> ?';
            $cmpParams[] = $name;
        }

        if (!$set) {
            $results[] = ['index'=>$idx, 'ok'=>false, 'error'=>'no updatable fields'];
            continue;
        }

        // 実際に値が変わった場合のみ updated_at を更新
        $set[] = 'updated_at = UTC_TIMESTAMP()';

        // UPDATE（変化なしなら更新しない）
        $sql = 'UPDATE employee_info SET ' . implode(', ', $set)
             . ' WHERE account_id = ?'
             . ($cmp ? ' AND NOT (' . implode(' AND ', $cmp) . ')' : '');

        $paramsAll = array_merge($params, [$accountId], $cmpParams);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsAll);
        $changed = ($stmt->rowCount() > 0);

        // 確定値を取得
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
                'plan'       => $d['plan'],
                'comment'    => $d['comment'],
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$d['updated_at'])),
                'changed'    => $changed,
            ],
        ];
    }

    // レスポンス整形
    if ($isBatch) {
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
    error_log('[ATTENDANCE_UPDATE_ERROR] ' . $e->getMessage());
    http_response_code(500);
    jsonResponse('error', 'Server Error');
}
