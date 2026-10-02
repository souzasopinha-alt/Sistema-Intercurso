<?php
declare(strict_types=1);

final class ComprovanteService
{
    public function __construct(private PDO $db) {}

    /* ------------------------------------------------------------------
     * CLIENTE: envia o comprovante (PIX) ou registra pagamento em dinheiro
     * ------------------------------------------------------------------ */
    public function enviar(int $pedidoId, int $usuarioId, array $post, ?array $arquivo): array
    {
        $metodo = $post['metodo_pagamento'] ?? 'pix';
        if (!in_array($metodo, ['pix', 'dinheiro'], true)) {
            throw new HttpException(422, 'Forma de pagamento inválida.');
        }
        $descricao = mb_substr(trim((string) ($post['descricao'] ?? '')), 0, 1000);

        // Valida o arquivo ANTES de abrir transação (PIX exige comprovante).
        $info = $metodo === 'pix' ? $this->validarArquivo($arquivo) : null;
        $nomeEmDisco = null;

        $this->db->beginTransaction();
        try {
            $pedido = $this->buscarPedido($pedidoId, true); // FOR UPDATE: evita envio duplo simultâneo

            if ((int) $pedido['usuario_id'] !== $usuarioId) {
                throw new HttpException(403, 'Este pedido não pertence à sua conta.');
            }
            if ($pedido['status_pagamento'] === 'aprovado') {
                throw new HttpException(409, 'O pagamento deste pedido já foi aprovado.');
            }
            if ($pedido['status_pagamento'] === 'em_analise') {
                throw new HttpException(409, 'Já existe um pagamento em análise para este pedido.');
            }

            if ($info !== null) {
                $nomeEmDisco = $this->salvarArquivo($arquivo['tmp_name'], $info['ext']);
            }

            $this->db->prepare(
                'INSERT INTO comprovantes
                   (pedido_id, metodo_pagamento, descricao, arquivo_nome, arquivo_original, mime_type, tamanho, hash_sha256)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $pedidoId,
                $metodo,
                $descricao !== '' ? $descricao : null,
                $nomeEmDisco,
                $info ? $this->nomeOriginalSeguro((string) $arquivo['name']) : null,
                $info['mime'] ?? null,
                $info['size'] ?? null,
                $info['hash'] ?? null,
            ]);
            $comprovanteId = (int) $this->db->lastInsertId();

            $this->db->prepare("UPDATE pedidos SET status_pagamento = 'em_analise' WHERE id = ?")
                     ->execute([$pedidoId]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($nomeEmDisco !== null && is_file($this->caminho($nomeEmDisco))) {
                unlink($this->caminho($nomeEmDisco)); // não deixa arquivo órfão
            }
            throw $e;
        }

        return [
            'id'               => $comprovanteId,
            'pedido_id'        => $pedidoId,
            'metodo_pagamento' => $metodo,
            'status_pagamento' => 'em_analise',
        ];
    }

    /* ------------------------------------------------------------------
     * CLIENTE (dono) ou ADMIN: consulta o status do pagamento do pedido
     * ------------------------------------------------------------------ */
    public function status(int $pedidoId, int $usuarioId, bool $admin): array
    {
        $pedido = $this->buscarPedido($pedidoId);
        if (!$admin && (int) $pedido['usuario_id'] !== $usuarioId) {
            throw new HttpException(403, 'Este pedido não pertence à sua conta.');
        }

        $st = $this->db->prepare(
            'SELECT id, metodo_pagamento, status, motivo_recusa, criado_em, analisado_em
               FROM comprovantes WHERE pedido_id = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$pedidoId]);

