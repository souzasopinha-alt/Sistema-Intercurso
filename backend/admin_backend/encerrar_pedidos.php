<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message = '', int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

try {
    $stmt = $pdo->prepare('SELECT tipo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $tipo = $stmt->fetchColumn();

    if (strtoupper((string) $tipo) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->query(
            "SELECT valor FROM configuracoes
             WHERE chave = 'pedidos_abertos' FOR UPDATE"
        );
        $valor = $stmt->fetchColumn();

        if ($valor !== '1') {
            $pdo->rollBack();
            resposta(false, 'Os pedidos já estão encerrados.', 409);
        }

        $pdo->exec("UPDATE configuracoes SET valor = '0' WHERE chave = 'pedidos_abertos'");

        $pdo->commit();

        resposta(true, 'Pedidos encerrados com sucesso.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('encerrar_pedidos.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível encerrar os pedidos.', 500);
}