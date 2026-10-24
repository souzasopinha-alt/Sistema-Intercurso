<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth/exigir_administrador.php';

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

try {
    exigirAdministrador($pdo);

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