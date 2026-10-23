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
    header('Allow: POST');
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
    $pdo->beginTransaction();

    // Use the same lock order as upload_camisas.php so finalization cannot
    // race an upload already in progress.
    $stmt = $pdo->query(
        "SELECT valor FROM configuracoes
         WHERE chave = 'envios_abertos'
         FOR UPDATE"
    );

    if ($stmt->fetchColumn() !== '1') {
        $pdo->rollBack();
        resposta(false, 'O período de envio foi encerrado.', 403);
    }

    $stmt = $pdo->prepare(
        'SELECT envios_finalizados FROM usuarios WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$usuarioId]);
    $finalizado = $stmt->fetchColumn();
    if ($finalizado === false) {
        $pdo->rollBack();
        resposta(false, 'Usuário não encontrado.', 404);
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM opcoes WHERE usuario_id = ?'
    );
    $stmt->execute([$usuarioId]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->rollBack();
        resposta(false, 'Você ainda não enviou nenhuma proposta.', 400);
    }

    $stmt = $pdo->prepare(
        'UPDATE usuarios SET envios_finalizados = 1 WHERE id = ?'
    );
    $stmt->execute([$usuarioId]);

    $pdo->commit();
    resposta(true, 'Propostas finalizadas com sucesso.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('finalizar_envios.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível finalizar os envios.', 500);
}