<?php

session_start();

require_once '../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['tipo'])) {
    http_response_code(401);

    echo json_encode([
        'sucesso' => false,
        'erro' => 'Usuário não autenticado.'
    ]);

    exit;
}

if ($_SESSION['tipo'] !== 'ADMIN') {
    http_response_code(403);

    echo json_encode([
        'sucesso' => false,
        'erro' => 'Acesso permitido apenas para administradores.'
    ]);

    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);

$id = $dados['id'] ?? null;

if (!$id || !filter_var($id, FILTER_VALIDATE_INT)) {
    http_response_code(400);

    echo json_encode([
        'sucesso' => false,
        'erro' => 'ID do pedido inválido.'
    ]);

    exit;
}

try {

    $stmt = $pdo->prepare("
        UPDATE pedidos
        SET status_pagamento = 'APROVADO'
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Pedido não encontrado ou já estava aprovado.'
        ]);

        exit;
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Pagamento aprovado.'
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao aprovar pagamento.'
    ]);
}