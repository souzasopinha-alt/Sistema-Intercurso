<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

function resposta(
    bool $success,
    string $message = '',
    int $status = 200,
    array $extra = []
): never {
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
        "SELECT id, nome, imagem1, imagem2, imagem3, aprovado
         FROM opcoes
         WHERE usuario_id = ?
         ORDER BY created_at ASC, id ASC"
    );

    $stmt->execute([(int) $_SESSION['usuario_id']]);

    $opcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    resposta(true, '', 200, [
        'opcoes' => $opcoes
    ]);

} catch (Throwable $e) {
    error_log('listar_opcoes.php: ' . $e->getMessage());

    resposta(false, 'Não foi possível carregar suas propostas.', 500);
}
