<?php
declare(strict_types=1);

/**
 * IP一致なら JWT を発行して main.php?token=... に 302
 * - Cookie/$_SESSION は使わない（main 側で JS が受け取る）
 * - LB/リバプロ経由を想定し、信頼プロキシのときのみ X-Forwarded-For 先頭を採用
 * - 許可IPは ipaddress_mst の複数行から完全一致で照合
 */

ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log','/tmp/php-jwt.log');

const BASE_URL  = '/bbs/testmain/';
const MAIN_URL  = BASE_URL . 'main/main.php';
const LOGIN_URL = BASE_URL . 'main/loginScreen.php';

/** ===== 信頼できるプロキシ(LB)の送信元IP/CIDRを列挙（環境に合わせて調整） ===== */
const TRUSTED_PROXIES = [
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
    // 例: 固定の社内リバプロ/ALBがあれば追加
    // '203.0.113.10/32',
];

/** CIDR 含有判定 (IPv4) */
function ipInCidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) $cidr .= '/32';
    [$subnet, $mask] = explode('/', $cidr, 2);
    $ipl = ip2long($ip);
    $subnetl = ip2long($subnet);
    $maskl = -1 << (32 - (int)$mask);
    return ($ipl & $maskl) === ($subnetl & $maskl);
}

/** REMOTE_ADDR が信頼プロキシか? */
function isTrustedProxy(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    foreach (TRUSTED_PROXIES as $cidr) {
        if (ipInCidr($ip, $cidr)) return true;
    }
    return false;
}

/** クライアント実IP: 信頼プロキシ経由なら XFF 先頭、そうでなければ REMOTE_ADDR */
function client_ip_v4(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $remote = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $remote : '';

    if ($remote !== '' && isTrustedProxy($remote)) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $first;
            }
        }
    }
    return $remote !== '' ? $remote : '0.0.0.0';
}

/** ===== DB接続 ===== */
$base = dirname(__DIR__); // .../common_api
$ret = @require $base . '/config/db.php';
$pdo = ($ret instanceof PDO) ? $ret : (function_exists('getDbConnection') ? getDbConnection() : null);
if (!$pdo instanceof PDO) {
    header('Location: ' . LOGIN_URL, true, 302);
    exit;
}

/** ===== 許可IP照合（複数行対応 / 完全一致） ===== */
$clientIp = client_ip_v4();

$stmt = $pdo->prepare("SELECT 1 FROM ipaddress_mst WHERE TRIM(ipaddress) = :ip LIMIT 1");
$stmt->execute([':ip' => $clientIp]);
$ipOk = (bool)$stmt->fetchColumn();

// デバッグが必要なら一時的にヘッダ出力（本番では外すかコメントアウト）
header('X-Debug-Remote-Addr: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
header('X-Debug-XFF: ' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '(none)'));
header('X-Debug-Client-Ip: ' . $clientIp);

if (!$ipOk) {
    header('Location: ' . LOGIN_URL, true, 302);
    exit;
}

/** ===== JWTサービス読込 ===== */
$svcPath = __DIR__ . '/../jwt/jwt_service.php';
$cfgPath = __DIR__ . '/../jwt/jwt_config.php';
if (!is_readable($svcPath) || !is_readable($cfgPath)) {
    header('Location: ' . LOGIN_URL, true, 302);
    exit;
}
require_once $svcPath;
/** @var array $cfg */
$cfg = require $cfgPath;

/** ===== JWT発行 → main.php?token=... に 302 ===== */
try {
    $now = time();
    $ttl = max(300, (int)($cfg['ttl_seconds'] ?? 3600));
    $exp = $now + $ttl;
    $jti = function_exists('uuid_v4') ? uuid_v4() : bin2hex(random_bytes(16));

    $claims = [
        'iss' => $cfg['issuer'],
        'aud' => $cfg['audience'],
        'iat' => $now,
        'nbf' => $now,
        'exp' => $exp,
        'jti' => $jti,
        'sub' => 'ip_gate_user',
        'ip'  => $clientIp,
    ];

    $jwt = jwt_generate($claims, $cfg);

    if (function_exists('jwt_db_insert')) {
        jwt_db_insert($pdo, $jti, 0, (new DateTime("@$now")), (new DateTime("@$exp")));
    }

   header('Location: ' . MAIN_URL . '?token=' . $jwt, true, 302);
    exit;

} catch (Throwable $e) {
    // 失敗時はログイン画面へフォールバック（必要なら X-JWT-Error を一時的に出す）
    // header('X-JWT-Error: ' . $e->getMessage());
    header('Location: ' . LOGIN_URL, true, 302);
    exit;
}
