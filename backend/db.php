<?php
$host = '127.0.0.1';
$db   = 'sistema_intercurso';
$user = 'root';
$pass = '2026'; // Deixe vazio se o root não tiver senha, ou coloque '2026' se definiu essa senha antes
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     echo "Conexão com o banco de dados realizada com sucesso!";
} catch (\PDOException $e) {
     echo "Erro ao conectar com o banco de dados: " . $e->getMessage();
}