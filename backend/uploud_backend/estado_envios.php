<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../resposta.php';

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o banco indisponível.', 500);
}

try {
    $stmt = $pdo->prepare(
        "SELECT valor
         FROM configuracoes
         WHERE chave = 'envios_abertos'
         LIMIT 1"
    );

    $stmt->execute();
    $enviosAbertos = $stmt->fetchColumn() === '1';

    $stmt = $pdo->prepare(
        'SELECT envios_finalizados
         FROM usuarios
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $enviosFinalizados = $stmt->fetchColumn();

    if ($enviosFinalizados === false) {
        resposta(false, 'Usuário não encontrado.', 404);
    }

    resposta(true, '', 200, [
        'envios_abertos' => $enviosAbertos,
        'envios_finalizados' => (int) $enviosFinalizados === 1
    ]);
} catch (Throwable $e) {
    error_log('estado_envios.php: ' . $e->getMessage());

    resposta(false, 'Não foi possível consultar o período de envio.', 500);
}