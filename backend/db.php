
<?php

$env = is_file(__DIR__ . '/.env')
    ? (parse_ini_file(__DIR__ . '/.env') ?: [])
    : [];

$host = $env['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$db   = $env['DB_NAME'] ?? getenv('DB_NAME') ?: 'sistema_intercurso';
$user = $env['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$pass = $env['DB_PASS'] ?? getenv('DB_PASS') ?: '';

if ($host === '' || $db === '' || $user === '') {
    error_log('Configuração do banco incompleta.');
    http_response_code(500);
    if (defined('DB_JSON_ERRORS') && DB_JSON_ERRORS) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Serviço de autenticação temporariamente indisponível.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    if (defined('DB_JSON_ERRORS') && DB_JSON_ERRORS) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Serviço de autenticação temporariamente indisponível.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    exit('Não foi possível conectar ao banco de dados.');
}
