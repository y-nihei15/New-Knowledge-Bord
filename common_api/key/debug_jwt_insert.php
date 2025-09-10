<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors','1');

require_once __DIR__.'/jwt_signatures_repo.php';
require_once __DIR__.'/../config/db.php'; // getDbConnection() がある前提

$pdo = getDbConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "[DB] {$db}\n";

$pub = __DIR__.'/public.pem';
if (!is_file($pub)) { throw new RuntimeException("public.pem not found: {$pub}"); }

$keyId = 'manual-'.bin2hex(random_bytes(4));
$repo  = new JwtSignaturesRepo($pdo);

$repo->insertSignatureRow($keyId, $pub, 'users');
echo "[OK] inserted key_id={$keyId}\n";

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`jwt_signatures`")->fetchColumn();
echo "[COUNT] {$cnt}\n";
