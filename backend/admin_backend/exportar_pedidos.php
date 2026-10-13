<?php
session_start();

require_once '../db.php';

if (!isset($_SESSION['tipo'])) {
    http_response_code(401);
    exit('Usuário não autenticado.');
}

if ($_SESSION['tipo'] !== 'ADMIN') {
    http_response_code(403);
    exit('Acesso permitido apenas para administradores.');
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
            pedidos.status_pagamento
        FROM pedidos
        INNER JOIN usuarios
            ON pedidos.usuario_id = usuarios.id
        ORDER BY
            CASE UPPER(pedidos.tamanho)
                WHEN 'PP' THEN 1
                WHEN 'P'  THEN 2
                WHEN 'M'  THEN 3
                WHEN 'G'  THEN 4
                WHEN 'GG' THEN 5
                ELSE 6
            END,
            pedidos.numero ASC,
            pedidos.id ASC
    ";

    $stmt = $pdo->query($sql);

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="pedidos_por_tamanho.csv"'
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // BOM para facilitar a abertura correta no Excel.
    echo "\xEF\xBB\xBF";

    $saida = fopen('php://output', 'w');

    // Cabeçalho em português.
    fputcsv($saida, [
        'ID do pedido',
        'Aluno',
        'E-mail',
        'Curso/Modelo',
        'Número da camisa',
        'Tamanho',
        'Nome nas costas',
        'Data do pedido',
        'Status do pagamento'
    ], ';', '"', '');

    while ($pedido = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($saida, [
            $pedido['id'],
            $pedido['aluno'],
            $pedido['email'],
            $pedido['modelo'],
            $pedido['numero'],
            $pedido['tamanho'],
            $pedido['nome_camisa'],
            $pedido['data_pedido'],
            $pedido['status_pagamento']
        ], ';', '"', '');
    }

    fclose($saida);
    exit;

} catch (PDOException $e) {
    error_log('Erro ao exportar pedidos: ' . $e->getMessage());
    http_response_code(500);
    exit('Erro ao gerar a exportação.');
}