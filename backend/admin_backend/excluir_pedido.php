<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth/exigir_administrador.php';

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

try {
    exigirAdministrador($pdo);

    $dados = json_decode(file_get_contents('php://input'), true);
    $pedidoId = filter_var(is_array($dados) ? ($dados['id'] ?? null) : null, FILTER_VALIDATE_INT);

    if (!$pedidoId || $pedidoId < 1) {
        resposta(false, 'Pedido inválido.', 400);
    }

    $stmt = $pdo->prepare('SELECT id FROM pedidos WHERE id = ? LIMIT 1');
    $stmt->execute([$pedidoId]);

    if (!$stmt->fetchColumn()) {
        resposta(false, 'Pedido não encontrado.', 404);
    }

    $pdo->beginTransaction();

    try {
        // Comprovantes primeiro (FK para pedidos)
        $stmt = $pdo->prepare('DELETE FROM comprovantes WHERE pedido_id = ?');
        $stmt->execute([$pedidoId]);

        $stmt = $pdo->prepare('DELETE FROM pedidos WHERE id = ?');
        $stmt->execute([$pedidoId]);

        $pdo->commit();

        resposta(true, 'Pedido excluído com sucesso.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('excluir_pedido.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível excluir o pedido.', 500);
}