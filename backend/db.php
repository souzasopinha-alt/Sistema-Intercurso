
<?php

$env = is_file(__DIR__ . '/.env')
    ? (parse_ini_file(__DIR__ . '/.env') ?: [])
    : [];

$host = $env['DB_HOST'] ?? getenv('DB_HOST') ?: '';
$db   = $env['DB_NAME'] ?? getenv('DB_NAME') ?: '';
$user = $env['DB_USER'] ?? getenv('DB_USER') ?: '';
$pass = $env['DB_PASS'] ?? getenv('DB_PASS') ?: '';

if ($host === '' || $db === '' || $user === '') {
    error_log('Configuração do banco incompleta.');
    http_response_code(500);
    exit('Erro interno de configuração do banco de dados.');
}

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log('Falha na conexão com o banco: ' . $e->getMessage());
    http_response_code(500);
    exit('Não foi possível conectar ao banco de dados.');
}
