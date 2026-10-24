<?php
declare(strict_types=1);

final class AdminController
{
    public static function orders(PDO $db, bool $simple): void
    {
        v2_require_admin($db);
        try {
            if ($simple) {
                $sql = 'SELECT p.id, u.nome AS aluno, u.email, p.modelo, p.numero,
                               p.tamanho, p.nome_camisa, p.status_pagamento, p.data_pedido
                        FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id';
                $params = [];
                $filters = [];
                foreach (['status' => 'status_pagamento', 'modelo' => 'modelo', 'tamanho' => 'tamanho'] as $input => $column) {
                    $value = trim((string) ($_GET[$input] ?? ''));
                    if ($value !== '') {
                        $filters[] = "p.{$column} = ?";
                        $params[] = $value;
                    }
                }
                if ($filters !== []) {
                    $sql .= ' WHERE ' . implode(' AND ', $filters);
                }
                $sql .= ' ORDER BY p.data_pedido DESC';
            } else {
                $sql = 'SELECT p.id, u.nome AS aluno, u.email, p.modelo, p.numero,
                               p.tamanho, p.nome_camisa, p.data_pedido,
                               p.status_pagamento, p.metodo_pagamento,
                               c.id AS comprovante_id,
                               COALESCE(c.arquivo, c.arquivo_nome) AS comprovante_arquivo,
                               c.data_envio AS comprovante_data
                        FROM pedidos p
                        JOIN usuarios u ON u.id = p.usuario_id
                        LEFT JOIN comprovantes c ON c.id = (
                            SELECT c2.id FROM comprovantes c2
                            WHERE c2.pedido_id = p.id
                            ORDER BY c2.data_envio DESC, c2.id DESC LIMIT 1
                        )
                        WHERE 1 = 1';
                $params = [];
                foreach (['status' => 'status_pagamento', 'modelo' => 'modelo', 'tamanho' => 'tamanho'] as $input => $column) {
                    $value = trim((string) ($_GET[$input] ?? ''));
                    if ($value !== '') {
                        $sql .= " AND p.{$column} = ?";
                        $params[] = $value;
                    }
                }
                $sql .= ' ORDER BY p.data_pedido DESC';
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            v2_json($stmt->fetchAll());
        } catch (Throwable $error) {
            v2_log_error('V2 admin orders', $error);
            v2_json(['erro' => 'Erro ao buscar os pedidos.'], 500);
        }
    }

    public static function summary(PDO $db): void
    {
        v2_require_admin($db);
        try {
            $counts = $db->query(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(status_pagamento = 'PENDENTE'), 0) AS pendentes,
                        COALESCE(SUM(status_pagamento = 'APROVADO'), 0) AS aprovados,
                        COALESCE(SUM(status_pagamento = 'RECUSADO'), 0) AS recusados
                 FROM pedidos"
            )->fetch();
            $byModel = $db->query(
                'SELECT modelo, COUNT(*) AS quantidade FROM pedidos
                 GROUP BY modelo ORDER BY quantidade DESC'
            )->fetchAll();
            $bySize = $db->query(
                'SELECT tamanho, COUNT(*) AS quantidade FROM pedidos
                 GROUP BY tamanho ORDER BY quantidade DESC'
            )->fetchAll();
            $byStatus = $db->query(
                'SELECT status_pagamento, COUNT(*) AS quantidade
                 FROM pedidos GROUP BY status_pagamento'
            )->fetchAll();

            v2_json([
                'total' => (int) $counts['total'],
                'pendentes' => (int) $counts['pendentes'],
                'aprovados' => (int) $counts['aprovados'],
                'recusados' => (int) $counts['recusados'],
                'por_modelo' => $byModel,
                'por_tamanho' => $bySize,
                'por_status' => $byStatus,
            ]);
        } catch (Throwable $error) {
            v2_log_error('V2 admin summary', $error);
            v2_json(['erro' => 'Erro ao buscar dados do painel.'], 500);
        }
    }

