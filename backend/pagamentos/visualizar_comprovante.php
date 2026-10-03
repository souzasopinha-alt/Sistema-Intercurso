<?php

session_start();

require_once '../db.php';

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['tipo'])) {
    http_response_code(401);
    exit('Usuário não autenticado.');
}

if ($_SESSION['tipo'] !== 'ADMIN') {
    http_response_code(403);
    exit('Acesso permitido apenas para administradores.');
}

$id = $_GET['id'] ?? null;

if (!$id || !filter_var($id, FILTER_VALIDATE_INT)) {
    http_response_code(400);
    exit('Comprovante inválido.');
}

try {

    $stmt = $pdo->prepare("
        SELECT arquivo
        FROM comprovantes
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $comprovante = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comprovante) {
        http_response_code(404);
        exit('Comprovante não encontrado.');
    }

    $arquivo = $comprovante['arquivo'];

    $caminho = dirname(__DIR__, 2) . '/' . $arquivo;

    if (!is_file($caminho)) {
        http_response_code(404);
        exit('Arquivo do comprovante não encontrado.');
    }

    $tipo = mime_content_type($caminho);

    $tiposPermitidos = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf'
    ];

    if (!in_array($tipo, $tiposPermitidos, true)) {
        http_response_code(415);
        exit('Tipo de arquivo não permitido.');
    }

    header('Content-Type: ' . $tipo);
    header('Content-Length: ' . filesize($caminho));
    header('Content-Disposition: inline');

    readfile($caminho);
    exit;

} catch (PDOException $e) {

    http_response_code(500);
    exit('Erro ao carregar o comprovante.');
}