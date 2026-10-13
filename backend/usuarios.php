<?php

session_start();

header('Content-Type: application/json; charset=utf-8');

if (
    !isset($_SESSION['usuario_id']) ||
    ($_SESSION['tipo'] ?? '') !== 'ADMIN'
) {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso não autorizado.']);
    exit;
}

require_once __DIR__ . '/db.php';

try {
    $stmt = $pdo->query("
        SELECT
            u.id,
            u.nome,
            u.email,
            u.tipo,
            CASE
                WHEN ea.utilizado = 1 THEN 'Cadastrado'
                ELSE 'Autorizado'
            END AS situacao
        FROM usuarios u
        LEFT JOIN emails_autorizados ea
            ON ea.id = u.email_autorizado_id
        ORDER BY u.nome ASC
    ");

    echo json_encode(
        $stmt->fetchAll(),
        JSON_UNESCAPED_UNICODE
    );
} catch (PDOException $e) {
    error_log('Erro ao listar usuários: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao carregar usuários.']);
}
