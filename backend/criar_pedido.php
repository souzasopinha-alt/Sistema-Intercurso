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

if ($modelo === '' || $numero === '' || $tamanho === '' || $nomeCamisa === '') {
    http_response_code(400);

    echo json_encode([
        'erro' => 'Todos os campos são obrigatórios.'
    ]);

    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO pedidos (
            usuario_id,
            modelo,
            numero,
            tamanho,
            nome_camisa
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $_SESSION['usuario_id'],
        $modelo,
        $numero,
        $tamanho,
        $nomeCamisa
    ]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Pedido criado com sucesso.',
        'pedido_id' => $pdo->lastInsertId()
    ]);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        'erro' => 'Erro ao criar o pedido.'
    ]);
}
