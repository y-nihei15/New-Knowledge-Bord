<?php
function getDbConnection() {
    $host = '127.0.0.1';  // ← localhost より 127.0.0.1 推奨
    $port = '3306';
    $dbname = 'attendance_board'; // ← 実DB名
    $user = 'root';               // ← 実ユーザ
    $pass = 'Knowledge88!!';                   // ← 実パスワード

    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;

    } catch (PDOException $e) {
        // 本番では隠す。ローカル調査の間だけ出す
        error_log('[DB] '.$e->getCode().' '.$e->getMessage());
        http_response_code(503);

        // ← 調査用に “コード” を一時的に出力
        echo json_encode([
            "status" => "error",
            "message" => "Service Unavailable",
            "code" => $e->getCode(),          // ★ ここを見る！
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
