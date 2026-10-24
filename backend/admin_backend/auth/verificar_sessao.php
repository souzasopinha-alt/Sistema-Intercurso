<?php

session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['usuario_id'])) {
    echo json_encode([
        'logado' => false,
        'admin' => false
    ]);

    exit;
}

if (strtoupper(trim((string) ($_SESSION['tipo'] ?? ''))) !== 'ADMIN') {
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