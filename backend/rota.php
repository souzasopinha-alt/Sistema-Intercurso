<?php

require_once 'db.php';

try {
    $sql = "SELECT * FROM camisas ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $camisas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');

    echo json_encode($camisas);

} catch (PDOException $erro) {

    http_response_code(500);

    header('Content-Type: application/json');

    echo json_encode([
        "erro" => "Erro ao buscar propostas de camisas"
    ]);
}
