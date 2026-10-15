<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message = '', int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resposta(false, 'Método não permitido.', 405);
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o banco indisponível.', 500);
}

$usuarioId = (int) $_SESSION['usuario_id'];

try {
    // Confere se envios ainda estão abertos
    $stmt = $pdo->query(
        "SELECT valor FROM configuracoes WHERE chave = 'envios_abertos' LIMIT 1"
    );

    if ($stmt->fetchColumn() !== '1') {
        resposta(false, 'O período de envio foi encerrado.', 403);
    }

    // Confere se o aluno já enviou ao menos uma proposta
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM opcoes WHERE usuario_id = ?'
    );
    $stmt->execute([$usuarioId]);

    if ((int) $stmt->fetchColumn() === 0) {
        resposta(false, 'Você ainda não enviou nenhuma proposta.', 400);
    }

    // Marca como finalizado
    $stmt = $pdo->prepare(
        'UPDATE usuarios SET envios_finalizados = 1 WHERE id = ?'
    );
    $stmt->execute([$usuarioId]);

    resposta(true, 'Propostas finalizadas com sucesso.');

} catch (Throwable $e) {
    error_log('finalizar_envios.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível finalizar os envios.', 500);
}