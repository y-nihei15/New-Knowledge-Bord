<?php
declare(strict_types=1);

ini_set('display_errors', '0'); // API応答がJSONなので画面出力はNG
ini_set('log_errors', '1');     // 代わりにエラーログへ

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../jwt/jwt_service.php';
$jwt_cfg = require __DIR__ . '/../jwt/jwt_config.php';
require_once __DIR__ . '/../key/key_loader.php'; // CORS関連の $allowCreds/$reqOrigin/$allowOrigin を想定

// --- CORS ---
error_log("login-1\n", 3, "/var/www/html/logs/hayashi.log");
if (isset($allowCreds, $reqOrigin) && $allowCreds && $reqOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $reqOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
} else {
    $allowOrigin = $allowOrigin ?? '*';
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
}
error_log("login-2\n", 3, "/var/www/html/logs/hayashi.log");
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// プリフライト即返し
error_log("login-3\n", 3, "/var/www/html/logs/hayashi.log");
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 直アクセスは画面へ
error_log("login-4\n", 3, "/var/www/html/logs/hayashi.log");
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Location: ../../main/loginScreen.php');
    exit;
}

error_log("login-5\n", 3, "/var/www/html/logs/hayashi.log");
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

/* ===== 入力（フォーム or JSON 両対応。※フロントはフォーム送信） ===== */
error_log("login-6\n", 3, "/var/www/html/logs/hayashi.log");
$ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$userId = '';
$password = '';
if (is_string($ctype) && stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true) ?: [];
    $userId  = (string)($data['user_id'] ?? '');
    $password = (string)($data['password'] ?? '');
} else {
    $userId  = (string)($_POST['user_id'] ?? '');
    $password = (string)($_POST['password'] ?? '');
}

error_log("login-7\n", 3, "/var/www/html/logs/hayashi.log");
if ($userId === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'IDとパスワードを入力してください']);
    exit;
}

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    error_log("login-8\n", 3, "/var/www/html/logs/hayashi.log");

    // 認証用：まずはハッシュ検証のため password を取得
    $stmt = $pdo->prepare('SELECT user_id, password FROM login_info WHERE user_id = :id LIMIT 1');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("login-9\n", 3, "/var/www/html/logs/hayashi.log");

    if (!$row) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'ユーザー未存在']);
        exit;
    }

    $stored = rtrim((string)$row['password']); // CHAR列の右詰め空白対策
    $ok = false;

    $info = password_get_info($stored);
    $recognizedHash = ($stored !== '' && ($info['algo'] ?? 0) !== 0);

    if ($recognizedHash) {
        // ハッシュ検証
        $ok = password_verify($password, $stored);
        if ($ok && password_needs_rehash($stored, PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id')
                ->execute([':new' => $newHash, ':id' => $userId]);
        }
    } else {
        // 平文からの移行
        if ($stored !== '' && hash_equals($stored, $password)) {
            $ok = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id')
                ->execute([':new' => $newHash, ':id' => $userId]);
        }
    }

    error_log("login-10\n", 3, "/var/www/html/logs/hayashi.log");

    if (!$ok) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'パスワード不一致']);
        exit;
    }

    /* ===== JWT発行（ログイン時のみ user_type を含める） ===== */

    // ★ ここで account_id と user_type を取得（必須）
    $stmt = $pdo->prepare('SELECT account_id, user_type FROM login_info WHERE user_id = :id LIMIT 1');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("login-11\n", 3, "/var/www/html/logs/hayashi.log");

    $accountId = isset($row['account_id']) ? (int)$row['account_id'] : 0;
    $userType  = isset($row['user_type'])  ? (int)$row['user_type']  : 0;

    if ($accountId <= 0) {
        throw new RuntimeException('account_id を取得できませんでした。');
    }

    $now = time();
    $ttl = max(300, (int)($jwt_cfg['ttl_seconds'] ?? 3600));
    $exp = $now + $ttl;
    $jti = function_exists('uuid_v4') ? uuid_v4() : bin2hex(random_bytes(16));

    // ログイン時のみ user_type を JWT に含める
    $claims = [
        'iss'        => $jwt_cfg['issuer'],
        'aud'        => $jwt_cfg['audience'],
        'sub'        => $userId,
        'account_id' => $accountId,
        'user_type'  => $userType, // ★ ここが今回の追加
        'jti'        => $jti,
        'iat'        => $now,
        'nbf'        => $now,
        'exp'        => $exp,
    ];

    error_log("login-12\n", 3, "/var/www/html/logs/hayashi.log");

    $token = jwt_generate($claims, $jwt_cfg);

    error_log("login-13\n", 3, "/var/www/html/logs/hayashi.log");

    // DB監査テーブルへ登録
    $issuedAt  = (new DateTime('@' . $now))->setTimezone(new DateTimeZone('UTC'));
    $expiresAt = (new DateTime('@' . $exp))->setTimezone(new DateTimeZone('UTC'));
    jwt_db_insert($pdo, $jti, (int)$accountId, $issuedAt, $expiresAt);

    error_log("login-14\n", 3, "/var/www/html/logs/hayashi.log");

    // 応答
    echo json_encode([
        'ok'    => true,
        'token' => $token,
        'exp'   => $exp,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    require_once __DIR__ . '/../key/generate_keys.php';
    exit;


} catch (Throwable $e) {
    error_log('[auth/login] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'システムエラー']);
    exit;
}
