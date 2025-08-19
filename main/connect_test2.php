<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$host='127.0.0.1'; $port='3306';
$db='attendance_board'; $user='root'; $pass='';

try {
  $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$pass);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  echo "PDO OK<br>";
  echo "location_mst check: ";
  $pdo->query("SELECT 1 FROM location_mst LIMIT 1");
  echo "OK";
} catch (Throwable $e) {
  echo "FAILED<br>Code: ".$e->getCode()."<br>Msg: ".$e->getMessage();
}
