<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../common_api/jwt/jwt_service.php';
$jwt_cfg = require __DIR__ . '/../common_api/jwt/jwt_config.php';

/* ===== CORS（同一オリジンなら気にしなくてOK。将来のために置いておく） ===== */
$allowOrigin = $jwt_cfg['allow_origin'] ?? '*';
$allowCreds  = !empty($jwt_cfg['use_cookie']);
header('Access-Control-Allow-Origin: ' . $allowOrigin);
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($allowCreds) header('Access-Control-Allow-Credentials: true');

// プリフライト即返し
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 直アクセスは画面へ
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Location: ../../main/loginScreen.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

/* ===== 入力（フォーム or JSON 両対応。※フロントはフォーム送信） ===== */
$ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$userId = ''; $password = '';
if (is_string($ctype) && stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true) ?: [];
    $userId  = (string)($data['user_id'] ?? '');
    $password = (string)($data['password'] ?? '');
} else {
    $userId  = (string)($_POST['user_id'] ?? '');
    $password = (string)($_POST['password'] ?? '');
}

if ($userId === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'IDとパスワードを入力してください']);
    exit;
}

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 数値キャストしない（英数字IDOK）
    $stmt = $pdo->prepare('SELECT user_id, password FROM login_info WHERE user_id = :id LIMIT 1');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'ユーザー未存在']);
        exit;
    }

    $stored = (string)$row['password'];
    $ok = false;

    // ハッシュならverify、平文なら一致→ハッシュ移行
    if ($stored !== '' && password_get_info($stored)['algo'] !== 0) {
        $ok = password_verify($password, $stored);
    } else {
        if (hash_equals($stored, $password)) {
            $ok = true;
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id');
            $upd->execute([':new' => $newHash, ':id' => $userId]);
        }
    }

    if (!$ok) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'パスワード不一致']);
        exit;
    }

    /* ===== JWT発行（新スタックに合わせる） ===== */
    $now = time();
    $ttl = (int)($jwt_cfg['ttl'] ?? 900); // 15分デフォ
    $exp = $now + $ttl;
    $jti = bin2hex(random_bytes(16));     // 32桁hex（UUIDでもOK）

    $claims = [
        'iss' => $jwt_cfg['iss'] ?? 'attendance-api',
        'aud' => $jwt_cfg['aud'] ?? 'attendance-client',
        'sub' => $userId,
        'account_id' => $userId, // 数値IDが別にあるなら差し替え
        'jti' => $jti,
        'iat' => $now,
        'nbf' => $now,
        'exp' => $exp,
    ];

    $token = jwt_issue($claims, $jwt_cfg);

    // 失効・期限管理のため登録（実装があれば）
    if (function_exists('jwt_db_register')) {
        jwt_db_register($pdo, $jti, $exp, $userId);
    }

    // Cookie運用もするならここでSet-Cookie（任意）
    if (!empty($jwt_cfg['use_cookie']) && !empty($jwt_cfg['cookie_name'])) {
        $cookieName = $jwt_cfg['cookie_name'];
        $secure  = !empty($jwt_cfg['cookie_secure']) ? true : (!empty($_SERVER['HTTPS']));
        $domain  = $jwt_cfg['cookie_domain'] ?? '';
        $path    = $jwt_cfg['cookie_path'] ?? '/';
        $sameSite= $jwt_cfg['cookie_samesite'] ?? 'None';
        setcookie($cookieName, $token, [
            'expires'  => $exp,
            'path'     => $path,
            'domain'   => $domain ?: null,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    // ★ フロントの期待形（ok/token/exp）だけ返す
    echo json_encode([
        'ok'    => true,
        'token' => $token,
        'exp'   => $exp,
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    error_log('[auth/login] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'システムエラー']);
    exit;
}
