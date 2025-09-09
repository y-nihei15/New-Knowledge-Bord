<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');

require_once __DIR__.'/jwt_signatures_repo.php';
require_once __DIR__.'/../config/db.php';

// 既存の接続を使う（例：getDbConnectionがある前提。無ければ既存DSNでnew PDO）
$pdo = getDbConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// どのDBに繋がっているか明示
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "[DB] {$db}\n";

// 既存の public.pem からハッシュ作成（材料が無ければ例外にする）
$pub = __DIR__.'/public.pem';
if (!is_file($pub)) { throw new RuntimeException("public.pem not found: {$pub}"); }

$keyId = 'manual-'.bin2hex(random_bytes(4));  // テスト用の一時ID
$repo  = new JwtSignaturesRepo($pdo);

// 実INSERT（`use` は予約語なのでバッククォート対応済み）
$repo->insertSignatureRow($generatedKeyId, $publicPem, 'users'); // これで1行入るはず
echo "[OK] inserted key_id={$keyId}\n";

// 同接続で確認
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`jwt_signatures`")->fetchColumn();
echo "[COUNT] {$cnt}\n";
