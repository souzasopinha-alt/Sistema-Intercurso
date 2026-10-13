<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message = '', int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $ok, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado.', 401);
}

try {
    $stmt = $pdo->query(
        'SELECT id, nome, imagem1, imagem2, imagem3
         FROM opcoes
         WHERE aprovado = 1
         ORDER BY created_at ASC, id ASC'
    );

    resposta(true, '', 200, ['opcoes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('listar_opcoes.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível carregar as propostas.', 500);
}
