<?php

session_start();

require_once 'db.php';

header('Content-Type: application/json');

// Verifica se está logado
if (!isset($_SESSION['tipo'])) {
    http_response_code(401);

    echo json_encode([
        'erro' => 'Usuário não autenticado'
    ]);

    exit;
}

// Verifica se é administrador
if ($_SESSION['tipo'] !== 'ADMIN') {
    http_response_code(403);

    echo json_encode([
        'erro' => 'Acesso permitido apenas para administradores'
    ]);

    exit;
}

try {

    $sql = "
        SELECT
            pedidos.id,
            usuarios.nome AS aluno,
            pedidos.modelo,
            pedidos.numero,
            pedidos.tamanho,
            pedidos.nome_camisa,
            pedidos.data_pedido,
            pedidos.status_pagamento
        FROM pedidos
        INNER JOIN usuarios
            ON pedidos.usuario_id = usuarios.id
        WHERE 1=1
    ";

    $parametros = [];

    // Filtro por status
    if (!empty($_GET['status'])) {
        $sql .= " AND pedidos.status_pagamento = :status";
        $parametros['status'] = $_GET['status'];
    }

    // Filtro por modelo
    if (!empty($_GET['modelo'])) {
        $sql .= " AND pedidos.modelo = :modelo";
        $parametros['modelo'] = $_GET['modelo'];
    }

    // Filtro por tamanho
    if (!empty($_GET['tamanho'])) {
        $sql .= " AND pedidos.tamanho = :tamanho";
        $parametros['tamanho'] = $_GET['tamanho'];
    }

    $sql .= " ORDER BY pedidos.data_pedido DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);

    $pedidos = $stmt->fetchAll();

    echo json_encode($pedidos);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'erro' => 'Erro ao buscar pedidos'
    ]);
}
