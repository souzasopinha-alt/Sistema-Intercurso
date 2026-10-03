<?php

session_start();

require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['tipo'])) {
    http_response_code(401);

    echo json_encode([
        'erro' => 'Usuário não autenticado'
    ]);

    exit;
}

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
            usuarios.email,
            pedidos.modelo,
            pedidos.numero,
            pedidos.tamanho,
            pedidos.nome_camisa,
            pedidos.data_pedido,
            pedidos.status_pagamento,

            (
                SELECT comprovantes.id
                FROM comprovantes
                WHERE comprovantes.pedido_id = pedidos.id
                ORDER BY comprovantes.data_envio DESC, comprovantes.id DESC
                LIMIT 1
            ) AS comprovante_id,

            (
                SELECT comprovantes.arquivo
                FROM comprovantes
                WHERE comprovantes.pedido_id = pedidos.id
                ORDER BY comprovantes.data_envio DESC, comprovantes.id DESC
                LIMIT 1
            ) AS comprovante_arquivo,

            (
                SELECT comprovantes.data_envio
                FROM comprovantes
                WHERE comprovantes.pedido_id = pedidos.id
                ORDER BY comprovantes.data_envio DESC, comprovantes.id DESC
                LIMIT 1
            ) AS comprovante_data

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

    // Filtro por modelo/curso
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

    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($pedidos);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'erro' => 'Erro ao buscar pedidos'
    ]);
}