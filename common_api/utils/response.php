<?php
function jsonResponse($status, $message, $data = null) {
    header("Content-Type: application/json; charset=UTF-8");
    $response = [
        "status" => $status,
        "message" => $message
    ];
    if ($status === "success" && $data !== null) {
        $response["data"] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('json_no_store')) {
  function json_no_store(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

/**
 * 401 Unauthorized 応答用のヘルパー
 */
if (!function_exists('unauthorized')) {
  function unauthorized(string $msg = 'Unauthorized'): void {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
