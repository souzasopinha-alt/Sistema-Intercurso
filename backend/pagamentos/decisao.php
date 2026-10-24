<?php

if (!defined('STATUS_PAGAMENTO_DECISAO')) {
    http_response_code(404);
    exit;
}

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['usuario_id']) || !isset($_SESSION['tipo'])) {
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Usuário não autenticado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper(trim((string) $_SESSION['tipo'])) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Acesso permitido apenas para administradores.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dados = json_decode((string) file_get_contents('php://input'), true);
$pedidoId = filter_var(
    is_array($dados) ? ($dados['id'] ?? null) : null,
    FILTER_VALIDATE_INT
);

if (!$pedidoId || $pedidoId < 1) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'ID do pedido inválido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../db.php';

$statusPedido = STATUS_PAGAMENTO_DECISAO;
$statusComprovante = $statusPedido === 'APROVADO' ? 'aprovado' : 'recusado';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT status_pagamento FROM pedidos WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$pedidoId]);
    $statusAtual = $stmt->fetchColumn();

    if ($statusAtual === false) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Pedido não encontrado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($statusAtual !== 'PENDENTE') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Este pedido já foi analisado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE pedidos SET status_pagamento = ? WHERE id = ?'
    );
    $stmt->execute([$statusPedido, $pedidoId]);

    $stmt = $pdo->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'comprovantes'
           AND COLUMN_NAME IN ('status', 'analisado_por', 'analisado_em')"
    );
    $colunas = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

    if (isset($colunas['status'])) {
        $stmt = $pdo->prepare(
            'SELECT id FROM comprovantes
             WHERE pedido_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $comprovanteId = $stmt->fetchColumn();

        if ($comprovanteId !== false) {
            $campos = ['status = ?'];
            $valores = [$statusComprovante];

            if (isset($colunas['analisado_por'])) {
                $campos[] = 'analisado_por = ?';
                $valores[] = (int) $_SESSION['usuario_id'];
            }
            if (isset($colunas['analisado_em'])) {
                $campos[] = 'analisado_em = NOW()';
            }

            $valores[] = (int) $comprovanteId;
            $stmt = $pdo->prepare(
                'UPDATE comprovantes SET ' . implode(', ', $campos) . ' WHERE id = ?'
            );
            $stmt->execute($valores);
        }
    }

    $pdo->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $statusPedido === 'APROVADO'
            ? 'Pagamento aprovado.'
            : 'Pagamento recusado.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erro ao decidir pagamento: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao atualizar o pagamento.'
    ], JSON_UNESCAPED_UNICODE);
}
