<?php

session_start();

require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'ADMIN') {
    http_response_code(403);

    echo json_encode([
        'erro' => 'Acesso negado.'
    ]);

    exit;

    $sql = "
    SELECT
        COUNT(*) AS total,
        SUM(status_pagamento = 'PENDENTE') AS pendentes,
        SUM(status_pagamento = 'APROVADO') AS aprovados,
        SUM(status_pagamento = 'RECUSADO') AS recusados
    FROM pedidos
";

$stmt = $pdo->query($sql);
$resumo = $stmt->fetch();

echo json_encode($resumo);

}

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

    $total = $pdo->query("
        SELECT COUNT(*) FROM pedidos
    ")->fetchColumn();

    $pendentes = $pdo->query("
        SELECT COUNT(*) FROM pedidos
        WHERE status_pagamento = 'PENDENTE'
    ")->fetchColumn();

    $aprovados = $pdo->query("
        SELECT COUNT(*) FROM pedidos
        WHERE status_pagamento = 'APROVADO'
    ")->fetchColumn();

    $recusados = $pdo->query("
        SELECT COUNT(*) FROM pedidos
        WHERE status_pagamento = 'RECUSADO'
    ")->fetchColumn();

    echo json_encode([
        'total' => (int) $total,
        'pendentes' => (int) $pendentes,
        'aprovados' => (int) $aprovados,
        'recusados' => (int) $recusados
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'erro' => 'Erro ao buscar dados do painel'
    ]);
}
