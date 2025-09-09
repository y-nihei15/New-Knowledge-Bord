<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../common_api/config/db.php';      // PDO生成：$pdo を返す想定
require_once __DIR__ . '/../common_api/config/import.php';  // ↑で定義した定数

try {
    // メソッドチェック
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    // ① 画面からアップロードがあれば優先（任意）
    $csvPath = null;
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $csvPath = $_FILES['file']['tmp_name'];
    } else {
        // ② 未指定なら固定CSV
        $csvPath = DEFAULT_CSV_PATH;
    }
    if (!is_readable($csvPath)) {
        throw new RuntimeException('CSVが読み取れません: ' . $csvPath);
    }

    // 読み込み（UTF-8化 & BOM除去）
    $raw = file_get_contents($csvPath);
    if ($raw === false) {
        throw new RuntimeException('CSVの読み込みに失敗しました');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM
    $enc = mb_detect_encoding($raw, ['UTF-8','SJIS-win','CP932','EUC-JP','ISO-2022-JP'], true) ?: 'UTF-8';
    if ($enc !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $enc);

    // CSVとして読み直す
    $temp = fopen('php://temp', 'rb+');
    fwrite($temp, $raw);
    rewind($temp);

    $rows = [];
    $line = 0;
    while (($cols = fgetcsv($temp)) !== false) {
        $line++;
        // 空行スキップ
        if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) continue;

        // 列数をマッピング数に合わせる
        $cols = array_pad($cols, count(CSV_COLUMNS), '');
        $record = array_combine(CSV_COLUMNS, array_map(fn($v)=>trim((string)$v), $cols));
        $record['_line'] = $line;
        $rows[] = $record;
    }
    fclose($temp);

    // DB保存
    $pdo = getDbConnection(); // ★db.phpにある想定（関数名はプロジェクトに合わせて修正可）
    $pdo->beginTransaction();
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        importTable,
        implode(',', CSV_COLUMNS),
        implode(',', array_fill(0, count(CSV_COLUMNS), '?'))
    );
    $stmt = $pdo->prepare($sql);

    $inserted = 0;
    $errors = [];
    foreach ($rows as $r) {
        // 必須バリデーション（部署名・名前）
        if (($r['department'] ?? '') === '' || ($r['name'] ?? '') === '') {
            $errors[] = "行{$r['_line']}：部署名または名前が空です";
            continue;
        }
        try {
            $stmt->execute(array_map(fn($k)=>$r[$k] ?? null, CSV_COLUMNS));
            $inserted++;
        } catch (Throwable $e) {
            $errors[] = "行{$r['_line']}：DB登録エラー - " . $e->getMessage();
        }
    }
    $pdo->commit();

    echo json_encode(['ok' => true, 'inserted' => $inserted, 'skipped' => count($errors), 'errors' => $errors], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
