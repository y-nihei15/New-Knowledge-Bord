<?php
declare(strict_types=1);

/*
 * logout.php — RS256検証でJWTを確認し、論理ログアウト
 * 依存:
 *   - ../key/public.pem : RS256 公開鍵（PEM）
 * 注意:
 *   - 画面にはJSON以外を出さない（BOM/echo禁止）
 */

ini_set('display_errors','0');
ini_set('log_errors','1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // 本番は限定
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$LOGIN_URL = '../../main/loginScreen.php';

if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method === 'GET') {
    // 画面アクセス時はそのまま遷移（APIとしては使わない想定）
    header('Location: ' . $LOGIN_URL, true, 303);
    exit;
}

/* ====== ユーティリティ ====== */
function b64u_decode(string $b64u): string {
    $b64 = strtr($b64u, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) $b64 .= str_repeat('=', 4 - $pad);
    $out = base64_decode($b64, true);
    if ($out === false) throw new RuntimeException('base64url decode failed');
    return $out;
}

/**
 * RS256 で JWT を検証して payload(array) を返す
 */
function verify_jwt_rs256(string $jwt, string $publicPem): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('invalid token format');

    [$encHeader, $encPayload, $encSig] = $parts;
    $headerJson  = b64u_decode($encHeader);
    $payloadJson = b64u_decode($encPayload);
    $sigBin      = b64u_decode($encSig);

    $header = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
    $payload= json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

    if (($header['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('unsupported alg');
    }

    $pub = openssl_pkey_get_public($publicPem);
    if ($pub === false) throw new RuntimeException('public key load failed');

    $ok = openssl_verify($encHeader.'.'.$encPayload, $sigBin, $pub, OPENSSL_ALGO_SHA256);
    openssl_pkey_free($pub);
    if ($ok !== 1) throw new RuntimeException('signature verify failed');

    return $payload;
}

/* ====== トークンの取り出し ====== */
$token = '';
$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (stripos($auth, 'Bearer ') === 0) {
    $token = trim(substr($auth, 7));
} elseif (isset($_POST['token'])) {
    $token = (string)$_POST['token'];
}
if ($token === '') {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'missing token','redirect'=>$LOGIN_URL], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ====== 検証 → レスポンス ====== */
try {
    // 公開鍵で検証（RS256）
    $publicPemPath = __DIR__ . '/../key/public.pem';
    $publicPem = @file_get_contents($publicPemPath);
    if ($publicPem === false) throw new RuntimeException('public key not found');

    $pl = verify_jwt_rs256($token, $publicPem);

    // ログイン側の発行仕様に合わせて payload を検査
    $now = time();

    // 必須クレーム
    $required = ['iss','sub','aud','iat','nbf','exp','account_id','role'];
    foreach ($required as $k) {
        if (!array_key_exists($k, $pl)) {
            throw new RuntimeException('invalid token payload');
        }
    }
    // 値の妥当性
    if (!is_string($pl['iss']) || !is_string($pl['sub']) || !is_string($pl['aud'])) {
        throw new RuntimeException('invalid token payload');
    }
    if (!is_int($pl['iat']) || !is_int($pl['nbf']) || !is_int($pl['exp'])) {
        throw new RuntimeException('invalid token payload');
    }
    if ($pl['nbf'] > $now || $pl['exp'] <= $now) {
        throw new RuntimeException('token expired or not yet valid');
    }
    // 受信者チェック（必要に応じて固定）
    if ($pl['aud'] !== 'web-client') {
        throw new RuntimeException('aud mismatch');
    }
    // 発行者チェック（必要に応じて固定）
    if ($pl['iss'] !== 'login.php') {
        throw new RuntimeException('iss mismatch');
    }

    // サーバー側でセッション/DBの無効化が必要な場合はここで実施
    // 例) ブラックリストに jti を入れる…など。今回は stateless のため何もしない。

    // 成功（クライアント側はトークンを破棄して遷移）
    http_response_code(200);
    echo json_encode(['ok'=>true,'status'=>'success','redirect'=>$LOGIN_URL], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    // 失敗時
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Invalid token payload','redirect'=>$LOGIN_URL], JSON_UNESCAPED_UNICODE);
    exit;
}
