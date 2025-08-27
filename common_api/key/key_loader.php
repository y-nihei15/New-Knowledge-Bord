<?php
// common_api/key/key_loader.php
declare(strict_types=1);

$privateKeyFile = __DIR__ . '/private.pem';
$publicKeyFile  = __DIR__ . '/public.pem';

if (!file_exists($privateKeyFile) || !file_exists($publicKeyFile)) {
    throw new RuntimeException("鍵ファイルが存在しません。先に generate_keys.php を実行してください。");
}

return [
    'private' => file_get_contents($privateKeyFile),
    'public'  => file_get_contents($publicKeyFile),
];
