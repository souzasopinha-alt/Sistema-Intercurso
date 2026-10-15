<?php

session_start();

require_once 'db.php';

header('Content-Type: application/json');

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
            pedidos.status_pagamento,
            pedidos.data_pedido
        FROM pedidos
        INNER JOIN usuarios
            ON pedidos.usuario_id = usuarios.id
    ";

    $params = [];
    $filtros = [];

    // Filtro "meus pedidos": só os do usuário logado.
    if (isset($_GET['meus']) && $_GET['meus'] === '1') {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['erro' => 'Usuário não autenticado.']);
            exit;
        }

        $filtros[] = "pedidos.usuario_id = ?";
        $params[] = $_SESSION['usuario_id'];
    }

    if (isset($_GET['status'])) {
        $status = $_GET['status'];

        $statusPermitidos = ['PENDENTE', 'APROVADO', 'RECUSADO'];

        if (in_array($status, $statusPermitidos)) {
            $filtros[] = "pedidos.status_pagamento = ?";
            $params[] = $status;
        }
    }

    if (isset($_GET['modelo']) && $_GET['modelo'] !== '') {
        $filtros[] = "pedidos.modelo = ?";
        $params[] = $_GET['modelo'];
    }

    if (isset($_GET['tamanho']) && $_GET['tamanho'] !== '') {
        $filtros[] = "pedidos.tamanho = ?";
        $params[] = $_GET['tamanho'];
    }

    if (!empty($filtros)) {
        $sql .= " WHERE " . implode(" AND ", $filtros);
    }

    $sql .= " ORDER BY pedidos.data_pedido DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $pedidos = $stmt->fetchAll();

    echo json_encode($pedidos);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        'erro' => 'Erro ao buscar os pedidos.'
    ]);
}
