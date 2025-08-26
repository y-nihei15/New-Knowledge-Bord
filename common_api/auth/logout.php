<?php
require_once __DIR__ . '/../jwt/token_reset.php';
header('Content-Type: application/json; charset=utf-8');

$ok = reset_token_from_header();  // JWTの無効化処理

http_response_code(200);
echo json_encode([
  'status'  => $ok ? 'success' : 'error',
  'message' => $ok ? 'Logged out successfully' : 'Logout failed'
], JSON_UNESCAPED_UNICODE);