<?php
declare(strict_types=1);

require_once __DIR__ . '/../common_api/config/db.php';
header('Content-Type: application/json; charset=UTF-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'POST only']); exit;
  }

  // 認証/認可（必要に応じて有効化）
  // require_once __DIR__ . '/../common_api/auth.php';
  // $auth = verifyBearerOrExit();
  // requireAdminOrExit();

  // location_id 検証 & 存在確認
  if (!isset($_POST['location_id'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'location_id required']); exit;
  }
  $locationId = filter_var($_POST['location_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
  if (!$locationId) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid location_id']); exit;
  }

  $pdo = getDbConnection();
  $chkLoc = $pdo->prepare('SELECT id FROM location_mst WHERE id=:id');
  $chkLoc->bindValue(':id', $locationId, PDO::PARAM_INT);
  $chkLoc->execute();
  if (!$chkLoc->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'location not found']); exit;
  }

  // アップロード検査
  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'file upload error']); exit;
  }

  $maxBytes = 5 * 1024 * 1024;
  if ((int)$_FILES['file']['size'] > $maxBytes) {
    http_response_code(413);
    echo json_encode(['ok'=>false,'error'=>'file too large']); exit;
  }

  $csv = file_get_contents($_FILES['file']['tmp_name']);
  if ($csv === false) throw new RuntimeException('read failed');

  $encodings = ['UTF-8','UTF-16LE','UTF-16BE','UTF-32LE','UTF-32BE','SJIS-win','CP932','SJIS','EUC-JP','ISO-2022-JP'];
  $det = mb_detect_encoding($csv, $encodings, true) ?: 'UTF-8';
  if ($det !== 'UTF-8') $csv = mb_convert_encoding($csv, 'UTF-8', $det);

  $csv = preg_replace("/\r\n|\r/u", "\n", $csv);

  // 区切り推定
  $firstLine = strtok($csv, "\n") ?: '';
  $delims = [',',';',"\t"];
  $counts = array_map(fn($d)=>substr_count((string)$firstLine, $d), $delims);
  $delim = $delims[array_keys($counts, max($counts) ?: 0)[0]] ?? ',';

  $fp = fopen('php://temp', 'r+');
  fwrite($fp, $csv);
  rewind($fp);

  $rawHeader = fgetcsv($fp, 0, $delim);
  if (!$rawHeader) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'empty csv']); exit;
  }

  $normHeader = function($h) {
    $h = (string)$h;
    $h = preg_replace('/^\xEF\xBB\xBF/u', '', $h);
    $h = preg_replace('/[\x00-\x1F\x7F\x{200B}\x{FEFF}]+/u', '', $h);
    return strtolower(trim($h));
  };
  $header = array_map($normHeader, $rawHeader);

  if (count($header) !== count(array_unique($header))) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'duplicate header']); exit;
  }

  // 必須列（※削除指示でも user_id / emp_name / sort は要求）
  $required = ['user_id','emp_name','sort'];
  $missing = array_values(array_diff($required, $header));
  if ($missing) {
    rewind($fp);
    foreach ([',',';',"\t"] as $tryDelim) {
      $tryHeader = fgetcsv($fp, 0, $tryDelim);
      if ($tryHeader) {
        $tryHeader = array_map($normHeader, $tryHeader);
        if (count($tryHeader) === count(array_unique($tryHeader))) {
          $miss2 = array_values(array_diff($required, $tryHeader));
          if (!$miss2) { $header = $tryHeader; $delim = $tryDelim; $missing = []; break; }
        }
      }
    }
  }
  if ($missing) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>"missing column: ".implode(', ', $missing)]); exit;
  }

  $idx = array_flip($header);

  // ===== DB準備 =====
  $pdo->beginTransaction();

  // 削除用
  $selAccByUid  = $pdo->prepare("SELECT account_id FROM login_info WHERE user_id=:uid");
  $delEmpByAcc  = $pdo->prepare("DELETE FROM employee_info WHERE account_id=:acc");
  $delLoginByAcc= $pdo->prepare("DELETE FROM login_info WHERE account_id=:acc");

  // 連番採番ベース
  $selMaxBoth = $pdo->query("
    SELECT GREATEST(
      COALESCE((SELECT MAX(account_id) FROM employee_info), 0),
      COALESCE((SELECT MAX(account_id) FROM login_info), 0)
    ) AS max_id
  ");
  $nextAcc = (int)$selMaxBoth->fetchColumn();

  // 部門正規化（※元の仕様：全角スペース→半角、全空白圧縮）
  $normalizeDept = function($s) {
    $s = (string)$s;
    $s = preg_replace('/\x{3000}/u', ' ', $s);  // 全角空白→半角
    $s = preg_replace('/\s+/u', ' ', $s);       // 連続空白を1つに
    return trim($s);
  };

  // 部門 name -> id マップ（キーは正規化後の文字列、DB保存も正規化名）
  $deptMapByName = [];
  $qDepts = $pdo->query("SELECT id, name FROM dept_mst");
  foreach ($qDepts->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $deptMapByName[$normalizeDept($d['name'])] = (int)$d['id'];
  }

  // name に UNIQUE が必要
  $insDeptAuto = $pdo->prepare("
    INSERT INTO dept_mst (name) VALUES (:name)
    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
  ");

  // 参照
  $selAcc        = $pdo->prepare("SELECT account_id FROM login_info WHERE user_id=:uid");
  $selLoginByUid = $pdo->prepare("SELECT account_id FROM login_info WHERE user_id=:uid");
  $selLoginByAcc = $pdo->prepare("SELECT 1 FROM login_info WHERE account_id=:acc");
  $selEmp        = $pdo->prepare("SELECT 1 FROM employee_info WHERE account_id=:acc");

  // 変更
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
  $insEmp = $pdo->prepare("
    INSERT INTO employee_info
      (account_id, name, dept_id, sort, status, plan, comment, location_id)
    VALUES
      (:acc, :name, :dept_id, :sort, :status, :plan, :comment, :lid)
  ");
  $insLogin = $pdo->prepare("
    INSERT INTO login_info (account_id, user_id, password)
    VALUES (:acc, :uid, :pwd)
  ");
  $updLoginPwd = $pdo->prepare("UPDATE login_info SET password=:pwd WHERE account_id=:acc");

  $updated = 0; $inserted = 0; $skipped = 0; $deleted = 0;
  $newDepartments = [];
  $updatedIds = []; // debug

  $uidCache = [];
  $maxRows = 5000; $rowCount = 0;

  while (($row = fgetcsv($fp, 0, $delim)) !== false) {
    if (++$rowCount > $maxRows) { throw new RuntimeException('row limit exceeded'); }
    if (count($row) === 1 && trim((string)$row[0]) === '') continue;

    $row = array_map(fn($v)=> is_string($v) ? trim($v) : $v, $row);

    $userId  = (string)($row[$idx['user_id']] ?? '');
    $empName = (string)($row[$idx['emp_name']] ?? '');
    $sort    = (int)($row[$idx['sort']] ?? 0);
    if ($userId === '') { $skipped++; continue; }

    // 削除指示（emp_name = none）
    $empNameNorm = strtolower(trim($empName));
    if ($empNameNorm === 'none') {
      $selAccByUid->execute([':uid'=>$userId]);
      $acc = $selAccByUid->fetchColumn();
      if ($acc !== false) {
        $accId = (int)$acc;
        $delEmpByAcc->execute([':acc'=>$accId]);
        $delLoginByAcc->execute([':acc'=>$accId]);
        $deleted++;
      } else {
        $skipped++;
      }
      continue;
    }

    // 通常フロー
    if ($empName === '') { $skipped++; continue; }

    $status  = (isset($idx['status'])  && ($row[$idx['status']]  ?? '') !== '') ? (int)$row[$idx['status']]  : null;
    $plan    = (isset($idx['plan'])    && ($row[$idx['plan']]    ?? '') !== '') ? (string)$row[$idx['plan']] : null;
    $comment = (isset($idx['comment']) && ($row[$idx['comment']] ?? '') !== '') ? (string)$row[$idx['comment']] : null;
    if ($status !== null && ($status < 1 || $status > 3)) $status = 2;

    // 部門決定：dept_name のみ使用（正規化名で保存）
    $deptId = null;
    $csvHasDeptName = isset($idx['dept_name']) && ($row[$idx['dept_name']] ?? '') !== '';
    if ($csvHasDeptName) {
      $normName = $normalizeDept((string)$row[$idx['dept_name']]);
      if ($normName !== '') {
        if (!isset($deptMapByName[$normName])) {
          $insDeptAuto->execute([':name' => $normName]);
          $newId = (int)$pdo->lastInsertId();
          $deptMapByName[$normName] = $newId;
          $newDepartments[] = ['id' => $newId, 'name' => $normName];
        }
        $deptId = $deptMapByName[$normName];
      }
    }

    // user_id → account_id
    $accountId = $uidCache[$userId] ?? null;
    if ($accountId === null) {
      $selAcc->execute([':uid'=>$userId]);
      $acc = $selAcc->fetchColumn();
      $accountId = $acc !== false ? (int)$acc : 0;
      $uidCache[$userId] = $accountId;
    }

    $exists = false;
    if ($accountId > 0) {
      $selEmp->execute([':acc'=>$accountId]);
      $exists = (bool)$selEmp->fetchColumn();
    }

    if ($exists) {
      $updEmp->execute([
        ':name'=>$empName, ':dept_id'=>$deptId, ':sort'=>$sort,
        ':status_keep'=>$status, ':plan_keep'=>$plan, ':comment_keep'=>$comment,
        ':lid'=>$locationId, ':acc'=>$accountId
      ]);
      if ($updEmp->rowCount() > 0) { $updated++; $updatedIds[] = (int)$accountId; }

    } else {
      // user_id再利用優先
      $selLoginByUid->execute([':uid'=>$userId]);
      $reuseAcc = $selLoginByUid->fetchColumn();

      if ($reuseAcc !== false) {
        $newAcc = (int)$reuseAcc;
        $passwordToStore = password_hash((string)$userId, PASSWORD_DEFAULT);
        $updLoginPwd->execute([':pwd'=>$passwordToStore, ':acc'=>$newAcc]);

        $selEmp->execute([':acc'=>$newAcc]);
        if ($selEmp->fetchColumn()) {
          $updEmp->execute([
            ':name'=>$empName, ':dept_id'=>$deptId, ':sort'=>$sort,
            ':status_keep'=>$status, ':plan_keep'=>$plan, ':comment_keep'=>$comment,
            ':lid'=>$locationId, ':acc'=>$newAcc
          ]);
          if ($updEmp->rowCount() > 0) { $updated++; $updatedIds[] = (int)$newAcc; }
        } else {
          $insEmp->execute([
            ':acc'=>$newAcc, ':name'=>$empName, ':dept_id'=>$deptId, ':sort'=>$sort,
            ':status'=>$status ?? 2, ':plan'=>$plan ?? '', ':comment'=>$comment ?? '',
            ':lid'=>$locationId
          ]);
          $inserted++;
        }
        $uidCache[$userId] = $newAcc;

      } else {
        // 新規 account_id
        do {
          $nextAcc++;
          $newAcc = $nextAcc;
          $selLoginByAcc->execute([':acc'=>$newAcc]);
          $busyLogin = (bool)$selLoginByAcc->fetchColumn();
          $selEmp->execute([':acc'=>$newAcc]);
          $busyEmp = (bool)$selEmp->fetchColumn();
        } while ($busyLogin || $busyEmp);

        $passwordToStore = password_hash((string)$userId, PASSWORD_DEFAULT);
        $insLogin->execute([':acc'=>$newAcc, ':uid'=>$userId, ':pwd'=>$passwordToStore]);

        $insEmp->execute([
          ':acc'=>$newAcc, ':name'=>$empName, ':dept_id'=>$deptId, ':sort'=>$sort,
          ':status'=>$status ?? 2, ':plan'=>$plan ?? '', ':comment'=>$comment ?? '',
          ':lid'=>$locationId
        ]);

        $uidCache[$userId] = $newAcc;
        $inserted++;
      }
    }
  }

  $pdo->commit();

  echo json_encode([
    'ok'               => true,
    'updated'          => $updated,
    'inserted'         => $inserted,
    'deleted'          => $deleted,
    'skipped'          => $skipped,
    'new_departments'  => $newDepartments,
    'debug_updated_ids'=> $updatedIds,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'internal error']);
  error_log('[import_csv.php] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
}
