<?php

session_start();

require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['tipo'])) {
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

    // ==============================
    // RESUMO GERAL
    // ==============================

    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(status_pagamento = 'PENDENTE') AS pendentes,
            SUM(status_pagamento = 'APROVADO') AS aprovados,
            SUM(status_pagamento = 'RECUSADO') AS recusados
        FROM pedidos
    ";

    $stmt = $pdo->query($sql);
    $resumo = $stmt->fetch(PDO::FETCH_ASSOC);


    // ==============================
    // PEDIDOS POR MODELO
    // ==============================

    $sql = "
        SELECT
            modelo,
            COUNT(*) AS quantidade
        FROM pedidos
        GROUP BY modelo
        ORDER BY quantidade DESC
    ";

    $stmt = $pdo->query($sql);
    $porModelo = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // ==============================
    // PEDIDOS POR TAMANHO
    // ==============================

    $sql = "
        SELECT
            tamanho,
            COUNT(*) AS quantidade
        FROM pedidos
        GROUP BY tamanho
        ORDER BY quantidade DESC
    ";

    $stmt = $pdo->query($sql);
    $porTamanho = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // ==============================
    // PEDIDOS POR STATUS
    // ==============================

    $sql = "
        SELECT
            status_pagamento,
            COUNT(*) AS quantidade
        FROM pedidos
        GROUP BY status_pagamento
    ";

    $stmt = $pdo->query($sql);
    $porStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // ==============================
    // RESPOSTA
    // ==============================

    echo json_encode([
        'total' => (int) $resumo['total'],
        'pendentes' => (int) $resumo['pendentes'],
        'aprovados' => (int) $resumo['aprovados'],
        'recusados' => (int) $resumo['recusados'],

        'por_modelo' => $porModelo,
        'por_tamanho' => $porTamanho,
        'por_status' => $porStatus
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'erro' => 'Erro ao buscar dados do painel'
    ]);
}