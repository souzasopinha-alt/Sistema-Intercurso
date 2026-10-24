<?php

require_once __DIR__ . '/../../resposta.php';

function exigirAdministrador(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'SELECT tipo FROM usuarios WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['usuario_id']]);

    if (strtoupper(trim((string) $stmt->fetchColumn())) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }
}
