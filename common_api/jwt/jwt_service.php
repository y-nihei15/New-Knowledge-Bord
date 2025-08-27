<?php
declare(strict_types=1);

// // 鍵の読み込み
// require_once __DIR__ . '/../key/key_loader.php';
// $keys = require __DIR__ . '/../key/key_loader.php';

// // ライブラリ利用
// use Firebase\JWT\JWT;
// use Firebase\JWT\Key;

// 依存: config/db.php, utils/response.php（任意）, このファイルはヘルパ群
require_once __DIR__ . '/../config/db.php';

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/'));
}
function hmac_sign(string $msg, string $secret): string {
    return hash_hmac('sha256', $msg, $secret, true);
}

function jwt_generate(array $claims, array $cfg): string {
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $segments = [
        base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES)),
        base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES)),
    ];
    $signature = base64url_encode(hmac_sign(implode('.', $segments), $cfg['secret']));
    return implode('.', [...$segments, $signature]);
}

function jwt_parse(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $header  = json_decode(base64url_decode($h), true);
    $payload = json_decode(base64url_decode($p), true);
    $sig     = $s;
    return ['header'=>$header,'payload'=>$payload,'signature'=>$sig,'signing_input'=>"$h.$p"];
}

function jwt_verify(string $jwt, array $cfg): ?array {
    $parsed = jwt_parse($jwt);
    if (!$parsed) return null;
    $expected = base64url_encode(hmac_sign($parsed['signing_input'], $cfg['secret']));
    if (!hash_equals($expected, $parsed['signature'])) return null;
    $now = time();
    $p = $parsed['payload'];
    if (($p['iss']??null)!==$cfg['issuer']) return null;
    if (($p['aud']??null)!==$cfg['audience']) return null;
    if (($p['nbf']??0) > $now) return null;
    if (($p['iat']??0) > $now) return null;
    if (($p['exp']??0) <= $now) return null;
    return $p;
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// === DB関連（jwt_tokens 管理）===
// テーブル: jwt_tokens（id, jwt_id, account_id, is_manually_revoked, issued_at, expires_at, updated_at）
// ・発行時：INSERT（is_manually_revoked=0, issued_at=NOW, expires_at=+90日）
// ・失効時：UPDATE is_manually_revoked=1, updated_at=NOW
function jwt_db_insert(PDO $pdo, string $jwtId, int $accountId, DateTime $issuedAt, DateTime $expiresAt): void {
    $sql = "INSERT INTO jwt_tokens (jwt_id, account_id, is_manually_revoked, issued_at, expires_at, updated_at)
            VALUES (:jwt_id, :account_id, 0, :issued_at, :expires_at, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':jwt_id' => $jwtId,
        ':account_id' => $accountId,
        ':issued_at' => $issuedAt->format('Y-m-d H:i:s'),
        ':expires_at'=> $expiresAt->format('Y-m-d H:i:s'),
    ]);
}

function jwt_db_revoke(PDO $pdo, string $jwtId): bool {
    $sql = "UPDATE jwt_tokens SET is_manually_revoked=1, updated_at=NOW() WHERE jwt_id=:jwt_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':jwt_id' => $jwtId]);
    return $stmt->rowCount() > 0;
}

function jwt_db_is_revoked_or_expired(PDO $pdo, string $jwtId): bool {
    $sql = "SELECT is_manually_revoked, expires_at FROM jwt_tokens WHERE jwt_id=:jwt_id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':jwt_id'=>$jwtId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return true; // 未登録＝無効扱い
    if ((int)$row['is_manually_revoked'] === 1) return true;
    return strtotime($row['expires_at']) <= time();
}

