<?php
function verifyToken($headers) {
    if (!isset($headers['Authorization'])) return false;
    
    $authHeader = trim($headers['Authorization']);
    if (strpos($authHeader, 'Bearer') !== 0) return false;

    $token = trim(str_replace('Bearer', '', $authHeader));
    
    // テスト用：トークンが "valid-token" のみ通過
    return $token === "valid-token";
}
?>
