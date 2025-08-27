<?php
// common_api/key/generate_keys.php
declare(strict_types=1);

$dir = __DIR__;
$privateKeyFile = $dir . '/private.pem';
$publicKeyFile  = $dir . '/public.pem';

// すでに鍵が存在する場合は作成しない
if (file_exists($privateKeyFile) && file_exists($publicKeyFile)) {
    echo "鍵は既に存在します。\n";
    exit;
}

// RSA鍵ペアを生成
$keys = openssl_pkey_new([
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
]);

// 秘密鍵をエクスポート
openssl_pkey_export($keys, $privateKey);

// 公開鍵を取得
$details = openssl_pkey_get_details($keys);
$publicKey = $details['key'];

// ファイルに保存
file_put_contents($privateKeyFile, $privateKey);
file_put_contents($publicKeyFile, $publicKey);

echo "鍵ペアを生成しました。\n";
