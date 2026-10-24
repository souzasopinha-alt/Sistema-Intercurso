<?php

session_start();

require_once '../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Usuário não autenticado.'
    ]);
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    $pedidoId = filter_input(INPUT_GET, 'pedido_id', FILTER_VALIDATE_INT);
    if (!$pedidoId || $pedidoId < 1) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'erro' => 'Pedido inválido.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, status_pagamento, metodo_pagamento
            FROM pedidos
            WHERE id = ? AND usuario_id = ?
            LIMIT 1
        ");
        $stmt->execute([$pedidoId, $usuarioId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            http_response_code(404);
            echo json_encode(['sucesso' => false, 'erro' => 'Pedido não encontrado.']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM comprovantes WHERE pedido_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$pedidoId]);

        echo json_encode([
            'sucesso' => true,
            'pedido_id' => (int) $pedido['id'],
            'status_pagamento' => $pedido['status_pagamento'],
            'metodo_pagamento' => $pedido['metodo_pagamento'],
            'pagamento_registrado' => $pedido['metodo_pagamento'] !== null
                || $stmt->fetchColumn() !== false
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('comprovante.php status: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao consultar o pagamento.']);
    }
    exit;
}

if ($metodo !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido.']);
    exit;
}

$corpo = file_get_contents('php://input');
if ($corpo === '' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    echo json_encode(['sucesso' => false, 'erro' => 'O envio excede o limite permitido.']);
    exit;
}

$payload = json_decode($corpo, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Conteúdo JSON inválido.']);
    exit;
}
$pedidoId = $payload['pedido_id'] ?? null;
$metodoPagamento = $payload['metodo_pagamento'] ?? '';

if (!filter_var($pedidoId, FILTER_VALIDATE_INT) || (int) $pedidoId < 1) {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Pedido inválido.'
    ]);
    exit;
}

if (!in_array($metodoPagamento, ['pix', 'dinheiro'], true)) {
    http_response_code(422);
    echo json_encode(['sucesso' => false, 'erro' => 'Forma de pagamento inválida.']);
    exit;
}

