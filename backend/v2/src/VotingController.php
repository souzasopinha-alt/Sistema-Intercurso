<?php
declare(strict_types=1);

final class VotingController
{
    public static function vote(PDO $db): void
    {
        $userId = v2_require_user();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            self::voteState($db, $userId);
        } elseif ($method === 'POST') {
            self::castVote($db, $userId);
        } else {
            header('Allow: GET, POST');
            v2_json(['success' => false, 'message' => 'Método não permitido.'], 405);
        }
    }

    public static function listOwnProposals(PDO $db): void
    {
        $userId = v2_require_user();
        try {
            $stmt = $db->prepare(
                'SELECT id, nome, imagem1, imagem2, imagem3, aprovado
                 FROM opcoes WHERE usuario_id = ?
                 ORDER BY created_at ASC, id ASC'
            );
            $stmt->execute([$userId]);
            v2_json(['success' => true, 'message' => '', 'opcoes' => $stmt->fetchAll()]);
        } catch (Throwable $error) {
            v2_log_error('V2 own proposals', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível carregar suas propostas.'], 500);
        }
    }

    public static function submissionState(PDO $db): void
    {
        $userId = v2_require_user();
        try {
            $stmt = $db->prepare(
                "SELECT
                    (SELECT valor FROM configuracoes WHERE chave = 'envios_abertos' LIMIT 1) AS envios_abertos,
                    envios_finalizados
                 FROM usuarios WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$userId]);
            $state = $stmt->fetch();
            if (!$state) {
                v2_json(['success' => false, 'message' => 'Usuário não encontrado.'], 404);
            }
            v2_json([
                'success' => true,
                'message' => '',
                'envios_abertos' => $state['envios_abertos'] === '1',
                'envios_finalizados' => (int) $state['envios_finalizados'] === 1,
            ]);
        } catch (Throwable $error) {
            v2_log_error('V2 submission state', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível consultar o período de envio.'], 500);
        }
    }

    public static function finalizeSubmissions(PDO $db): void
    {
        self::requirePost();
        $userId = v2_require_user();
        try {
            $db->beginTransaction();
            $stmt = $db->query(
                "SELECT valor FROM configuracoes WHERE chave = 'envios_abertos' FOR UPDATE"
            );
            if ($stmt->fetchColumn() !== '1') {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'O período de envio foi encerrado.'], 403);
            }

            $stmt = $db->prepare(
                'SELECT envios_finalizados FROM usuarios WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $finalized = $stmt->fetchColumn();
            if ($finalized === false) {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'Usuário não encontrado.'], 404);
            }

            $stmt = $db->prepare('SELECT COUNT(*) FROM opcoes WHERE usuario_id = ?');
            $stmt->execute([$userId]);
            if ((int) $stmt->fetchColumn() === 0) {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'Você ainda não enviou nenhuma proposta.'], 400);
            }

            $stmt = $db->prepare(
                'UPDATE usuarios SET envios_finalizados = 1 WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $db->commit();
            v2_json(['success' => true, 'message' => 'Propostas finalizadas com sucesso.']);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            v2_log_error('V2 finalize submissions', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível finalizar os envios.'], 500);
        }
    }

    public static function submitProposal(PDO $db, bool $admin): void
    {
        self::requirePost();
        $userId = $admin ? v2_require_admin($db) : v2_require_user();
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            v2_json(['success' => false, 'message' => 'Conteúdo JSON inválido.'], 400);
        }

        $name = trim((string) ($payload['nome'] ?? ''));
        if ($admin && ($name === '' || mb_strlen($name) > 100)) {
            v2_json(['success' => false, 'message' => 'Informe um nome de até 100 caracteres para a opção.'], 400);
        }

        try {
            $images = self::decodeImages($payload, $admin);
        } catch (InvalidArgumentException $error) {
            v2_json(['success' => false, 'message' => $error->getMessage()], 400);
        }

        $saved = [];
        try {
            $db->beginTransaction();
            if ($admin) {
                $isOpen = $db->query(
                    "SELECT valor FROM configuracoes WHERE chave = 'votacao_aberta' FOR UPDATE"
                )->fetchColumn() === '1';
                if ($isOpen) {
                    $db->rollBack();
                    v2_json(['success' => false, 'message' => 'Encerre a votação antes de adicionar uma nova opção.'], 409);
                }
            } else {
                $isOpen = $db->query(
                    "SELECT valor FROM configuracoes WHERE chave = 'envios_abertos' FOR UPDATE"
                )->fetchColumn() === '1';
                if (!$isOpen) {
                    $db->rollBack();
                    v2_json(['success' => false, 'message' => 'O período de envio de camisas foi encerrado.'], 403);
                }
                $stmt = $db->prepare(
                    'SELECT nome, envios_finalizados FROM usuarios WHERE id = ? FOR UPDATE'
                );
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                if (!$user) {
                    $db->rollBack();
                    v2_json(['success' => false, 'message' => 'Usuário não encontrado.'], 401);
                }
                if ((int) $user['envios_finalizados'] === 1) {
                    $db->rollBack();
                    v2_json(['success' => false, 'message' => 'Você já finalizou suas propostas. Não é possível enviar mais.'], 403);
                }
                $name = (string) $user['nome'];
            }

            $relativeDirectory = ($admin ? 'admin/' : 'usuarios/')
                . $userId . '/' . bin2hex(random_bytes(12));
            $baseDirectory = self::imageDirectory();
            $absoluteDirectory = $baseDirectory . DIRECTORY_SEPARATOR
                . $relativeDirectory;
            if (
                !is_dir($absoluteDirectory) &&
                !mkdir($absoluteDirectory, 0750, true) &&
                !is_dir($absoluteDirectory)
            ) {
                throw new RuntimeException('Proposal image directory could not be created.');
            }

            $paths = [];
            foreach ($images as $field => $image) {
                $filename = $field . '.' . $image['extension'];
                $path = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
                if (file_put_contents($path, $image['bytes'], LOCK_EX) === false) {
                    throw new RuntimeException('Proposal image could not be saved.');
                }
                if (!chmod($path, 0640)) {
                    throw new RuntimeException('Proposal image permissions could not be restricted.');
                }
                $saved[] = $path;
                $paths[$field] = 'uploads/camisas/' . $relativeDirectory . '/' . $filename;
            }

            $stmt = $db->prepare(
                'INSERT INTO opcoes (usuario_id, nome, imagem1, imagem2, imagem3, aprovado)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $name,
                $paths['imagem1'],
                $paths['imagem2'],
                $paths['imagem3'],
                $admin ? 1 : 0,
            ]);
            $optionId = (int) $db->lastInsertId();
            $db->commit();

            v2_json([
                'success' => true,
                'message' => $admin ? 'Opção adicionada à votação.' : 'As três imagens foram salvas com sucesso.',
                'opcao_id' => $optionId,
                'imagens' => $paths,
            ]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            foreach ($saved as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            v2_log_error('V2 proposal upload', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível salvar a proposta.'], 500);
        }
    }

    public static function adminResults(PDO $db): void
    {
        v2_require_admin($db);
        try {
            $stmt = $db->query(
                "SELECT o.id, o.usuario_id, o.nome, o.imagem1, o.imagem2, o.imagem3,
                        u.tipo AS tipo_criador, COUNT(v.id) AS total_votos
                 FROM opcoes o
                 JOIN usuarios u ON u.id = o.usuario_id
                 LEFT JOIN votos_realizados v ON v.opcao_id = o.id
                 WHERE o.aprovado = 1
                 GROUP BY o.id, o.usuario_id, u.tipo, o.nome, o.imagem1, o.imagem2, o.imagem3
                 ORDER BY o.id ASC"
            );
            $options = [];
            foreach ($stmt->fetchAll() as $index => $row) {
                $number = $index + 1;
                $options[] = [
                    'id' => (int) $row['id'],
                    'usuario_id' => (int) $row['usuario_id'],
                    'criado_por_admin' => strtoupper((string) $row['tipo_criador']) === 'ADMIN',
                    'numero' => $number,
                    'nome' => $row['nome'] ?: 'Opção ' . $number,
                    'imagem1' => $row['imagem1'],
                    'imagem2' => $row['imagem2'],
                    'imagem3' => $row['imagem3'],
                    'total_votos' => (int) $row['total_votos'],
                ];
            }
            v2_json(['success' => true, 'message' => '', 'opcoes' => $options]);
        } catch (Throwable $error) {
            v2_log_error('V2 admin vote results', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível carregar os votos.'], 500);
        }
    }

    public static function deleteAdminOption(PDO $db): void
    {
        self::requirePost();
        v2_require_admin($db);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $optionId = filter_var(is_array($payload) ? ($payload['id'] ?? null) : null, FILTER_VALIDATE_INT);
        if (!$optionId || $optionId < 1) {
            v2_json(['success' => false, 'message' => 'Opção inválida.'], 400);
        }

        try {
            $db->beginTransaction();
            if ($db->query(
                "SELECT valor FROM configuracoes WHERE chave = 'votacao_aberta' FOR UPDATE"
            )->fetchColumn() === '1') {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'Encerre a votação antes de excluir uma opção.'], 409);
            }
            $stmt = $db->prepare(
                "SELECT o.usuario_id, o.imagem1, o.imagem2, o.imagem3
                 FROM opcoes o JOIN usuarios u ON u.id = o.usuario_id
                 WHERE o.id = ? AND UPPER(u.tipo) = 'ADMIN' FOR UPDATE"
            );
            $stmt->execute([$optionId]);
            $option = $stmt->fetch();
            if (!$option) {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'Opção administrativa não encontrada.'], 404);
            }
            $db->prepare('DELETE FROM votos_realizados WHERE opcao_id = ?')->execute([$optionId]);
            $db->prepare('DELETE FROM opcoes WHERE id = ?')->execute([$optionId]);
            $db->commit();

            self::removeAdminImages($option);
            v2_json(['success' => true, 'message' => 'Opção administrativa excluída.']);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            v2_log_error('V2 delete admin option', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível excluir a opção.'], 500);
        }
    }

    private static function voteState(PDO $db, int $userId): void
    {
        try {
            $isOpen = $db->query(
                "SELECT valor FROM configuracoes WHERE chave = 'votacao_aberta' LIMIT 1"
            )->fetchColumn() === '1';
            $stmt = $db->query(
                "SELECT o.id, o.nome, u.tipo AS tipo_criador, o.imagem1, o.imagem2, o.imagem3
                 FROM opcoes o JOIN usuarios u ON u.id = o.usuario_id
                 WHERE o.aprovado = 1 ORDER BY o.id ASC"
            );
            $options = $stmt->fetchAll();
            $stmt = $db->prepare(
                'SELECT 1 FROM votos_realizados WHERE usuario_id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $hasVoted = (bool) $stmt->fetchColumn();
            foreach ($options as $index => &$option) {
                $option['numero'] = $index + 1;
                $option['rotulo'] = strtoupper((string) $option['tipo_criador']) === 'ADMIN'
                    ? $option['nome']
                    : null;
                unset($option['tipo_criador'], $option['nome']);
            }
            unset($option);
            v2_json([
                'success' => true,
                'message' => '',
                'opcoes' => $options,
                'ja_votou' => $hasVoted,
                'votacao_aberta' => $isOpen,
            ]);
        } catch (Throwable $error) {
            v2_log_error('V2 voting state', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível carregar a votação.'], 500);
        }
    }

    private static function castVote(PDO $db, int $userId): void
    {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $optionId = filter_var(is_array($payload) ? ($payload['opcao_id'] ?? null) : null, FILTER_VALIDATE_INT);
        if (!$optionId || $optionId < 1) {
            v2_json(['success' => false, 'message' => 'Opção inválida.'], 400);
        }
        try {
            $db->beginTransaction();
            if ($db->query(
                "SELECT valor FROM configuracoes WHERE chave = 'votacao_aberta' FOR UPDATE"
            )->fetchColumn() !== '1') {
                $db->rollBack();
                v2_json([
                    'success' => false,
                    'message' => 'A votação está encerrada.',
                    'votacao_aberta' => false,
                ], 403);
            }
            $stmt = $db->prepare('SELECT id FROM opcoes WHERE id = ? AND aprovado = 1 LIMIT 1');
            $stmt->execute([$optionId]);
            if (!$stmt->fetchColumn()) {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'Essa opção não está disponível.'], 400);
            }
            $db->prepare(
                'INSERT INTO votos_realizados (usuario_id, opcao_id) VALUES (?, ?)'
            )->execute([$userId, $optionId]);
            $db->commit();
            v2_json(['success' => true, 'message' => 'Voto registrado com sucesso.']);
        } catch (PDOException $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                v2_json(['success' => false, 'message' => 'Você já votou. Cada aluno pode votar uma vez.'], 409);
            }
            v2_log_error('V2 cast vote', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível concluir a operação.'], 500);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            v2_log_error('V2 cast vote', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível concluir a operação.'], 500);
        }
    }

    private static function decodeImages(array $payload, bool $admin): array
    {
        $allowed = $admin
            ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']
            : [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $images = [];
        foreach (['imagem1', 'imagem2', 'imagem3'] as $field) {
            $encoded = $payload[$field]['data'] ?? null;
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($bytes === false) {
                throw new InvalidArgumentException("A imagem {$field} não foi recebida corretamente.");
            }
            $mime = $finfo->buffer($bytes);
            if (
                $bytes === '' ||
                strlen($bytes) > 10 * 1024 * 1024 ||
                !is_string($mime) ||
                !isset($allowed[$mime]) ||
                @getimagesizefromstring($bytes) === false
            ) {
                throw new InvalidArgumentException("Formato inválido ou tamanho excedido para {$field}.");
            }
            $images[$field] = ['bytes' => $bytes, 'extension' => $allowed[$mime]];
        }

        return $images;
    }

    private static function removeAdminImages(array $option): void
    {
        $base = realpath(self::imageDirectory());
        if ($base === false) {
            return;
        }
        foreach (['imagem1', 'imagem2', 'imagem3'] as $field) {
            $relative = (string) $option[$field];
            if (!str_starts_with($relative, 'uploads/camisas/admin/')) {
                continue;
            }
            $file = realpath($base . DIRECTORY_SEPARATOR . substr($relative, strlen('uploads/camisas/')));
            if ($file !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
                if (!unlink($file)) {
                    error_log('V2 could not remove an admin proposal image after deletion.');
                }
            }
        }
    }

    private static function imageDirectory(): string
    {
        return rtrim(
            v2_config('CAMISAS_UPLOAD_DIR')
                ?? dirname(__DIR__, 2) . '/uploads/camisas',
            DIRECTORY_SEPARATOR
        );
    }

    private static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            v2_json(['success' => false, 'message' => 'Método não permitido.'], 405);
        }
    }
}
