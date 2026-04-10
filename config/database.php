<?php
/*
$host = '127.0.0.1';
$dbname = 'finance230326';
$username = 'root';
$password = '';
*/

$host = '127.0.0.1';
$port = '3308';//для сервера
//$port = '3306';//для локального сервера
$dbname = 'host1340522_finance230326';
$username = 'host1340522_user26';
$password = 'a32d3bd4';
//$username = 'root';
//$password = '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>