        return [
            'pedido_id'        => $pedidoId,
            'status_pagamento' => $pedido['status_pagamento'],
            'comprovante'      => $st->fetch() ?: null,
        ];
    }

    /* ------------------------------------------------------------------
     * ADMIN: lista comprovantes (os "em análise" aparecem primeiro)
     * ------------------------------------------------------------------ */
    public function listar(?string $status): array
    {
        $validos = ['em_analise', 'aprovado', 'recusado'];
        if ($status !== null && $status !== '' && !in_array($status, $validos, true)) {
            throw new HttpException(422, 'Status inválido.');
        }

        $sql = "SELECT c.id, c.pedido_id, c.metodo_pagamento, c.descricao, c.arquivo_original,
                       c.mime_type, c.tamanho, c.status, c.motivo_recusa, c.criado_em, c.analisado_em,
                       (c.arquivo_nome IS NOT NULL) AS tem_arquivo,
                       p.total, p.usuario_id,
                       (SELECT COUNT(*) FROM comprovantes d
                         WHERE c.hash_sha256 IS NOT NULL
                           AND d.hash_sha256 = c.hash_sha256 AND d.id <> c.id) AS duplicados
                  FROM comprovantes c
                  JOIN pedidos p ON p.id = c.pedido_id";
        $params = [];
        if ($status) {
            $sql .= ' WHERE c.status = ?';
            $params[] = $status;
        }
        $sql .= " ORDER BY FIELD(c.status, 'em_analise', 'recusado', 'aprovado'), c.criado_em DESC LIMIT 200";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /* ------------------------------------------------------------------
     * ADMIN: aprova ou recusa o pagamento mais recente em análise
     * ------------------------------------------------------------------ */
    public function decidir(int $pedidoId, string $acao, ?string $motivo, int $adminId): array
    {
        if (!in_array($acao, ['aprovar', 'recusar'], true)) {
            throw new HttpException(422, "Ação inválida. Use 'aprovar' ou 'recusar'.");
        }
        $motivo = $motivo !== null ? mb_substr(trim($motivo), 0, 255) : null;
        if ($acao === 'recusar' && mb_strlen((string) $motivo) < 3) {
            throw new HttpException(422, 'Informe o motivo da recusa.');
        }

        $this->db->beginTransaction();
        try {
            $this->buscarPedido($pedidoId, true);

            $st = $this->db->prepare(
                "SELECT id FROM comprovantes
                  WHERE pedido_id = ? AND status = 'em_analise'
                  ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $st->execute([$pedidoId]);
            $comprovanteId = $st->fetchColumn();
            if ($comprovanteId === false) {
                throw new HttpException(409, 'Não há pagamento em análise para este pedido.');
            }

            $novo = $acao === 'aprovar' ? 'aprovado' : 'recusado';

            $this->db->prepare(
                'UPDATE comprovantes
                    SET status = ?, motivo_recusa = ?, analisado_por = ?, analisado_em = NOW()
                  WHERE id = ?'
            )->execute([$novo, $acao === 'recusar' ? $motivo : null, $adminId, $comprovanteId]);

            // Se o seu pedido tem uma coluna de status geral (ex.: 'pago'), atualize-a aqui também.
            $this->db->prepare('UPDATE pedidos SET status_pagamento = ? WHERE id = ?')
                     ->execute([$novo, $pedidoId]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['pedido_id' => $pedidoId, 'comprovante_id' => (int) $comprovanteId, 'status_pagamento' => $novo];
    }

    /* ------------------------------------------------------------------
     * ADMIN: entrega o arquivo (imagem/PDF) para visualização
     * ------------------------------------------------------------------ */
    public function entregarArquivo(int $pedidoId, ?int $comprovanteId): never
    {
        $sql = 'SELECT arquivo_nome, mime_type, tamanho FROM comprovantes
                 WHERE pedido_id = ? AND arquivo_nome IS NOT NULL';
        $params = [$pedidoId];
        if ($comprovanteId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $comprovanteId;
        }
        $st = $this->db->prepare($sql . ' ORDER BY id DESC LIMIT 1');
        $st->execute($params);
        $c = $st->fetch();

        $ext = $c ? (config('upload')['mimes'][$c['mime_type']] ?? null) : null;
        $caminho = $c ? $this->caminho($c['arquivo_nome']) : null;
        if (!$c || !$ext || !is_file($caminho)) {
            throw new HttpException(404, 'Comprovante não encontrado.');
        }

        header('Content-Type: ' . $c['mime_type']);
        header('Content-Length: ' . filesize($caminho));
        header("Content-Disposition: inline; filename=\"comprovante-pedido-{$pedidoId}.{$ext}\"");
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($caminho);
        exit;
    }

    /* ======================= internos ======================= */

    private function buscarPedido(int $id, bool $travar = false): array
    {
        $st = $this->db->prepare(
            'SELECT id, usuario_id, total, status_pagamento FROM pedidos WHERE id = ?' . ($travar ? ' FOR UPDATE' : '')
        );
        $st->execute([$id]);
        return $st->fetch() ?: throw new HttpException(404, 'Pedido não encontrado.');
    }

    /** Valida pelo CONTEÚDO real do arquivo (não confia em extensão nem no tipo enviado pelo navegador). */
    private function validarArquivo(?array $f): array
    {
        $cfg = config('upload');

        if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new HttpException(422, 'Anexe o comprovante antes de confirmar o envio.');
        }
        match ($f['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new HttpException(413, 'O arquivo excede o limite de 10 MB.'),
            default => throw new HttpException(400, 'Falha ao receber o arquivo. Tente novamente.'),
        };
        if (!is_uploaded_file($f['tmp_name'])) {
            throw new HttpException(400, 'Upload inválido.');
        }
        if ($f['size'] > $cfg['max_bytes']) {
            throw new HttpException(413, 'O arquivo excede o limite de 10 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset($cfg['mimes'][$mime])) {
            throw new HttpException(415, 'Formato não permitido. Envie PNG, JPG, WEBP ou PDF.');
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($f['tmp_name']) === false) {
            throw new HttpException(415, 'A imagem enviada está corrompida ou é inválida.');
        }

        return [
            'mime' => $mime,
            'ext'  => $cfg['mimes'][$mime],
            'size' => (int) $f['size'],
            'hash' => hash_file('sha256', $f['tmp_name']),
        ];
    }

    private function salvarArquivo(string $tmp, string $ext): string
    {
        $dir = config('upload')['dir'];
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de comprovantes.');
        }
        $nome = bin2hex(random_bytes(16)) . '.' . $ext; // nome aleatório: nunca usa o nome do cliente
        if (!move_uploaded_file($tmp, $this->caminho($nome))) {
            throw new RuntimeException('Não foi possível salvar o comprovante.');
        }
        chmod($this->caminho($nome), 0640);
        return $nome;
    }

    private function caminho(string $nome): string
    {
        return config('upload')['dir'] . '/' . basename($nome);
    }

    private function nomeOriginalSeguro(string $nome): string
    {
        return mb_substr(preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '_', basename($nome)), 0, 255);
    }
}
