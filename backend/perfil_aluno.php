<?php

session_start();

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Usuário não autenticado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $usuarioId = (int) $_SESSION['usuario_id'];

    $stmt = $pdo->prepare("
        SELECT id, nome, email, curso, serie, tipo
        FROM usuarios
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$usuarioId]);

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Usuário não encontrado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Flag de pedidos abertos
    $stmt = $pdo->query(
        "SELECT valor FROM configuracoes WHERE chave = 'pedidos_abertos' LIMIT 1"
    );
    $pedidosAbertos = $stmt->fetchColumn() === '1';

    // Todos os pedidos do usuário, mais recente primeiro
    $stmt = $pdo->prepare("
        SELECT
            id,
            modelo,
            numero,
            tamanho,
            nome_camisa,
            data_pedido,
            status_pagamento,
            metodo_pagamento
        FROM pedidos
        WHERE usuario_id = ?
        ORDER BY id DESC
    ");
    $stmt->execute([$usuarioId]);

    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'sucesso' => true,
        'usuario' => $usuario,
        'pedidos' => $pedidos,
        'pedidos_abertos' => $pedidosAbertos
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('Erro ao buscar perfil do aluno: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao carregar o perfil.'
    ], JSON_UNESCAPED_UNICODE);
}