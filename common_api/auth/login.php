<?php
declare(strict_types=1);

// JSONを壊さない（画面にエラーを出さない）
ini_set('display_errors', '0');
ini_set('log_errors', '1');<?php

declare(strict_types=1);
ini_set('display_errors', '0'); // API応答がJSONなので画面出力はNG
ini_set('log_errors', '1');     // 代わりにエラーログへ
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../jwt/jwt_service.php';
$jwt_cfg = require __DIR__ . '/../jwt/jwt_config.php';

/* ===== CORS（同一オリジンなら気にしなくてOK。将来のために置いておく） ===== */
$allowOrigin = $jwt_cfg['allow_origin'] ?? '*';
$allowCreds  = !empty($jwt_cfg['use_cookie']);
$reqOrigin   = $_SERVER['HTTP_ORIGIN'] ?? '';

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

        // 顔合わせ：必要なら再ハッシュ（コスト変更やアルゴ進化に追従）
        // ★ 暫定：BCRYPT固定（60文字）。DB拡張後は DEFAULT に戻す
        if ($ok && password_needs_rehash($stored, PASSWORD_BCRYPT)) {
            $rehash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id')
                ->execute([':new' => $rehash, ':id' => $userId]);
        }

        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id')
            ->execute([':new' => $newHash, ':id' => $userId]);
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
    $now = time();
    $ttl = (int)($jwt_cfg['ttl'] ?? 900); // 15分デフォ
    $exp = $now + $ttl;
    $jti = bin2hex(random_bytes(16));     // 32桁hex（UUIDでもOK）

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

    $token =jwt_generate($claims, $jwt_cfg);

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

error_reporting(E_ALL);
ob_start(); // 予期せぬ出力を吸収

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// OPTIONSは即返す（将来のCORS対策）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Location: ../../main/loginScreen.php'); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    if (ob_get_length()) ob_end_clean();
    echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']); exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../jwt/token_issue.php'; // ← パス確認

// フォーム/JSON両対応（いまはフォームでOK）
$ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (is_string($ctype) && stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true) ?: [];
    $userId = (string)($in['user_id'] ?? '');
    $password = (string)($in['password'] ?? '');
} else {
    $userId = (string)($_POST['user_id'] ?? '');
    $password = (string)($_POST['password'] ?? '');
}

if ($userId === '' || $password === '') {
    http_response_code(400);
    if (ob_get_length()) ob_end_clean();
    echo json_encode(['ok'=>false,'error'=>'IDとパスワードを入力してください']); exit;
}

try {
    $pdo = getDbConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ★ 数値キャストしない（英数字IDを潰さない）
    $stmt = $pdo->prepare('SELECT user_id, password FROM login_info WHERE user_id = :id LIMIT 1');
    $stmt->bindValue(':id', $userId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(401);
        if (ob_get_length()) ob_end_clean();
        echo json_encode(['ok'=>false,'error'=>'ユーザー未存在']); exit;
    }

    $stored = (string)$row['password'];
    $ok = false;
    if ($stored !== '' && password_get_info($stored)['algo'] !== 0) {
        $ok = password_verify($password, $stored);
    } else {
        if (hash_equals($stored, $password)) {
            $ok = true;
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $upd = $pdo->prepare('UPDATE login_info SET password = :new WHERE user_id = :id');
            $upd->execute([':new'=>$newHash, ':id'=>$userId]);
        }
    }

    if (!$ok) {
        http_response_code(401);
        if (ob_get_length()) ob_end_clean();
        echo json_encode(['ok'=>false,'error'=>'パスワード不一致']); exit;
    }

    // JWT発行（issue_token の戻りキーを実際に合わせる）
    $res = issue_token((int)$row['user_id']); // 数値IDで運用ならこのまま。英数字なら $userId を渡す
    // 例）戻り値が ['token'=>..., 'exp'=>...] 前提。違うならここを合わせる
    $token = $res['token'] ?? null;
    $exp   = $res['exp']   ?? (time()+900);

    if (ob_get_length()) ob_end_clean();
    echo json_encode(['ok'=>true, 'token'=>$token, 'exp'=>$exp], JSON_UNESCAPED_UNICODE); exit;

} catch (Throwable $e) {
    error_log('[auth/login] '.$e->getMessage());
    http_response_code(500);
    if (ob_get_length()) ob_end_clean();
    echo json_encode(['ok'=>false,'error'=>'システムエラー']); exit;
}
