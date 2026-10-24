<?php
declare(strict_types=1);

final class PaymentController
{
    private const MAX_FILE_BYTES = 10485760;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public static function status(PDO $db): void
    {
        $userId = v2_require_user();
        $orderId = filter_input(INPUT_GET, 'pedido_id', FILTER_VALIDATE_INT);
        if (!$orderId || $orderId < 1) {
            v2_json(['sucesso' => false, 'erro' => 'Pedido inválido.'], 400);
        }

        try {
            $stmt = $db->prepare(
                'SELECT id, status_pagamento, metodo_pagamento
                 FROM pedidos WHERE id = ? AND usuario_id = ? LIMIT 1'
            );
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch();
            if (!$order) {
                v2_json(['sucesso' => false, 'erro' => 'Pedido não encontrado.'], 404);
            }

            $stmt = $db->prepare(
                'SELECT id FROM comprovantes WHERE pedido_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$orderId]);
            v2_json([
                'sucesso' => true,
                'pedido_id' => (int) $order['id'],
                'status_pagamento' => $order['status_pagamento'],
                'metodo_pagamento' => $order['metodo_pagamento'],
                'pagamento_registrado' => $order['metodo_pagamento'] !== null
                    || $stmt->fetchColumn() !== false,
            ]);
        } catch (Throwable $error) {
            v2_log_error('V2 payment status', $error);
            v2_json(['sucesso' => false, 'erro' => 'Erro ao consultar o pagamento.'], 500);
        }
    }

    public static function submit(PDO $db): void
    {
        self::requireMethod('POST');
        $userId = v2_require_user();
        $body = file_get_contents('php://input');
        if ($body === '' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            v2_json(['sucesso' => false, 'erro' => 'O envio excede o limite permitido.'], 413);
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            v2_json(['sucesso' => false, 'erro' => 'Conteúdo JSON inválido.'], 400);
        }

        $orderId = filter_var($payload['pedido_id'] ?? null, FILTER_VALIDATE_INT);
        $method = $payload['metodo_pagamento'] ?? '';
        if (!$orderId || $orderId < 1) {
            v2_json(['sucesso' => false, 'erro' => 'Pedido inválido.'], 400);
        }
        if (!in_array($method, ['pix', 'dinheiro'], true)) {
            v2_json(['sucesso' => false, 'erro' => 'Forma de pagamento inválida.'], 422);
        }

        $file = null;
        if ($method === 'pix') {
            $file = self::decodeReceipt($payload['comprovante'] ?? null);
        }
        $description = is_string($payload['descricao'] ?? null)
            ? mb_substr(trim($payload['descricao']), 0, 1000)
            : '';
        $savedPath = null;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                'SELECT status_pagamento, metodo_pagamento
                 FROM pedidos
                 WHERE id = ? AND usuario_id = ?
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$orderId, $userId]);
            $order = $stmt->fetch();

