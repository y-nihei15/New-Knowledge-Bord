<?php
function getDbConnection() {
    $dsn = 'mysql:dbname=attendance_board;host=133.18.236.157';
    $user = 'root';
    $password = 'Knowledge88!!';

    try {
        $pdo = new PDO("dbn=$dsn;charset=utf8mb4", $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(503);
        echo json_encode(["status" => "error", "message" => "Service Unavailable"]);
        exit;
    }
}



