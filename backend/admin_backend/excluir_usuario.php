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
    $alvoId = filter_var(is_array($dados) ? ($dados['id'] ?? null) : null, FILTER_VALIDATE_INT);

    if (!$alvoId || $alvoId < 1) {
        resposta(false, 'Usuário inválido.', 400);
    }

    if ($alvoId === (int) $_SESSION['usuario_id']) {
        resposta(false, 'Você não pode excluir a própria conta.', 400);
    }

    $stmt = $pdo->prepare('SELECT nome FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$alvoId]);
    $nomeAlvo = $stmt->fetchColumn();

    if ($nomeAlvo === false) {
        resposta(false, 'Usuário não encontrado.', 404);
    }

    $pdo->beginTransaction();

    try {
        // Votos precisam ser removidos antes das opções, camisas e usuários
        // por causa das chaves estrangeiras.
        $stmt = $pdo->prepare('DELETE FROM votos_realizados WHERE usuario_id = ?');
        $stmt->execute([$alvoId]);

        // Votos de outros usuários na proposta deste (libera FK de opcoes.id).
        $stmt = $pdo->prepare(
            'DELETE FROM votos_realizados
             WHERE opcao_id IN (SELECT id FROM opcoes WHERE usuario_id = ?)'
        );
        $stmt->execute([$alvoId]);

        $stmt = $pdo->prepare('DELETE FROM votos WHERE usuario_id = ?');
        $stmt->execute([$alvoId]);

        $stmt = $pdo->prepare(
            'DELETE FROM votos
             WHERE camisa_id IN (SELECT id FROM camisas WHERE usuario_id = ?)'
        );
        $stmt->execute([$alvoId]);

        // Comprovantes dependem dos pedidos e devem sair antes deles.
        $stmt = $pdo->prepare(
            'DELETE comprovantes FROM comprovantes
             INNER JOIN pedidos ON pedidos.id = comprovantes.pedido_id
             WHERE pedidos.usuario_id = ?'
        );
        $stmt->execute([$alvoId]);

        $stmt = $pdo->prepare('DELETE FROM pedidos WHERE usuario_id = ?');
        $stmt->execute([$alvoId]);

        // Propostas do usuário.
        $stmt = $pdo->prepare('DELETE FROM opcoes WHERE usuario_id = ?');
        $stmt->execute([$alvoId]);

        $stmt = $pdo->prepare('DELETE FROM camisas WHERE usuario_id = ?');
        $stmt->execute([$alvoId]);

        // Usuário.
        $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
        $stmt->execute([$alvoId]);

        $pdo->commit();

        resposta(true, 'Usuário excluído com sucesso.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('excluir_usuario.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível excluir o usuário.', 500);
}