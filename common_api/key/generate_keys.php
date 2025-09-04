<?php
// common_api/key/generate_keys.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';  // getDbConnection()

// 使い方（任意のロール/有効期限日数をCLI引数で指定可）
// php generate_keys.php <role=default> <expires_days=365>

function uuid_v4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0F) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3F) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$role = $argv[1] ?? 'default';
$expiresDays = (int)($argv[2] ?? 365);

$dir = __DIR__;
$privateKeyFile = $dir . '/private.pem';
$publicKeyFile  = $dir . '/public.pem';

// すでに鍵が存在する場合は終了（必要ならここでDBチェックを追加）
if (file_exists($privateKeyFile) && file_exists($publicKeyFile)) {
    echo "鍵は既に存在します。\n";
    exit(0);
}

// RSA鍵ペアを生成
$keys = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($keys === false) {
    fwrite(STDERR, "OpenSSL: 鍵生成に失敗\n");
    exit(1);
}

// 秘密鍵をエクスポート（パスフレーズ不要なら第2引数のみ）
$privateKey = '';
if (!openssl_pkey_export($keys, $privateKey)) {
    fwrite(STDERR, "OpenSSL: 秘密鍵エクスポートに失敗\n");
    exit(1);
}

// 公開鍵を取得
$details = openssl_pkey_get_details($keys);
if ($details === false || empty($details['key'])) {
    fwrite(STDERR, "OpenSSL: 公開鍵取得に失敗\n");
    exit(1);
}
$publicKey = $details['key'];

// ファイルに保存（パーミッションは必要に応じて調整）
file_put_contents($privateKeyFile, $privateKey);
file_put_contents($publicKeyFile, $publicKey);

// ===== DBにメタ情報を保存 =====
$pdo = getDbConnection();

$id   = uuid_v4();                          // 管理ID
$keyId = bin2hex(random_bytes(8));          // KID（16桁hex）
$now = new DateTimeImmutable('now');
$expiresAt = $expiresDays > 0 ? $now->modify("+{$expiresDays} days") : null;

$alg = 'RS256';
$use = 'sig';
$status = 'active';

// 削除やローテ用の“管理用シークレット”を一度だけ払い出し、ハッシュを保存
$oneTimeSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$secretHash = password_hash($oneTimeSecret, PASSWORD_DEFAULT); // 可能なら PASSWORD_ARGON2ID

$sql = "INSERT INTO jwt_key_registry
        (id, key_id, role, issued_at, status, is_revoked, revoked_at, scheduled_deletion_at,
         secret_hash, alg, `use`, expires_at)
        VALUES
        (:id, :key_id, :role, :issued_at, :status, 0, NULL, NULL,
         :secret_hash, :alg, :use, :expires_at)";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id'          => $id,
    ':key_id'      => $keyId,
    ':role'        => $role,
    ':issued_at'   => $now->format('Y-m-d H:i:s'),
    ':status'      => $status,
    ':secret_hash' => $secretHash,
    ':alg'         => $alg,
    ':use'         => $use,
    ':expires_at'  => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
]);

echo "鍵ペアを生成・保存しました。\n";
echo "key_id: {$keyId}\n";
echo "alg/use: {$alg}/{$use}\n";
echo "role: {$role}\n";
echo "issued_at: ".$now->format(DATE_ATOM)."\n";
echo "expires_at: ".($expiresAt ? $expiresAt->format(DATE_ATOM) : '(null)')."\n";
echo "管理用シークレット（保存してください。DBにはハッシュのみ保存）:\n{$oneTimeSecret}\n";
