<?php

$env = is_file(__DIR__ . '/.env')
    ? (parse_ini_file(__DIR__ . '/.env') ?: [])
    : [];

$host = $env['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$db   = $env['DB_NAME'] ?? getenv('DB_NAME') ?: '';
$user = $env['DB_USER'] ?? getenv('DB_USER') ?: '';
$pass = $env['DB_PASS'] ?? getenv('DB_PASS') ?: '';

$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    
} catch (PDOException $e) {
    echo "Erro ao conectar com o banco de dados: " . $e->getMessage();
}
