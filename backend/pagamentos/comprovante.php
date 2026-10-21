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

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Conteúdo JSON inválido.']);
    exit;
}
$pedidoId = $payload['pedido_id'] ?? null;
$metodoPagamento = $payload['metodo_pagamento'] ?? '';

if (!$pedidoId || !filter_var($pedidoId, FILTER_VALIDATE_INT)) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Pedido inválido.'
    ]);
    exit;
}

try {
    // Confere que o pedido pertence ao usuário
    $stmt = $pdo->prepare("
        SELECT id FROM pedidos WHERE id = ? AND usuario_id = ? LIMIT 1
    ");
    $stmt->execute([$pedidoId, $usuarioId]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        http_response_code(403);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Este pedido não pertence ao usuário.'
        ]);
        exit;
    }

    $pdo->beginTransaction();

    try {

        // ---------- Dinheiro ----------
        if ($metodoPagamento === 'dinheiro') {
            $stmt = $pdo->prepare("
                UPDATE pedidos
                SET metodo_pagamento = 'DINHEIRO',
                    status_pagamento = 'PENDENTE'
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$pedidoId, $usuarioId]);

            $pdo->commit();

            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Pagamento em dinheiro registrado.'
            ]);
            exit;
        }

        // ---------- PIX ----------
        if (!isset($payload['comprovante']['data'])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'Nenhum comprovante foi enviado.'
            ]);
            exit;
        }

        $conteudo = is_string($payload['comprovante']['data'])
            ? base64_decode($payload['comprovante']['data'], true)
            : false;
        if ($conteudo === false) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'Erro ao enviar o arquivo.'
            ]);
            exit;
        }

        if (strlen($conteudo) > 10 * 1024 * 1024 || strlen($conteudo) === 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'O arquivo excede o limite de 10 MB.'
            ]);
            exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $tipo = $finfo->buffer($conteudo);

        $tiposPermitidos = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf'
        ];

        if (!isset($tiposPermitidos[$tipo])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'Tipo de arquivo não permitido.'
            ]);
            exit;
        }

        $extensao = $tiposPermitidos[$tipo];
        $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $extensao;

        $pastaUploads = __DIR__ . '/uploads/';
        if (!is_dir($pastaUploads)) {
            mkdir($pastaUploads, 0755, true);
        }

        $caminhoCompleto = $pastaUploads . $nomeArquivo;

        if (file_put_contents($caminhoCompleto, $conteudo, LOCK_EX) === false) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'Não foi possível salvar o comprovante.'
            ]);
            exit;
        }

        $caminhoBanco = 'backend/pagamentos/uploads/' . $nomeArquivo;

        $stmt = $pdo->prepare("
            INSERT INTO comprovantes (pedido_id, arquivo)
            VALUES (?, ?)
        ");
        $stmt->execute([$pedidoId, $caminhoBanco]);

        $stmt = $pdo->prepare("
            UPDATE pedidos
            SET metodo_pagamento = 'PIX',
                status_pagamento = 'PENDENTE'
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->execute([$pedidoId, $usuarioId]);

        $pdo->commit();

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Comprovante enviado com sucesso.',
            'pedido_id' => $pedidoId
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isset($caminhoCompleto) && file_exists($caminhoCompleto)) {
            unlink($caminhoCompleto);
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log('comprovante.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao registrar o comprovante.'
    ]);
}
