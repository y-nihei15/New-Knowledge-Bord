<?php
function jsonResponse($status, $message, $data = null) {
    header("Content-Type: application/json; charset=UTF-8");
    $res = ["status" => $status, "message" => $message];
    if ($status === "success" && $data !== null) {
        $res["data"] = $data;
    }
    echo json_encode($res);
    exit;
}
