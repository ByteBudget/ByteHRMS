<?php
// db.php - Database Connection & Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Denver');

$host = '127.0.0.1';
$db   = ''; // Change to your database name
$user = '';      // Change to your database user
$pass = '';          // Change to your database password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
