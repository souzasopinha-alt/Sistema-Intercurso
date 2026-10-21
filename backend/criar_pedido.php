<?php

session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function responder(int $status, array $dados): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['usuario_id'])) {
    responder(401, ['erro' => 'Usuário não autenticado.']);
}

$modelo = trim($_POST['modelo'] ?? '');
$numero = filter_var($_POST['numero'] ?? null, FILTER_VALIDATE_INT);
$tamanho = trim($_POST['tamanho'] ?? '');
$nomeCamisa = trim($_POST['nome_camisa'] ?? '');
$substituirId = filter_var($_POST['substituir_pedido_id'] ?? null, FILTER_VALIDATE_INT);

if ($modelo === '' || !$numero || $numero < 1 || $numero > 99 || $tamanho === '' || $nomeCamisa === '') {
    responder(400, ['erro' => 'Todos os campos são obrigatórios e válidos.']);
}

$usuarioId = (int) $_SESSION['usuario_id'];

try {
    $pdo->beginTransaction();

    // Serializa criações simultâneas do mesmo usuário, inclusive quando ainda não há pedido.
    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE');
    $stmt->execute([$usuarioId]);
    if (!$stmt->fetchColumn()) {
        $pdo->rollBack();
        responder(404, ['erro' => 'Usuário não encontrado.']);
    }

    $stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'pedidos_abertos' LIMIT 1");
    if ($stmt->fetchColumn() !== '1') {
        $pdo->rollBack();
        responder(403, ['erro' => 'Os pedidos estão encerrados.']);
    }

    $stmt = $pdo->prepare('SELECT id, status_pagamento FROM pedidos WHERE usuario_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $stmt->execute([$usuarioId]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($substituirId) {
        if (!$ultimo || (int) $ultimo['id'] !== $substituirId || $ultimo['status_pagamento'] !== 'RECUSADO') {
            $pdo->rollBack();
            responder(409, ['erro' => 'Este pedido recusado não pode mais ser substituído. Atualize a página e tente novamente.']);
        }
        // Remover comprovantes dependentes antes do pedido (a FK não usa ON DELETE CASCADE).
        $stmt = $pdo->prepare('DELETE FROM comprovantes WHERE pedido_id = ?');
        $stmt->execute([$substituirId]);
        $stmt = $pdo->prepare('DELETE FROM pedidos WHERE id = ? AND usuario_id = ?');
        $stmt->execute([$substituirId, $usuarioId]);
    } elseif ($ultimo && $ultimo['status_pagamento'] !== 'APROVADO') {
        $pdo->rollBack();
        responder(409, ['erro' => 'Você só pode fazer um novo pedido depois que o administrador aprovar o pedido atual.']);
    }

    $stmt = $pdo->prepare('INSERT INTO pedidos (usuario_id, modelo, numero, tamanho, nome_camisa) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$usuarioId, $modelo, $numero, $tamanho, $nomeCamisa]);
    $pedidoId = (int) $pdo->lastInsertId();
    $pdo->commit();

    responder(200, [
        'sucesso' => true,
        'mensagem' => 'Pedido criado com sucesso.',
        'pedido_id' => $pedidoId
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Erro ao criar pedido: ' . $e->getMessage());
    responder(500, ['erro' => 'Erro ao criar pedido.']);
}
