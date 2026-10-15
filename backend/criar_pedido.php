<?php

session_start();

require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);

    echo json_encode([
        'erro' => 'Usuário não autenticado.'
    ]);

    exit;
}

$modelo = $_POST['modelo'] ?? '';
$numero = $_POST['numero'] ?? '';
$tamanho = $_POST['tamanho'] ?? '';
$nomeCamisa = $_POST['nome_camisa'] ?? '';

if (
    $modelo === '' ||
    $numero === '' ||
    $tamanho === '' ||
    $nomeCamisa === ''
) {
    http_response_code(400);

    echo json_encode([
        'erro' => 'Todos os campos são obrigatórios.'
    ]);

    exit;
}

    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM pedidos
        WHERE usuario_id = ? AND status_pagamento = 'PENDENTE'
    ");
    $stmtCheck->execute([$_SESSION["usuario_id"]]);
    $temPendente = (int) $stmtCheck->fetchColumn();

    if ($temPendente > 0) {
        http_response_code(409);
        echo json_encode([
            "erro" => "Voce ja tem um pedido pendente de aprovacao. Aguarde a analise para fazer um novo."
        ]);
        exit;
    }


try {

    $sql = "
        INSERT INTO pedidos (
            usuario_id,
            modelo,
            numero,
            tamanho,
            nome_camisa
        )
        VALUES (?, ?, ?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        $_SESSION['usuario_id'],
        $modelo,
        $numero,
        $tamanho,
        $nomeCamisa
    ]);

    // Remove pedidos recusados anteriores deste usuário (com comprovantes e arquivos).
    $stmtRecusados = $pdo->prepare("
        SELECT id FROM pedidos
        WHERE usuario_id = ? AND status_pagamento = 'RECUSADO'
    ");
    $stmtRecusados->execute([$_SESSION['usuario_id']]);
    $idsRecusados = $stmtRecusados->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($idsRecusados)) {
        $placeholders = implode(',', array_fill(0, count($idsRecusados), '?'));

        $stmtArquivos = $pdo->prepare("
            SELECT arquivo FROM comprovantes WHERE pedido_id IN ($placeholders)
        ");
        $stmtArquivos->execute($idsRecusados);
        $arquivos = $stmtArquivos->fetchAll(PDO::FETCH_COLUMN);

        foreach ($arquivos as $caminhoBanco) {
            $nome = basename($caminhoBanco);
            $abs = __DIR__ . '/pagamentos/uploads/' . $nome;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        $stmtDelComp = $pdo->prepare("
            DELETE FROM comprovantes WHERE pedido_id IN ($placeholders)
        ");
        $stmtDelComp->execute($idsRecusados);

        $stmtDelPed = $pdo->prepare("
            DELETE FROM pedidos WHERE id IN ($placeholders)
        ");
        $stmtDelPed->execute($idsRecusados);
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Pedido criado com sucesso.',
        'pedido_id' => $pdo->lastInsertId()
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
    'erro' => 'Erro ao criar pedido.',
    'detalhes' => $e->getMessage()
    ]);
}