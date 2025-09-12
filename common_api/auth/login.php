<?php

declare(strict_types=1);

ini_set('display_errors', '0'); // API応答がJSONなので画面出力はNG
ini_set('log_errors', '1');     // 代わりにエラーログへ

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../jwt/jwt_service.php';
$jwt_cfg = require __DIR__ . '/../jwt/jwt_config.php';
require_once __DIR__ . '/../key/key_loader.php';

if ($allowCreds && $reqOrigin !== '') {
    // 資格情報ありのときはワイルドカード不可。来訪元をそのまま返す
    header('Access-Control-Allow-Origin: ' . $reqOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
}

header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');


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
    $stored = rtrim($stored); // ← CHAR列の右詰め空白対策（重要）
    $ok = false;

    $info = password_get_info($stored);
    $recognizedHash = ($stored !== '' && ($info['algo'] ?? 0) !== 0);

    if ($recognizedHash) {
        // 既に password_hash 系（bcrypt/argon 等）なら通常検証
        $ok = password_verify($password, $stored);

        // 1) 検証に成功した場合のみ更新
        // 2) 「再ハッシュが必要」な時だけ更新（常に上書きしない）
        if ($ok && password_needs_rehash($stored, PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUp = $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id');
            $stmtUp->execute([':new' => $newHash, ':id' => $userId]);
        }
    } else {
        // 平文想定：空白が混入していたら除去済みなので純粋比較
        if ($stored !== '' && hash_equals($stored, $password)) {
            $ok = true;
            // 平文から安全なハッシュへ移行
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id')
                ->execute([':new' => $newHash, ':id' => $userId]);
        }
    }

    if (!$ok) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'パスワード不一致']);
        exit;
    }

    /* ===== JWT発行（新スタックに合わせる） ===== */

    // 認証用の取得クエリを修正
    $stmt = $pdo->prepare('SELECT user_id, password, account_id FROM login_info WHERE user_id = :id LIMIT 1');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 認証 OK 後、account_id を整数で確実に確保
    $accountId = (int)$row['account_id'];
    if ($accountId <= 0) {
        throw new RuntimeException('account_id を取得できませんでした。');
    }

    $now = time();
    $ttl = max(300, (int)($jwt_cfg['ttl_seconds'] ?? 3600));
    $exp = $now + $ttl;
    $jti = function_exists('uuid_v4') ? uuid_v4() : bin2hex(random_bytes(16));

    $claims = [
        'iss' => $jwt_cfg['issuer'] ?? 'attendance-api',
        'aud' => $jwt_cfg['audience'] ?? 'attendance-client',
        'sub' => $userId,
        'account_id' => $accountId, // 数値IDが別にあるなら差し替え
        'jti' => $jti,
        'iat' => $now,
        'nbf' => $now,
        'exp' => $exp,
    ];

    $token = jwt_generate($claims, $jwt_cfg);

    // ★ DateTime に変換（UTCに正規化）
    $issuedAt  = (new DateTime('@' . $now))->setTimezone(new DateTimeZone('UTC'));
    $expiresAt = (new DateTime('@' . $exp))->setTimezone(new DateTimeZone('UTC'));

    jwt_db_insert($pdo, $jti, (int)$accountId, $issuedAt, $expiresAt);

    // ★ フロントの期待形（ok/token/exp）だけ返す
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
