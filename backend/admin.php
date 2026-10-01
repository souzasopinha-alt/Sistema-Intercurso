<?php

session_start();

require_once 'db.php';

header('Content-Type: application/json');

// Verifica se o usuário está autenticado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['tipo'])) {
    http_response_code(401);

    echo json_encode([
        'erro' => 'Usuário não autenticado'
    ]);

    exit;
}

// Verifica se o usuário é administrador
if ($_SESSION['tipo'] !== 'ADMIN') {
    http_response_code(403);

    echo json_encode([
        'erro' => 'Acesso negado.'
    ]);

    exit;
}

try {

    // Consulta os dados do painel
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

    // Transforma em JSON e exibe os dados do painel
    echo json_encode([
        'total' => (int) $resumo['total'],
        'pendentes' => (int) $resumo['pendentes'],
        'aprovados' => (int) $resumo['aprovados'],
        'recusados' => (int) $resumo['recusados']
    ]);

} catch (PDOException $e) {

    // Erro ao buscar dados do painel
    http_response_code(500);

    echo json_encode([
        'erro' => 'Erro ao buscar dados do painel'
    ]);
}