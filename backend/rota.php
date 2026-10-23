<?php

session_start();

header('Content-Type: application/json; charset=utf-8');

if (
    empty($_SESSION['usuario_id']) ||
    ($_SESSION['tipo'] ?? '') !== 'ADMIN'
) {
    http_response_code(isset($_SESSION['usuario_id']) ? 403 : 401);
    echo json_encode(['erro' => 'Acesso não autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';

try {
    $sql = "SELECT * FROM camisas ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $camisas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($camisas, JSON_UNESCAPED_UNICODE);

} catch (PDOException $erro) {

    http_response_code(500);

    error_log('Erro ao buscar propostas de camisas: ' . $erro->getMessage());

    echo json_encode([
        "erro" => "Erro ao buscar propostas de camisas"
    ], JSON_UNESCAPED_UNICODE);
}
