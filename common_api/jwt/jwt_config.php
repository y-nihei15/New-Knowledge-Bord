<?php
// 秘密鍵や共通設定（本番は .env やサーバ環境変数で管理推奨）
return [
    'secret'   => getenv('JWT_SECRET') ?: 'change-this-secret',
    'issuer'   => 'know-net.co.jp',   // iss know-net.co.jp attendance-api
    'audience' => 'know-net-app',     // aud
    // 有効期限は要件に合わせて発行日+90日（DBのexpires_atとも整合）
    'ttl_seconds' => 90 * 24 * 60 * 60,
    // クッキー運用する場合の名前（任意）
    'cookie_name' => 'access_token',
];
