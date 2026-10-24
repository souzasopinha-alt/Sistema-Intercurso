<?php
declare(strict_types=1);

final class OrderController
{
    public static function profile(PDO $db): void
    {
        $userId = v2_require_user();
        $role = strtoupper(trim((string) ($_SESSION['tipo'] ?? '')));
        if (!in_array($role, ['ALUNO', 'LIDER'], true)) {
            v2_json([
                'sucesso' => false,
                'erro' => 'Acesso permitido apenas para alunos e líderes.',
            ], 403);
        }

        try {
            $stmt = $db->prepare(
                'SELECT id, nome, email, curso, serie, tipo
                 FROM usuarios WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) {
                v2_json(['sucesso' => false, 'erro' => 'Usuário não encontrado.'], 404);
            }

            $stmt = $db->prepare(
                'SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1'
            );
            $stmt->execute(['pedidos_abertos']);
            $ordersOpen = $stmt->fetchColumn() === '1';

            $stmt = $db->prepare(
                'SELECT id, modelo, numero, tamanho, nome_camisa, data_pedido,
                        status_pagamento, metodo_pagamento
                 FROM pedidos WHERE usuario_id = ? ORDER BY id DESC'
            );
            $stmt->execute([$userId]);
            $orders = $stmt->fetchAll();
            $latest = $orders[0] ?? null;

            v2_json([
                'sucesso' => true,
                'usuario' => $user,
                'pedidos' => $orders,
                'pedidos_abertos' => $ordersOpen,
                'pode_criar_pedido' => !$latest || $latest['status_pagamento'] === 'APROVADO',
            ]);
        } catch (Throwable $error) {
            v2_log_error('V2 profile', $error);
            v2_json(['sucesso' => false, 'erro' => 'Erro ao carregar o perfil.'], 500);
        }
    }

    public static function create(PDO $db): void
    {
        self::requireMethod('POST');
        $userId = v2_require_user();
        $model = trim((string) ($_POST['modelo'] ?? ''));
        $number = filter_var($_POST['numero'] ?? null, FILTER_VALIDATE_INT);
        $size = trim((string) ($_POST['tamanho'] ?? ''));
        $shirtName = trim((string) ($_POST['nome_camisa'] ?? ''));
        $replaceId = filter_var(
            $_POST['substituir_pedido_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $model === '' || !$number || $number < 1 || $number > 99 ||
            $size === '' || $shirtName === ''
        ) {
            v2_json([
                'sucesso' => false,
                'erro' => 'Todos os campos são obrigatórios e válidos.',
            ], 400);
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            if (!$stmt->fetchColumn()) {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'Usuário não encontrado.'], 404);
            }

            $stmt = $db->query(
                "SELECT valor FROM configuracoes WHERE chave = 'pedidos_abertos' LIMIT 1"
            );
            if ($stmt->fetchColumn() !== '1') {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'Os pedidos estão encerrados.'], 403);
            }

            $stmt = $db->prepare(
                'SELECT id, status_pagamento, substituido_por_id
                 FROM pedidos WHERE usuario_id = ?
                 ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $latest = $stmt->fetch();

            if ($replaceId) {
                if (
                    !$latest ||
                    (int) $latest['id'] !== $replaceId ||
                    $latest['status_pagamento'] !== 'RECUSADO' ||
                    $latest['substituido_por_id'] !== null
                ) {
                    $db->rollBack();
                    v2_json([
                        'sucesso' => false,
                        'erro' => 'Este pedido recusado não pode mais ser substituído. Atualize a página e tente novamente.',
                    ], 409);
                }
            } elseif ($latest && $latest['status_pagamento'] !== 'APROVADO') {
                $db->rollBack();
                v2_json([
                    'sucesso' => false,
                    'erro' => 'Você só pode fazer um novo pedido depois que o administrador aprovar o pedido atual.',
                ], 409);
            }

            $stmt = $db->prepare(
                'INSERT INTO pedidos (usuario_id, modelo, numero, tamanho, nome_camisa)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $model, $number, $size, $shirtName]);
            $orderId = (int) $db->lastInsertId();

            if ($replaceId) {
                $stmt = $db->prepare(
                    'UPDATE pedidos SET substituido_por_id = ?
                     WHERE id = ? AND usuario_id = ? AND substituido_por_id IS NULL'
                );
                $stmt->execute([$orderId, $replaceId, $userId]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Rejected order changed during replacement.');
                }
            }

            $db->commit();
            v2_json([
                'sucesso' => true,
                'mensagem' => 'Pedido criado com sucesso.',
                'pedido_id' => $orderId,
            ]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            v2_log_error('V2 create order', $error);
            v2_json(['sucesso' => false, 'erro' => 'Erro ao criar pedido.'], 500);
        }
    }

    private static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            v2_json(['sucesso' => false, 'erro' => 'Método não permitido.'], 405);
        }
    }
}
