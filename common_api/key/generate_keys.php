<?php
declare(strict_types=1);

$dir = __DIR__;
$privatePem = $dir . DIRECTORY_SEPARATOR . 'private.pem';
$publicPem  = $dir . DIRECTORY_SEPARATOR . 'public.pem';
$cnfPath    = $dir . DIRECTORY_SEPARATOR . 'openssl.cnf';

// 空ファイルがあったら消しておく
foreach ([$privatePem, $publicPem] as $f) {
    if (file_exists($f) && filesize($f) === 0) { @unlink($f); }
}

// 1) openssl.cnf を用意
if (!file_exists($cnfPath)) {
    $cnf = <<<CNF
openssl_conf = openssl_init
[openssl_init]
providers = provider_sect
alg_section = algorithm_sect
[provider_sect]
base = base_sect
default = default_sect
[base_sect]
activate = 1
[default_sect]
activate = 1
[algorithm_sect]
rsa = rsa_sect
[rsa_sect]
rsa_pss_saltlen = -1
CNF;
    file_put_contents($cnfPath, $cnf);
}

function dump_openssl_errors(string $prefix = 'OpenSSL'): void {
    $errs = [];
    while ($e = openssl_error_string()) { $errs[] = $e; }
    if ($errs) fwrite(STDERR, $prefix . " errors:\n  - " . implode("\n  - ", $errs) . "\n");
}

// 2) 鍵生成
$conf = [
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
    "private_key_bits" => 2048,
    "digest_alg"       => "sha256",
    "config"           => $cnfPath,
];
$res = openssl_pkey_new($conf);
if ($res === false) {
    dump_openssl_errors("OpenSSL pkey_new");
    exit(1);
}
if (!openssl_pkey_export($res, $privPemOut, null, ["config" => $cnfPath])) {
    dump_openssl_errors("OpenSSL export");
    exit(1);
}
$details = openssl_pkey_get_details($res);
if ($details === false || empty($details['key'])) {
    dump_openssl_errors("OpenSSL details");
    exit(1);
}
$pubPemOut = $details['key'];

// 書き出し
file_put_contents($privatePem, $privPemOut);
file_put_contents($publicPem, $pubPemOut);
echo "Generated private.pem & public.pem in {$dir}\n";

// ここから DB 登録処理
require_once __DIR__ . '/jwt_signatures_repo.php';
require_once __DIR__ . '/../config/db.php'; // ← getDbConnection() を提供するファイル

$pdo = getDbConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "[DB] {$dbName}\n";

// 署名鍵ID（例: ランダム生成。既存の仕様に合わせてください）
$generatedKeyId = 'key-'.bin2hex(random_bytes(4));

$repo = new JwtSignaturesRepo($pdo);
try {
    $repo->insertSignatureRow($generatedKeyId, $publicPem, 'users');
    echo "[OK] inserted key_id={$generatedKeyId}\n";
} catch (Throwable $e) {
    error_log('[JWT INSERT ERROR] '.$e->getMessage());
    echo "[JWT INSERT ERROR] ".$e->getMessage()."\n";
}
