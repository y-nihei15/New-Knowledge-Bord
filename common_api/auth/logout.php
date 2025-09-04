<?php
declare(strict_types=1);

ini_set('display_errors','0');
ini_set('log_errors','1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$LOGIN_URL = '../../main/loginScreen.php';

if ($method === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../jwt/require_auth.php';
require_once __DIR__ . '/../config/db.php';

try {
    // ★Bearer必須。失敗時は require_auth() 内で 401 JSON を返して終了
    $claims = require_auth();
    $jti = (string)($claims['jwt_id'] ?? '');
    if ($jti === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'missing_jti','redirect'=>$LOGIN_URL], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 失効（DB）
    $pdo = getDbConnection();
    if (function_exists('jwt_db_revoke')) {
        jwt_db_revoke($pdo, $jti);                // ← あなたの実装名に合わせて
    } else {
        // フォールバック（テーブル名等は環境依存なので try-catch）
        try {
            $pdo->prepare('UPDATE jwt_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE jti = :jti')
                ->execute([':jti'=>$jti]);
        } catch (Throwable $e) { /* no-op */ }
    }

    // Cookie運用していない想定だが、設定があれば削除
    $cfg = require __DIR__ . '/../jwt/jwt_config.php';
    if (!empty($cfg['cookie_name'])) {
        setcookie($cfg['cookie_name'], '', [
            'expires'  => time()-3600,
            'path'     => $cfg['cookie_path'] ?? '/',
            'domain'   => $cfg['cookie_domain'] ?? '',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => $cfg['cookie_samesite'] ?? 'Lax',
        ]);
    }

    if ($method === 'GET') {
        // 直アクセスならサーバ側で即遷移
        header('Location: ' . $LOGIN_URL, true, 303);
        exit;
    }

    // fetch呼び出し用のJSON（クライアントで遷移させる）
    echo json_encode(['ok'=>true,'status'=>'success','redirect'=>$LOGIN_URL], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    // 無効トークン等 → ログインに戻す
    if ($method === 'GET') {
        header('Location: ' . $LOGIN_URL, true, 303);
        exit;
    }
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized','redirect'=>$LOGIN_URL], JSON_UNESCAPED_UNICODE);
    exit;
}
