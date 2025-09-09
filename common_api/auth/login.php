<?php
declare(strict_types=1);

/*
 * login.php — 安全なレスポンス・正しいHTTPコード・JWT(RS256)発行
 * 依存:
 *   - ../config/db.php         : getDbConnection(): PDO を返す
 *   - ../key/private.pem       : RSA秘密鍵（PEM）
 *   - ../key/public.pem        : RSA公開鍵（PEM）※ここでは読み込みのみ
 * 注意:
 *   - このファイルおよび require 先は UTF-8 (BOMなし)
 *   - 余計な echo/var_dump/終了タグ後の空白は厳禁
 */

////////////////////////////////////////////////////////////////
// 1) 出力/エラー設定（画面にはJSON以外を出さない）
////////////////////////////////////////////////////////////////
ini_set('display_errors', '0');          // 画面には出さない
ini_set('log_errors', '1');              // エラーログへ
error_reporting(E_ALL);
if (function_exists('ob_get_level') && ob_get_level() > 0) {
    // 既にどこかで出力されていてもクリア
    @ob_clean();
}
header('Content-Type: application/json; charset=utf-8');

////////////////////////////////////////////////////////////////
// 2) 依存ファイル
////////////////////////////////////////////////////////////////
require_once __DIR__ . '/../config/db.php';

////////////////////////////////////////////////////////////////
// 3) CORS（未定義変数を避け、デフォルトを明示）
////////////////////////////////////////////////////////////////
$allowCreds  = false;   // 必要なら true に
$allowOrigin = '*';     // 本番は厳格に限定
$reqOrigin   = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';

if ($allowCreds && $reqOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $reqOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// プリフライトは204で即返し
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 直アクセスは画面へ（必要なければ削除可）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Location: ../../main/loginScreen.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

////////////////////////////////////////////////////////////////
// 4) 入力（JSON/フォーム両対応）
////////////////////////////////////////////////////////////////
$ctype    = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$userId   = '';
$password = '';

if (is_string($ctype) && stripos($ctype, 'application/json') !== false) {
    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true) ?: [];
    $userId   = (string)($data['user_id'] ?? '');
    $password = (string)($data['password'] ?? '');
} else {
    $userId   = (string)($_POST['user_id'] ?? '');
    $password = (string)($_POST['password'] ?? '');
}

if ($userId === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'IDとパスワードを入力してください'], JSON_UNESCAPED_UNICODE);
    exit;
}

////////////////////////////////////////////////////////////////
// 5) ヘルパ（JWT: base64url, 署名, 生成）
////////////////////////////////////////////////////////////////
/**
 * base64url エンコード（=,+,/ を -,_ に、末尾=削除）
 */
function b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * RS256 で署名
 */
function sign_rs256(string $message, $privateKey): string {
    $signature = '';
    if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('JWT署名に失敗しました');
    }
    return $signature;
}

/**
 * RS256 JWT 生成
 */
function create_jwt_rs256(array $payload, string $privatePem): string {
    $header = ['typ' => 'JWT', 'alg' => 'RS256'];

    $pk = openssl_pkey_get_private($privatePem);
    if ($pk === false) {
        throw new RuntimeException('秘密鍵を読み込めません（PEM形式を確認）');
    }

    $encHeader  = b64u(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encPayload = b64u(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signing    = $encHeader . '.' . $encPayload;
    $signature  = sign_rs256($signing, $pk);
    openssl_pkey_free($pk);

    return $signing . '.' . b64u($signature);
}

////////////////////////////////////////////////////////////////
// 6) 認証 → JWT 発行
////////////////////////////////////////////////////////////////
try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ユーザー取得（account_id も返す前提）
    $stmt = $pdo->prepare('SELECT user_id, password, account_id FROM login_info WHERE user_id = :id LIMIT 1');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
 
    if (!$row) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'ユーザー未存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stored = (string)$row['password'];
    $stored = rtrim($stored); // CHAR列の右詰め空白対策
    $ok     = false;

    // password_hash 系かどうか
    $info            = password_get_info($stored);
    $recognizedHash  = ($stored !== '' && ($info['algo'] ?? 0) !== 0);

    if ($recognizedHash) {
        // bcrypt/argon 等
        $ok = password_verify($password, $stored);

        if ($ok && password_needs_rehash($stored, PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUp = $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id');
            $stmtUp->execute([':new' => $newHash, ':id' => $userId]);
        }
    } else {
        // 平文保存だった場合の移行（完全一致ならbcryptへ置換）
        if ($stored !== '' && hash_equals($stored, $password)) {
            $ok = true;
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id')
                ->execute([':new' => $newHash, ':id' => $userId]);
        }
    }
 
    if (!$ok) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'パスワード不一致'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- 認証OK：JWT 発行 ----
    $accountId = (string)($row['account_id'] ?? '');
    $now = time();
    $exp = $now + 60 * 60 * 12; // 12時間（必要に応じて変更）

    // 鍵を読み込み（key_loader.php を使わずファイル直接）
    $privatePemPath = __DIR__ . '/../key/private.pem';
    $publicPemPath  = __DIR__ . '/../key/public.pem';
    $privateKeyPem  = @file_get_contents($privatePemPath);
    $publicKeyPem   = @file_get_contents($publicPemPath);
    if ($privateKeyPem === false || $publicKeyPem === false) {
        throw new RuntimeException('鍵ファイルを読み込めません（private.pem/public.pem）');
    }

    // ペイロード
    $payload = [
        'iss' => 'login.php',     // 発行者
        'sub' => $userId,         // 対象
        'aud' => 'web-client',    // 受信者（任意）
        'iat' => $now,            // 発行時刻
        'nbf' => $now,            // これより前は無効
        'exp' => $exp,            // 失効
        // アプリ固有クレーム
        'account_id' => $accountId,
        'role'       => 'user',
    ];

    $token = create_jwt_rs256($payload, $privateKeyPem);

    // 成功レスポンス（JSON のみ）
    http_response_code(200);
    echo json_encode([
        'ok'    => true,
        'token' => $token,
        'exp'   => $exp,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    error_log('[auth/login] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'システムエラー'], JSON_UNESCAPED_UNICODE);
    exit;
}