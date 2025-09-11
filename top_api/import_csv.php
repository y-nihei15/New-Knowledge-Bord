<?php
declare(strict_types=1);

require_once __DIR__ . '/../common_api/config/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'POST only']); exit;
  }

  /** 認可（本番で有効化）
   * 例）require_once __DIR__ . '/../common_api/auth.php';
   *     requireAdminOrExit(); // JWTの is_admin / role 等で管理者のみ許可
   */

  // ===== パラメータ検証 =====
  if (!isset($_POST['location_id'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'location_id required']); exit;
  }
  $locationId = max(1, (int)$_POST['location_id']);

  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'file upload error']); exit;
  }

  // サイズ上限（任意で調整）
  $maxBytes = 5 * 1024 * 1024; // 5MB
  if ((int)$_FILES['file']['size'] > $maxBytes) {
    http_response_code(413);
    echo json_encode(['ok'=>false,'error'=>'file too large']); exit;
  }

  // ===== ファイル読込 =====
  $csv = file_get_contents($_FILES['file']['tmp_name']);
  if ($csv === false) throw new RuntimeException('read failed');

  // ===== 文字コード統一（UTF-16/32, SJIS 等 → UTF-8） =====
  $encodings = [
    'UTF-8','UTF-16LE','UTF-16BE','UTF-32LE','UTF-32BE',
    'SJIS-win','CP932','SJIS','EUC-JP','ISO-2022-JP'
  ];
  $det = mb_detect_encoding($csv, $encodings, true) ?: 'UTF-8';
  if ($det !== 'UTF-8') {
    $csv = mb_convert_encoding($csv, 'UTF-8', $det);
  }

  // ===== 区切り推定 =====
  $firstLine = strtok($csv, "\r\n") ?: '';
  $delims = [',',';',"\t"];
  $counts = array_map(fn($d)=>substr_count((string)$firstLine, $d), $delims);
  $delim = $delims[array_keys($counts, max($counts) ?: 0)[0]] ?? ',';

  // ===== ストリーム化 =====
  $fp = fopen('php://temp', 'r+');
  fwrite($fp, $csv);
  rewind($fp);

  // ===== ヘッダ読み込み＋正規化 =====
  $rawHeader = fgetcsv($fp, 0, $delim);
  if (!$rawHeader) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'empty csv']); exit;
  }

  $norm = function($h) {
    $h = (string)$h;
    // UTF-8 BOM
    $h = preg_replace('/^\xEF\xBB\xBF/u', '', $h);
    // 制御文字・ZW空白
    $h = preg_replace('/[\x00-\x1F\x7F\x{200B}\x{FEFF}]+/u', '', $h);
    return strtolower(trim($h));
  };
  $header = array_map($norm, $rawHeader);

  // ===== 必須列（user_id をキーにする） =====
  $required = ['user_id','emp_name','sort'];
  $missing = array_values(array_diff($required, $header));

  // うまく行かない時は区切りを変えて再試行（保険）
  if ($missing) {
    rewind($fp);
    foreach ([',',';',"\t"] as $tryDelim) {
      $tryHeader = fgetcsv($fp, 0, $tryDelim);
      if ($tryHeader) {
        $tryHeader = array_map($norm, $tryHeader);
        $miss2 = array_values(array_diff($required, $tryHeader));
        if (!$miss2) { $header = $tryHeader; $delim = $tryDelim; $missing = []; break; }
      }
    }
  }
  if ($missing) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>"missing column: ".implode(', ', $missing)]); exit;
  }

  $idx = array_flip($header);

  // ===== DB 準備 =====
  $pdo = getDbConnection();
  $pdo->beginTransaction();

  // ★必要ならより安全に：テーブルロック（並行実行を完全防止）
  // $pdo->exec('LOCK TABLES employee_info WRITE, login_info WRITE');

  // ---- 連番の基準：両テーブルの最大 account_id を見る（重複回避の土台） ----
  $selMaxBoth = $pdo->query("
    SELECT GREATEST(
      COALESCE((SELECT MAX(account_id) FROM employee_info), 0),
      COALESCE((SELECT MAX(account_id) FROM login_info), 0)
    ) AS max_id
  ");
  $nextAcc = (int)$selMaxBoth->fetchColumn();

  // 部門 name=>id マップ（キャッシュ）
  $deptMap = [];
  $qDepts = $pdo->query("SELECT id, name FROM dept_mst");
  foreach ($qDepts->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $deptMap[trim((string)$d['name'])] = (int)$d['id'];
  }
  $insDept = $pdo->prepare("INSERT INTO dept_mst (name) VALUES (:name)");

  // user_id → account_id 変換用（存在チェック用）
  $selAcc = $pdo->prepare("SELECT account_id FROM login_info WHERE user_id=:uid");
  $uidCache = [];

  // login_info 側の占有チェック（account_id重複最終保険）
  $selLoginByUid = $pdo->prepare("SELECT account_id FROM login_info WHERE user_id=:uid");
  $selLoginByAcc = $pdo->prepare("SELECT 1 FROM login_info WHERE account_id=:acc");

  // employee_info 既存有無判定
  $selEmp = $pdo->prepare("SELECT 1 FROM employee_info WHERE account_id=:acc");

  // UPDATE（列欠如時は現状維持）
  $updEmp = $pdo->prepare("
    UPDATE employee_info
       SET name = :name,
           dept_id = :dept_id,
           sort = :sort,
           status  = COALESCE(:status_keep,  status),
           plan    = COALESCE(:plan_keep,    plan),
           comment = COALESCE(:comment_keep, comment),
           location_id = :lid
     WHERE account_id = :acc
  ");

  // INSERT（employee_info）
  $insEmp = $pdo->prepare("
    INSERT INTO employee_info
      (account_id, name, dept_id, sort, status, plan, comment, location_id)
    VALUES
      (:acc, :name, :dept_id, :sort, :status, :plan, :comment, :lid)
  ");

  // INSERT/UPDATE（login_info）
  $insLogin = $pdo->prepare("
    INSERT INTO login_info (account_id, user_id, password)
    VALUES (:acc, :uid, :pwd)
  ");
  $updLoginPwd = $pdo->prepare("
    UPDATE login_info SET password=:pwd WHERE account_id=:acc
  ");

  $updated = 0; $inserted = 0; $skipped = 0;

  // ===== 本文処理 =====
  while (($row = fgetcsv($fp, 0, $delim)) !== false) {
    // 空行スキップ
    if (count($row) === 1 && trim((string)$row[0]) === '') continue;

    // トリム
    $row = array_map(fn($v)=> is_string($v) ? trim($v) : $v, $row);

    // 必須
    $userId   = (string)($row[$idx['user_id']] ?? '');
    $empName  = (string)($row[$idx['emp_name']] ?? '');
    $sort     = (int)($row[$idx['sort']] ?? 0);

    if ($userId === '' || $empName === '') { $skipped++; continue; }

    // user_id → account_id 変換（キャッシュ活用）
    $accountId = $uidCache[$userId] ?? null;
    if ($accountId === null) {
      $selAcc->execute([':uid'=>$userId]);
      $acc = $selAcc->fetchColumn();
      $accountId = $acc !== false ? (int)$acc : 0;
      $uidCache[$userId] = $accountId;
    }

    // 任意列：存在かつ非空のときだけ更新値に採用（なければ null → 現状維持）
    $status  = (isset($idx['status'])  && ($row[$idx['status']]  ?? '') !== '') ? (int)$row[$idx['status']]  : null;
    $plan    = (isset($idx['plan'])    && ($row[$idx['plan']]    ?? '') !== '') ? (string)$row[$idx['plan']] : null;
    $comment = (isset($idx['comment']) && ($row[$idx['comment']] ?? '') !== '') ? (string)$row[$idx['comment']] : null;
    if ($status !== null && ($status < 1 || $status > 3)) $status = 2; // 正規化

    // 部門：dept_id 優先、無ければ dept_name で解決（無ければ自動作成※不要ならCREATE部分をコメントアウト）
    $deptId = null;
    if (isset($idx['dept_id']) && ($row[$idx['dept_id']] ?? '') !== '') {
      $deptId = (int)$row[$idx['dept_id']];
    } elseif (isset($idx['dept_name']) && ($row[$idx['dept_name']] ?? '') !== '') {
      $deptName = (string)$row[$idx['dept_name']];
      $key = trim($deptName);
      if ($key !== '') {
        if (!array_key_exists($key, $deptMap)) {
          $insDept->execute([':name'=>$key]); // 自動作成を止めたい場合はこの3行をコメントアウト
          $deptMap[$key] = (int)$pdo->lastInsertId();
        }
        $deptId = $deptMap[$key];
      }
    }

    // ===== 既存判定 =====
    $exists = false;
    if ($accountId > 0) {
      $selEmp->execute([':acc'=>$accountId]);
      $exists = (bool)$selEmp->fetchColumn();
    }

    if ($exists) {
      // ===== 既存ユーザー：UPDATE =====
      $updEmp->execute([
        ':name'         => $empName,
        ':dept_id'      => $deptId,
        ':sort'         => $sort,
        ':status_keep'  => $status,
        ':plan_keep'    => $plan,
        ':comment_keep' => $comment,
        ':lid'          => $locationId,
        ':acc'          => $accountId
      ]);
      if ($updEmp->rowCount() > 0) $updated++;

    } else {
      // ===== 新規ユーザー：まず user_id の再利用を最優先 =====
      $selLoginByUid->execute([':uid' => $userId]);
      $reuseAcc = $selLoginByUid->fetchColumn();

      if ($reuseAcc !== false) {
        // 既に login_info に user_id がいる → その account_id を再利用
        $newAcc = (int)$reuseAcc;

        // password を要件どおり user_id に更新（※本番はハッシュ推奨）
        $passwordToStore = (string)$userId;
        // 推奨: $passwordToStore = password_hash((string)$userId, PASSWORD_DEFAULT);
        $updLoginPwd->execute([':pwd'=>$passwordToStore, ':acc'=>$newAcc]);

        // employee_info が無ければ INSERT、あれば UPDATE
        $selEmp->execute([':acc'=>$newAcc]);
        $empExists = (bool)$selEmp->fetchColumn();

        if ($empExists) {
          $updEmp->execute([
            ':name'         => $empName,
            ':dept_id'      => $deptId,
            ':sort'         => $sort,
            ':status_keep'  => $status,
            ':plan_keep'    => $plan,
            ':comment_keep' => $comment,
            ':lid'          => $locationId,
            ':acc'          => $newAcc
          ]);
          if ($updEmp->rowCount() > 0) $updated++;
        } else {
          $insEmp->execute([
            ':acc'     => $newAcc,
            ':name'    => $empName,
            ':dept_id' => $deptId,
            ':sort'    => $sort,
            ':status'  => $status  ?? 2,
            ':plan'    => $plan    ?? '',
            ':comment' => $comment ?? '',
            ':lid'     => $locationId
          ]);
          $inserted++;
        }

        $uidCache[$userId] = $newAcc;

      } else {
        // ===== 完全新規：衝突しない account_id を連番で確保 =====
        do {
          $nextAcc++;
          $newAcc = $nextAcc;

          $selLoginByAcc->execute([':acc'=>$newAcc]);
          $busyLogin = (bool)$selLoginByAcc->fetchColumn();

          $selEmp->execute([':acc'=>$newAcc]);
          $busyEmp = (bool)$selEmp->fetchColumn();

        } while ($busyLogin || $busyEmp);

        // login_info を INSERT（password = user_id）
        $passwordToStore = (string)$userId; // 推奨: password_hash((string)$userId, PASSWORD_DEFAULT);
        $insLogin->execute([
          ':acc' => $newAcc,
          ':uid' => $userId,
          ':pwd' => $passwordToStore,
        ]);

        // employee_info を INSERT
        $insEmp->execute([
          ':acc'     => $newAcc,
          ':name'    => $empName,
          ':dept_id' => $deptId,
          ':sort'    => $sort,
          ':status'  => $status  ?? 2,
          ':plan'    => $plan    ?? '',
          ':comment' => $comment ?? '',
          ':lid'     => $locationId
        ]);

        $uidCache[$userId] = $newAcc;
        $inserted++;
      }
    }
  }

  // if テーブルロックを掛けていたらここで解除
  // $pdo->exec('UNLOCK TABLES');

  $pdo->commit();

  echo json_encode([
    'ok'       => true,
    'updated'  => $updated,
    'inserted' => $inserted,
    'skipped'  => $skipped
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
