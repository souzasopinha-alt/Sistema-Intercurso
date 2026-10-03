<?php

session_start();

require_once '../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);

    echo json_encode([
        'sucesso' => false,
        'erro' => 'Usuário não autenticado.'
    ]);

    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];

$pedidoId = $_POST['pedido_id'] ?? null;
$metodoPagamento = $_POST['metodo_pagamento'] ?? '';
$descricao = trim($_POST['descricao'] ?? '');

if (!$pedidoId || !filter_var($pedidoId, FILTER_VALIDATE_INT)) {
    http_response_code(400);

    echo json_encode([
        'sucesso' => false,
        'erro' => 'Pedido inválido.'
    ]);

    exit;
}

try {

    /*
     * Verifica se o pedido realmente pertence
     * ao usuário que está enviando o comprovante.
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM pedidos
        WHERE id = ?
          AND usuario_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $pedidoId,
        $usuarioId
    ]);

    $pedido = $stmt->fetch();

    if (!$pedido) {
        http_response_code(403);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Este pedido não pertence ao usuário.'
        ]);

        exit;
    }

    /*
     * Pagamento em dinheiro não precisa
     * de comprovante.
     */
    if ($metodoPagamento === 'dinheiro') {

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Pagamento em dinheiro registrado.'
        ]);

        exit;
    }

    /*
     * PIX precisa de comprovante.
     */
    if ($metodoPagamento !== 'pix') {
        http_response_code(400);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Método de pagamento inválido.'
        ]);

        exit;
    }

    if (!isset($_FILES['comprovante'])) {
        http_response_code(400);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Nenhum comprovante foi enviado.'
        ]);

        exit;
    }

    $arquivo = $_FILES['comprovante'];

    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Erro ao enviar o arquivo.'
        ]);

        exit;
    }

    /*
     * Limite de 10 MB.
     */
    if ($arquivo['size'] > 10 * 1024 * 1024) {
        http_response_code(400);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'O arquivo excede o limite de 10 MB.'
        ]);

        exit;
    }

    /*
     * Verifica o tipo real do arquivo.
     */
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $tipo = $finfo->file($arquivo['tmp_name']);

    $tiposPermitidos = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf'
    ];

    if (!isset($tiposPermitidos[$tipo])) {
        http_response_code(400);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Tipo de arquivo não permitido.'
        ]);

        exit;
    }

    $extensao = $tiposPermitidos[$tipo];

    /*
     * Nome aleatório para evitar conflitos
     * e não confiar no nome enviado pelo usuário.
     */
    $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $extensao;

    $pastaUploads = __DIR__ . '/uploads/';

    if (!is_dir($pastaUploads)) {
        mkdir($pastaUploads, 0755, true);
    }

    $caminhoCompleto = $pastaUploads . $nomeArquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
        http_response_code(500);

        echo json_encode([
            'sucesso' => false,
            'erro' => 'Não foi possível salvar o comprovante.'
        ]);

        exit;
    }

    /*
     * Caminho que será armazenado no banco.
     */
    $caminhoBanco = 'backend/pagamentos/uploads/' . $nomeArquivo;

    $stmt = $pdo->prepare("
        INSERT INTO comprovantes (
            pedido_id,
            arquivo
        )
        VALUES (?, ?)
    ");

    $stmt->execute([
        $pedidoId,
        $caminhoBanco
    ]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Comprovante enviado com sucesso.',
        'pedido_id' => $pedidoId
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao registrar o comprovante.'
    ]);
}