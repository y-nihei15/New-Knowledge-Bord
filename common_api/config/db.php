<?php
function getDbConnection() {
    $host = '127.0.0.1';
    $port = '3306';
    $dbname = 'attendance_board';
    $user = 'root';
    $password = 'Knowledge88!!';

    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("[DB] " . $e->getMessage());
        http_response_code(503);
        echo json_encode([
            "status" => "error",
            "message" => "Service Unavailable",
            "code" => $e->getCode()
        ]);
        exit;
    }
}

