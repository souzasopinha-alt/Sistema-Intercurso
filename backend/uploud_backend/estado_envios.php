<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

function resposta(bool $success, string $message = '', int $status = 200, array $extra = []): never
{
    http_response_code($status);

    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o banco indisponível.', 500);
}

try {
    $stmt = $pdo->prepare(
        "SELECT valor
         FROM configuracoes
         WHERE chave = 'envios_abertos'
         LIMIT 1"
    );

    $stmt->execute();

    $enviosAbertos = $stmt->fetchColumn() === '1';

    resposta(true, '', 200, [
        'envios_abertos' => $enviosAbertos
    ]);
} catch (Throwable $e) {
    error_log('estado_envios.php: ' . $e->getMessage());

    resposta(false, 'Não foi possível consultar o período de envio.', 500);
}