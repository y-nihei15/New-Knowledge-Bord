<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/jwt_service.php';

/**
 * JWT 認証必須チェック
 *
 * @param bool $redirectIfMissing true にするとトークンが無い場合に
 *                                JSONエラーではなくログイン画面へリダイレクトする
 * @return array 認証済みユーザー情報
 */
function require_auth(bool $redirectIfMissing = false): array {
    // var_dump($_SERVER['HTTP_AUTHORIZATION']);exit;

    $cfg = require __DIR__ . '/jwt_config.php';
    $pdo = getDbConnection();

    $jwt = null;

    // 1) Authorization ヘッダから取得
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.*)$/i', $auth, $m)) {
        $jwt = trim($m[1]);
    } else {
        // 2) Cookie から取得（必要なら）
        $cookieName = $cfg['cookie_name'];
        if (!empty($_COOKIE[$cookieName] ?? '')) {
            $jwt = $_COOKIE[$cookieName];
        }
    }

    // トークン無し
    if (!$jwt) {
        if ($redirectIfMissing) {
            // ログイン画面にリダイレクト（絶対パスにして安全に）
            header("Location: /bbs/bbsTest/n-yoneda/main/loginScreen.php");
            exit;
        }
        unauthorized('Missing token');
    }

    // トークン検証
    $payload = jwt_verify($jwt, $cfg);
    if (!$payload) {
        unauthorized('Invalid token');
    }

    // DBの手動失効・期限切れチェック
    if (jwt_db_is_revoked_or_expired($pdo, $payload['jti'])) {
        unauthorized('Revoked or expired');
    }

    // 認証OK → 呼び出し側に返す
    return [
        'account_id' => (int)$payload['sub'],
        'jwt_id'     => $payload['jti'],
        'exp'        => $payload['exp']
    ];
}