    public static function users(PDO $db): void
    {
        v2_require_admin($db);
        try {
            $rows = $db->query(
                "SELECT u.id, u.nome, u.email, u.tipo,
                        CASE WHEN ea.utilizado = 1 THEN 'Cadastrado' ELSE 'Autorizado' END AS situacao
                 FROM usuarios u LEFT JOIN emails_autorizados ea
                 ON ea.id = u.email_autorizado_id ORDER BY u.nome ASC"
            )->fetchAll();
            v2_json($rows);
        } catch (Throwable $error) {
            v2_log_error('V2 admin users', $error);
            v2_json(['erro' => 'Erro ao carregar usuários.'], 500);
        }
    }

    public static function state(PDO $db, string $which): void
    {
        v2_require_admin($db);
        $keys = $which === 'votacao'
            ? ['envios_abertos', 'votacao_aberta']
            : ['envios_abertos', 'votacao_aberta', 'pedidos_abertos'];
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare(
                "SELECT chave, valor FROM configuracoes WHERE chave IN ({$placeholders})"
            );
            $stmt->execute($keys);
            $values = array_fill_keys($keys, '0');
            foreach ($stmt->fetchAll() as $row) {
                $values[$row['chave']] = $row['valor'];
            }
            $result = [];
            foreach ($values as $key => $value) {
                $result[$key] = $value === '1';
            }
            v2_json(['success' => true, 'message' => ''] + $result);
        } catch (Throwable $error) {
            v2_log_error('V2 admin state', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível consultar o estado.'], 500);
        }
    }

