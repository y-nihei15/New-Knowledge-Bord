<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__.'/../common_api/config/db.php';
require_once __DIR__.'/../common_api/jwt/require_auth.php';

header('Content-Type: application/json; charset=utf-8');

$auth = require_auth(); // JWTチェック
$pdo  = getDbConnection();


// 共通JSONレスポンス
function jsonResponse(string $status, ?string $message = null, $data = null): void {
  header('Content-Type: application/json; charset=UTF-8');
  $out = ['status' => $status];
  if ($message !== null) $out['message'] = $message;
  if ($data !== null)    $out['data']    = $data;
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

/** 半角=1, 全角=2 の長さ */
function mbLengthZenkakuAware(string $s): int {
  $len = 0;
  $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
  if ($chars === false) return 0;
  foreach ($chars as $ch) {
    $len += preg_match('/[ -~]/u', $ch) ? 1 : 2; // ASCII可視+空白は半角=1、それ以外は全角=2
  }
  return $len;
}

/**
 * POST /attendance/update
 * - 単体: { account_id, status?, plan?, comment?, sort?, name? }
 * - まとめて: { items: [ { ... }, ... ] }
 * - status: "1"|"2"|"3" もしくは "present"|"absent"|"leave"
 * - plan/comment: 空文字 "" を受けたら NULL に更新
 * - 値が実際に変わったときだけ updated_at を更新（WHERE で比較）
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

    $set        = [];
    $params     = [];
    $cmp        = [];
    $cmpParams  = [];

    if (array_key_exists('status', $row)) {
      $s = strtolower((string)$row['status']);
      if (!isset($statusMap[$s])) {
        $results[] = ['index'=>$idx,'ok'=>false,'error'=>'invalid status'];
        continue;
      }
      $val = $statusMap[$s];
      $set[] = 'status = ?';
      $params[] = $val;
      $cmp[] = 'status <=> ?';
      $cmpParams[] = $val;
    }

    if (array_key_exists('plan', $row)) {
      $v = $row['plan'];
      if (!is_string($v)) {
        $results[] = ['index'=>$idx,'ok'=>false,'error'=>'plan must be string'];
        continue;
      }
      if ($v === '') {
        $set[] = 'plan = NULL';
        $cmp[] = 'plan IS NULL';
      } else {
        if (mbLengthZenkakuAware($v) > 150) { // 全角=2/半角=1 換算で150以内
          $results[] = ['index'=>$idx,'ok'=>false,'error'=>'plan must be <= 150 (zenkaku75/han150)'];
          continue;
        }
        $set[] = 'plan = ?';
        $params[] = $v;
        $cmp[] = 'plan <=> ?';
        $cmpParams[] = $v;
      }
    }

    if (array_key_exists('comment', $row)) {
      $v = $row['comment'];
      if (!is_string($v)) {
        $results[] = ['index'=>$idx,'ok'=>false,'error'=>'comment must be string'];
        continue;
      }
      if ($v === '') {
        $set[] = 'comment = NULL';
        $cmp[] = 'comment IS NULL';
      } else {
        if (mbLengthZenkakuAware($v) > 150) { // 全角=2/半角=1 換算で150以内
          $results[] = ['index'=>$idx,'ok'=>false,'error'=>'comment must be <= 150 (zenkaku75/han150)'];
          continue;
        }
        $set[] = 'comment = ?';
        $params[] = $v;
        $cmp[] = 'comment <=> ?';
        $cmpParams[] = $v;
      }
    }

    if (array_key_exists('sort', $row)) {
      $v = filter_var($row['sort'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
      if ($v === false) {
        $results[] = ['index'=>$idx,'ok'=>false,'error'=>'sort must be integer (>=0)'];
        continue;
      }
      $set[] = 'sort = ?';
      $params[] = $v;
      $cmp[] = 'sort <=> ?';
      $cmpParams[] = $v;
    }

    if (array_key_exists('name', $row)) {
      $name = (string)$row['name'];
      // nameは従来仕様のまま（必要なら同じ換算で制限へ変更可）
      if (strlen($name) > 100) { // バイト長100
        $results[] = ['index'=>$idx,'ok'=>false,'error'=>'name must be <= 100 bytes'];
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

    // 値が実際に変わった場合だけ updated_at を進める
    $set[] = 'updated_at = UTC_TIMESTAMP()';

    // UPDATE（変化なしなら更新しない）
    $sql = 'UPDATE employee_info SET '.implode(', ', $set).
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
        'plan'       => $d['plan'],
        'comment'    => $d['comment'],
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$d['updated_at'])),
        'changed'    => $changed,
      ]
    ];
  }

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
  http_response_code(500);
  jsonResponse('error', 'Server Error: '.$e->getMessage());
}

// } catch (Throwable $e) {
//   error_log('[ATTENDANCE_UPDATE] '.$e->getMessage());
//   http_response_code(500);
//   jsonResponse('error', 'Server Error');
// }