            if (!$order) {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'Pedido não encontrado.'], 404);
            }
            if ($order['status_pagamento'] === 'APROVADO') {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'Este pedido já foi pago.'], 409);
            }
            if (
                $order['status_pagamento'] === 'PENDENTE' &&
                $order['metodo_pagamento'] !== null
            ) {
                $db->rollBack();
                v2_json([
                    'sucesso' => false,
                    'erro' => 'Este pagamento já foi enviado e aguarda análise.',
                ], 409);
            }

            $filename = null;
            if ($file !== null) {
                $filename = bin2hex(random_bytes(16)) . '.' . $file['extension'];
                $directory = self::paymentDirectory();
                if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                    throw new RuntimeException('Payment storage directory could not be created.');
                }
                $savedPath = rtrim($directory, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . $filename;
                if (file_put_contents($savedPath, $file['bytes'], LOCK_EX) === false) {
                    throw new RuntimeException('Receipt file could not be saved.');
                }
                if (!chmod($savedPath, 0640)) {
                    throw new RuntimeException('Payment file permissions could not be restricted.');
                }
            }

            $stmt = $db->prepare(
                'INSERT INTO comprovantes
                    (pedido_id, arquivo, metodo_pagamento, descricao,
                     arquivo_nome, arquivo_original, mime_type, tamanho, hash_sha256)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $orderId,
                $filename === null ? null : 'backend/pagamentos/uploads/' . $filename,
                $method,
                $description !== '' ? $description : null,
                $filename,
                $file['original_name'] ?? null,
                $file['mime'] ?? null,
                $file === null ? null : strlen($file['bytes']),
                $file === null ? null : hash('sha256', $file['bytes']),
            ]);

            $stmt = $db->prepare(
                "UPDATE pedidos
                 SET metodo_pagamento = ?, status_pagamento = 'PENDENTE'
                 WHERE id = ? AND usuario_id = ?"
            );
            $stmt->execute([strtoupper($method), $orderId, $userId]);
            $db->commit();

            v2_json([
                'sucesso' => true,
                'mensagem' => $method === 'dinheiro'
                    ? 'Pagamento em dinheiro registrado.'
                    : 'Comprovante enviado com sucesso.',
                'status_pagamento' => 'PENDENTE',
                'pedido_id' => (int) $orderId,
            ]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($savedPath !== null && is_file($savedPath)) {
                unlink($savedPath);
            }
            v2_log_error('V2 payment submission', $error);
            v2_json(['sucesso' => false, 'erro' => 'Erro ao registrar o pagamento.'], 500);
        }
    }

    public static function decide(PDO $db, string $status): void
    {
        self::requireMethod('POST');
        $adminId = v2_require_admin($db);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $orderId = filter_var(
            is_array($payload) ? ($payload['id'] ?? null) : null,
            FILTER_VALIDATE_INT
        );
        if (!$orderId || $orderId < 1) {
            v2_json(['sucesso' => false, 'erro' => 'ID do pedido inválido.'], 400);
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                'SELECT status_pagamento FROM pedidos WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$orderId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'Pedido não encontrado.'], 404);
            }
            if ($current !== 'PENDENTE') {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'Este pedido já foi analisado.'], 409);
            }

            $stmt = $db->prepare(
                'UPDATE pedidos SET status_pagamento = ? WHERE id = ?'
            );
            $stmt->execute([$status, $orderId]);

            $proofStatus = $status === 'APROVADO' ? 'aprovado' : 'recusado';
            $stmt = $db->prepare(
                'SELECT id FROM comprovantes WHERE pedido_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$orderId]);
            $proofId = $stmt->fetchColumn();
            if ($proofId !== false) {
                $stmt = $db->prepare(
                    'UPDATE comprovantes
                     SET status = ?, analisado_por = ?, analisado_em = NOW()
                     WHERE id = ?'
                );
                $stmt->execute([$proofStatus, $adminId, $proofId]);
            }

            $db->commit();
            v2_json([
                'sucesso' => true,
                'mensagem' => $status === 'APROVADO'
                    ? 'Pagamento aprovado.'
                    : 'Pagamento recusado.',
            ]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            v2_log_error('V2 payment decision', $error);
            v2_json(['sucesso' => false, 'erro' => 'Erro ao atualizar o pagamento.'], 500);
        }
    }

    public static function viewReceipt(PDO $db): void
    {
        v2_require_admin($db);
        $proofId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$proofId || $proofId < 1) {
            http_response_code(400);
            exit('Comprovante inválido.');
        }

        $stmt = $db->prepare(
            'SELECT arquivo, arquivo_nome, mime_type
             FROM comprovantes WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$proofId]);
        $proof = $stmt->fetch();
        if (!$proof) {
            http_response_code(404);
            exit('Comprovante não encontrado.');
        }

        $candidates = [];
        if (!empty($proof['arquivo_nome'])) {
            $filename = basename((string) $proof['arquivo_nome']);
            $candidates[] = self::paymentDirectory() . DIRECTORY_SEPARATOR . $filename;
            $candidates[] = dirname(__DIR__, 2) . '/pagamentos/uploads/' . $filename;
            $candidates[] = dirname(__DIR__, 2) . '/pagamento_backend/storage/comprovantes/' . $filename;
        } elseif (!empty($proof['arquivo'])) {
            $filename = basename((string) $proof['arquivo']);
            $candidates[] = self::paymentDirectory() . DIRECTORY_SEPARATOR . $filename;
            $candidates[] = dirname(__DIR__, 2) . '/pagamentos/uploads/' . $filename;
            $candidates[] = dirname(__DIR__, 2) . '/pagamento_backend/storage/comprovantes/' . $filename;
        }

        $path = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
            http_response_code(404);
            exit('Arquivo do comprovante não encontrado.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            http_response_code(415);
            exit('Tipo de arquivo não permitido.');
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Disposition: inline; filename="comprovante-' . $proofId . '.' . self::MIME_EXTENSIONS[$mime] . '"');
        readfile($path);
        exit;
    }

    private static function decodeReceipt(mixed $receipt): array
    {
        if (!is_array($receipt) || !is_string($receipt['data'] ?? null)) {
            v2_json(['sucesso' => false, 'erro' => 'Nenhum comprovante foi enviado.'], 400);
        }
        if (strlen($receipt['data']) > (int) ceil(self::MAX_FILE_BYTES * 4 / 3) + 4) {
            v2_json(['sucesso' => false, 'erro' => 'O arquivo deve ter até 10 MB.'], 413);
        }
        $bytes = base64_decode($receipt['data'], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_FILE_BYTES) {
            v2_json(['sucesso' => false, 'erro' => 'O arquivo deve ter até 10 MB.'], 400);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) {
            v2_json(['sucesso' => false, 'erro' => 'Tipo de arquivo não permitido.'], 415);
        }
        if (str_starts_with($mime, 'image/') && @getimagesizefromstring($bytes) === false) {
            v2_json(['sucesso' => false, 'erro' => 'A imagem enviada é inválida ou está corrompida.'], 415);
        }

        $name = is_string($receipt['name'] ?? null) ? basename($receipt['name']) : 'comprovante';

        return [
            'bytes' => $bytes,
            'mime' => $mime,
            'extension' => self::MIME_EXTENSIONS[$mime],
            'original_name' => mb_substr(
                preg_replace('/[^A-Za-z0-9._ -]/', '_', $name),
                0,
                255
            ),
        ];
    }

    private static function paymentDirectory(): string
    {
        return rtrim(
            v2_config('PAYMENT_UPLOAD_DIR')
                ?? dirname(__DIR__, 2) . '/pagamentos/uploads',
            DIRECTORY_SEPARATOR
        );
    }

    private static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            v2_json(['sucesso' => false, 'erro' => 'Método não permitido.'], 405);
        }
    }
}