$caminhoCompleto = null;

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
        $stmt = $pdo->prepare("
            SELECT status_pagamento, metodo_pagamento
            FROM pedidos
            WHERE id = ? AND usuario_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$pedidoId, $usuarioId]);
        $pedidoAtual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedidoAtual) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['sucesso' => false, 'erro' => 'Pedido não encontrado.']);
            exit;
        }
        if ($pedidoAtual['status_pagamento'] === 'APROVADO') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['sucesso' => false, 'erro' => 'Este pedido já foi pago.']);
            exit;
        }
        if (
            $pedidoAtual['status_pagamento'] === 'PENDENTE' &&
            $pedidoAtual['metodo_pagamento'] !== null
        ) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'Este pagamento já foi enviado e aguarda análise.'
            ]);
            exit;
        }
        if ($pedidoAtual['status_pagamento'] === 'PENDENTE') {
            $stmt = $pdo->prepare(
                'SELECT id FROM comprovantes WHERE pedido_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$pedidoId]);
            if ($stmt->fetchColumn() !== false) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'sucesso' => false,
                    'erro' => 'Este pagamento já foi enviado e aguarda análise.'
                ]);
                exit;
            }
        }

        $stmt = $pdo->query(
            "SELECT COLUMN_NAME, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprovantes'"
        );
        $colunasComprovante = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $coluna) {
            $colunasComprovante[$coluna['COLUMN_NAME']] = $coluna['IS_NULLABLE'];
        }
        $descricaoEnviada = $payload['descricao'] ?? '';
        $descricao = is_string($descricaoEnviada)
            ? mb_substr(trim($descricaoEnviada), 0, 1000)
            : '';

        // ---------- Dinheiro ----------
        if ($metodoPagamento === 'dinheiro') {
            $stmt = $pdo->prepare("
                UPDATE pedidos
                SET metodo_pagamento = 'DINHEIRO',
                    status_pagamento = 'PENDENTE'
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$pedidoId, $usuarioId]);

            if (($colunasComprovante['arquivo'] ?? 'NO') === 'YES') {
                $campos = ['pedido_id', 'arquivo'];
                $valores = [$pedidoId, null];
                if (isset($colunasComprovante['metodo_pagamento'])) {
                    $campos[] = 'metodo_pagamento';
                    $valores[] = 'dinheiro';
                }
                if ($descricao !== '' && isset($colunasComprovante['descricao'])) {
                    $campos[] = 'descricao';
                    $valores[] = $descricao;
                }

                $placeholders = implode(', ', array_fill(0, count($campos), '?'));
                $stmt = $pdo->prepare(
                    'INSERT INTO comprovantes (' . implode(', ', $campos) . ')'
                    . " VALUES ($placeholders)"
                );
                $stmt->execute($valores);
            }

            $pdo->commit();

            echo json_encode([
                'sucesso' => true,
                'mensagem' => 'Pagamento em dinheiro registrado.',
                'status_pagamento' => 'PENDENTE',
                'pedido_id' => (int) $pedidoId
            ]);
            exit;
        }

        // ---------- PIX ----------
        $dadosComprovante = $payload['comprovante'] ?? null;
        if (!is_array($dadosComprovante) || !isset($dadosComprovante['data'])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'Nenhum comprovante foi enviado.'
            ]);
            exit;
        }

        $conteudo = is_string($dadosComprovante['data'])
            ? base64_decode($dadosComprovante['data'], true)
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
        if (str_starts_with($tipo, 'image/') && getimagesizefromstring($conteudo) === false) {
            $pdo->rollBack();
            http_response_code(415);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'A imagem enviada é inválida ou está corrompida.'
            ]);
            exit;
        }

        $extensao = $tiposPermitidos[$tipo];
        $nomeArquivo = bin2hex(random_bytes(16)) . '.' . $extensao;
        $nomeOriginal = $dadosComprovante['name'] ?? 'comprovante';
        $nomeOriginal = is_string($nomeOriginal) && trim($nomeOriginal) !== ''
            ? basename($nomeOriginal)
            : 'comprovante';

        $pastaUploads = getenv('PAYMENT_UPLOAD_DIR') ?: __DIR__ . '/uploads';
        if (!is_dir($pastaUploads) && !@mkdir($pastaUploads, 0750, true) && !is_dir($pastaUploads)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento do comprovante.');
        }

        $caminhoCompleto = rtrim($pastaUploads, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $nomeArquivo;

        if (@file_put_contents($caminhoCompleto, $conteudo, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível salvar o comprovante.');
        }
        if (!@chmod($caminhoCompleto, 0640)) {
            throw new RuntimeException('Não foi possível proteger o arquivo do comprovante.');
        }

        $caminhoBanco = 'backend/pagamentos/uploads/' . $nomeArquivo;

        $campos = ['pedido_id', 'arquivo'];
        $valores = [$pedidoId, $caminhoBanco];
        $metadados = [
            'metodo_pagamento' => 'pix',
            'descricao' => $descricao,
            'arquivo_original' => mb_substr(
                preg_replace('/[^A-Za-z0-9._ -]/', '_', $nomeOriginal),
                0,
                255
            ),
            'mime_type' => $tipo,
            'tamanho' => strlen($conteudo),
            'hash_sha256' => hash('sha256', $conteudo),
        ];
        foreach ($metadados as $coluna => $valor) {
            if ($valor !== '' && isset($colunasComprovante[$coluna])) {
                $campos[] = $coluna;
                $valores[] = $valor;
            }
        }

        $placeholders = implode(', ', array_fill(0, count($campos), '?'));
        $stmt = $pdo->prepare(
            'INSERT INTO comprovantes (' . implode(', ', $campos) . ')'
            . " VALUES ($placeholders)"
        );
        $stmt->execute($valores);

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
            'status_pagamento' => 'PENDENTE',
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
} catch (Throwable $e) {
    error_log('comprovante.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao registrar o comprovante.'
    ]);
}
