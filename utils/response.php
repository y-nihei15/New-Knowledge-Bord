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
    echo json_encode($response);
    exit;
}
