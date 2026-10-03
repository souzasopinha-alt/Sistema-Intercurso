<?php

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'logado' => false,
        'admin' => false
    ]);

    exit;
}

if (!isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'ADMIN') {
    echo json_encode([
        'logado' => true,
        'admin' => false
    ]);

    exit;
}

echo json_encode([
    'logado' => true,
    'admin' => true,
    'nome' => $_SESSION['nome'] ?? 'Administrador',
    'email' => $_SESSION['email'] ?? ''
]);