    public static function changeState(PDO $db, string $action): void
    {
        self::requirePost();
        v2_require_admin($db);
        $messages = [
            'iniciar_votacao' => 'Envios encerrados e votação iniciada.',
            'encerrar_votacao' => 'Votação encerrada com sucesso.',
            'reabrir_envios' => 'Envios reabertos com sucesso.',
            'reabrir_votacao' => 'Votação reaberta com sucesso.',
            'iniciar_pedidos' => 'Pedidos abertos com sucesso.',
            'encerrar_pedidos' => 'Pedidos encerrados com sucesso.',
            'reabrir_pedidos' => 'Pedidos reabertos com sucesso.',
        ];
        if (!isset($messages[$action])) {
            v2_json(['success' => false, 'message' => 'Ação inválida.'], 404);
        }

        try {
            $db->beginTransaction();
            $stmt = $db->query(
                "SELECT chave, valor FROM configuracoes
                 WHERE chave IN ('envios_abertos','votacao_aberta','pedidos_abertos')
                 FOR UPDATE"
            );
            $state = [
                'envios_abertos' => '0',
                'votacao_aberta' => '0',
                'pedidos_abertos' => '0',
            ];
            foreach ($stmt->fetchAll() as $row) {
                $state[$row['chave']] = $row['valor'];
            }

            $conflict = static function (string $message) use ($db): void {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                v2_json(['success' => false, 'message' => $message], 409);
            };
            switch ($action) {
                case 'iniciar_votacao':
                    if ($state['votacao_aberta'] === '1') $conflict('A votação já foi iniciada.');
                    if ($state['envios_abertos'] !== '1') $conflict('Os envios já foram encerrados.');
                    if ($state['pedidos_abertos'] === '1') $conflict('Encerre os pedidos antes de iniciar a votação.');
                    if ((int) $db->query('SELECT COUNT(*) FROM opcoes')->fetchColumn() === 0) {
                        $conflict('Ainda não há propostas enviadas.');
                    }
                    $db->exec('UPDATE opcoes SET aprovado = 1');
                    self::setSetting($db, 'envios_abertos', '0');
                    self::setSetting($db, 'votacao_aberta', '1');
                    break;
                case 'encerrar_votacao':
                    if ($state['votacao_aberta'] !== '1') $conflict('A votação já está encerrada.');
                    self::setSetting($db, 'votacao_aberta', '0');
                    break;
                case 'reabrir_envios':
                    if ($state['votacao_aberta'] === '1') $conflict('Encerre a votação antes de reabrir os envios.');
                    if ($state['pedidos_abertos'] === '1') $conflict('Encerre os pedidos antes de reabrir os envios.');
                    if ($state['envios_abertos'] === '1') $conflict('Os envios já estão abertos.');
                    self::setSetting($db, 'envios_abertos', '1');
                    $db->exec('UPDATE usuarios SET envios_finalizados = 0');
                    break;
                case 'reabrir_votacao':
                    if ($state['votacao_aberta'] === '1') $conflict('A votação já está aberta.');
                    if ($state['envios_abertos'] === '1') $conflict('Encerre os envios antes de abrir a votação.');
                    if ($state['pedidos_abertos'] === '1') $conflict('Encerre os pedidos antes de reabrir a votação.');
                    self::setSetting($db, 'votacao_aberta', '1');
                    break;
                case 'iniciar_pedidos':
                case 'reabrir_pedidos':
                    if ($state['votacao_aberta'] === '1') $conflict('Encerre a votação antes de abrir os pedidos.');
                    if ($state['envios_abertos'] === '1') $conflict('Encerre os envios antes de abrir os pedidos.');
                    if ($state['pedidos_abertos'] === '1') $conflict('Os pedidos já estão abertos.');
                    self::setSetting($db, 'pedidos_abertos', '1');
                    break;
                case 'encerrar_pedidos':
                    if ($state['pedidos_abertos'] !== '1') $conflict('Os pedidos já estão encerrados.');
                    self::setSetting($db, 'pedidos_abertos', '0');
                    break;
            }

            $db->commit();
            v2_json(['success' => true, 'message' => $messages[$action]]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            v2_log_error('V2 admin state transition', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível atualizar o estado.'], 500);
        }
    }

    public static function deleteOrder(PDO $db): void
    {
        self::requirePost();
        v2_require_admin($db);
        $id = self::payloadId();
        if (!$id) v2_json(['success' => false, 'message' => 'Pedido inválido.'], 400);
        try {
            $db->beginTransaction();
            $db->prepare('DELETE FROM comprovantes WHERE pedido_id = ?')->execute([$id]);
            $stmt = $db->prepare('DELETE FROM pedidos WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
            }
            $db->commit();
            v2_json(['success' => true, 'message' => 'Pedido excluído com sucesso.']);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            v2_log_error('V2 delete order', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível excluir o pedido.'], 500);
        }
    }

    public static function deleteUser(PDO $db): void
    {
        self::requirePost();
        $adminId = v2_require_admin($db);
        $id = self::payloadId();
        if (!$id) v2_json(['success' => false, 'message' => 'Usuário inválido.'], 400);
        if ($id === $adminId) {
            v2_json(['success' => false, 'message' => 'Você não pode excluir a própria conta.'], 400);
        }
        try {
            $db->beginTransaction();
            $check = $db->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE');
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                $db->rollBack();
                v2_json(['success' => false, 'message' => 'Usuário não encontrado.'], 404);
            }
            $db->prepare('DELETE FROM votos_realizados WHERE usuario_id = ?')->execute([$id]);
            $db->prepare(
                'DELETE FROM votos_realizados
                 WHERE opcao_id IN (SELECT id FROM opcoes WHERE usuario_id = ?)'
            )->execute([$id]);
            $db->prepare('DELETE FROM votos WHERE usuario_id = ?')->execute([$id]);
            $db->prepare(
                'DELETE FROM votos WHERE camisa_id IN (SELECT id FROM camisas WHERE usuario_id = ?)'
            )->execute([$id]);
            $db->prepare(
                'DELETE c FROM comprovantes c
                 JOIN pedidos p ON p.id = c.pedido_id WHERE p.usuario_id = ?'
            )->execute([$id]);
            $db->prepare('DELETE FROM pedidos WHERE usuario_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM opcoes WHERE usuario_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM camisas WHERE usuario_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
            $db->commit();
            v2_json(['success' => true, 'message' => 'Usuário excluído com sucesso.']);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            v2_log_error('V2 delete user', $error);
            v2_json(['success' => false, 'message' => 'Não foi possível excluir o usuário.'], 500);
        }
    }

    public static function importAuthorized(PDO $db): void
    {
        self::requirePost();
        v2_require_admin($db);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $csv = is_array($payload) && is_string($payload['conteudo'] ?? null)
            ? base64_decode($payload['conteudo'], true)
            : false;
        if ($csv === false || strlen($csv) > 5 * 1024 * 1024) {
            v2_json(['erro' => 'O conteúdo do CSV é inválido ou ultrapassa 5 MB.'], 400);
        }
        if (strtolower(pathinfo((string) ($payload['nome'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
            v2_json(['erro' => 'O arquivo precisa ter extensão .csv.'], 400);
        }
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle, 0, ';');
        if (!$header) {
            fclose($handle);
            v2_json(['erro' => 'O CSV está vazio.'], 400);
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map(static fn($value) => strtolower(trim($value)), $header);
        if ($header !== ['curso', 'série', 'nome', 'email']) {
            fclose($handle);
            v2_json(['erro' => 'Cabeçalho inválido. Use: curso;série;nome;email.'], 400);
        }

        $imported = $duplicates = $invalid = 0;
        $line = 1;
        try {
            $db->beginTransaction();
            $check = $db->prepare('SELECT id FROM emails_autorizados WHERE email = ? LIMIT 1');
            $insert = $db->prepare(
                "INSERT INTO emails_autorizados (email, nome, curso, serie, tipo, utilizado)
                 VALUES (?, ?, ?, ?, 'ALUNO', 0)"
            );
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                $line++;
                if (count($row) !== 4) {
                    $invalid++;
                    continue;
                }
                [$course, $grade, $name, $email] = array_map('trim', $row);
                $email = strtolower($email);
                if (
                    $course === '' || $grade === '' || $name === '' ||
                    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                    mb_strlen($name) > 100 || mb_strlen($course) > 100 ||
                    mb_strlen($grade) > 30 || mb_strlen($email) > 150
                ) {
                    $invalid++;
                    continue;
                }
                $check->execute([$email]);
                if ($check->fetchColumn() !== false) {
                    $duplicates++;
                    continue;
                }
                $insert->execute([$email, $name, $course, $grade]);
                $imported++;
            }
            fclose($handle);
            $db->commit();
            v2_json([
                'sucesso' => true,
                'mensagem' => 'Importação concluída.',
                'importados' => $imported,
                'duplicados' => $duplicates,
                'invalidos' => $invalid,
                'linhas_lidas' => $line - 1,
            ]);
        } catch (Throwable $error) {
            if (is_resource($handle)) fclose($handle);
            if ($db->inTransaction()) $db->rollBack();
            v2_log_error('V2 student import', $error);
            v2_json(['erro' => 'Falha na importação. Nenhuma alteração foi confirmada.'], 500);
        }
    }

    public static function exportOrders(PDO $db): void
    {
        v2_require_admin($db);
        try {
            $rows = $db->query(
                "SELECT p.id, u.nome AS aluno, u.email, p.modelo, p.numero, p.tamanho,
                        p.nome_camisa, p.data_pedido, p.status_pagamento
                 FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id
                 ORDER BY CASE UPPER(p.tamanho)
                    WHEN 'PP' THEN 1 WHEN 'P' THEN 2 WHEN 'M' THEN 3
                    WHEN 'G' THEN 4 WHEN 'GG' THEN 5 ELSE 6 END,
                    p.numero ASC, p.id ASC"
            );
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="pedidos_por_tamanho.csv"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'ID do pedido', 'Aluno', 'E-mail', 'Curso/Modelo',
                'Número da camisa', 'Tamanho', 'Nome nas costas',
                'Data do pedido', 'Status do pagamento',
            ], ';', '"', '');
            foreach ($rows as $row) {
                fputcsv($output, array_values($row), ';', '"', '');
            }
            fclose($output);
            exit;
        } catch (Throwable $error) {
            v2_log_error('V2 export orders', $error);
            http_response_code(500);
            exit('Erro ao gerar a exportação.');
        }
    }

    private static function setSetting(PDO $db, string $key, string $value): void
    {
        $stmt = $db->prepare(
            'INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        $stmt->execute([$key, $value]);
    }

    private static function payloadId(): int|false
    {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $id = filter_var(is_array($payload) ? ($payload['id'] ?? null) : null, FILTER_VALIDATE_INT);

        return $id && $id > 0 ? $id : false;
    }

    private static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            v2_json(['success' => false, 'message' => 'Método não permitido.'], 405);
        }
    }
}
