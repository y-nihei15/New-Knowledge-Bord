<?php
// 使い方（例・login.phpの認証成功時）:
//   require_once __DIR__.'/../jwt/token_issue.php';
//   $result = issue_token($accountId);  // ['token'=>..., 'jwt_id'=>..., 'exp'=>...]
require_once __DIR__ . '/jwt_service.php';
$cfg = require __DIR__ . '/jwt_config.php';

function issue_token(int $accountId): array {
    $cfg = require __DIR__ . '/jwt_config.php';
    $pdo = getDbConnection(); // config/db.php のPDO生成関数

    $now  = new DateTime('now');
    $exp  = (clone $now)->modify('+' . $cfg['ttl_seconds'] . ' seconds');
    $jti  = uuid_v4();

    $claims = [
        'iss' => $cfg['issuer'],
        'aud' => $cfg['audience'],
        'iat' => $now->getTimestamp(),
        'nbf' => $now->getTimestamp(),
        'exp' => $exp->getTimestamp(),
        'sub' => (string)$accountId, // 認可判定用
        'jti' => $jti,
    ];
    
    $jwt = jwt_generate($claims, $cfg);

    // DBへ登録（発行日時、失効日時＝発行日+90日）
    jwt_db_insert($pdo, $jti, $accountId, $now, $exp);

    return ['token'=>$jwt, 'jwt_id'=>$jti, 'exp'=>$exp->format(DATE_ATOM)];
}
