<?php

session_start();

require_once '../db.php';

if (empty($_SESSION['usuario_id']) || !isset($_SESSION['tipo'])) {
    http_response_code(401);
    exit('Usuário não autenticado.');
}

if (strtoupper(trim((string) $_SESSION['tipo'])) !== 'ADMIN') {
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
        SELECT arquivo, arquivo_nome, mime_type
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

    if (!empty($comprovante['arquivo_nome'])) {
        $nome = basename($comprovante['arquivo_nome']);
        $diretorioUpload = getenv('PAYMENT_UPLOAD_DIR')
            ?: dirname(__DIR__) . '/pagamento_backend/storage/comprovantes';
        $caminho = rtrim($diretorioUpload, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $nome;
        $caminhoPadrao = dirname(__DIR__)
            . '/pagamento_backend/storage/comprovantes/' . $nome;
        if (!is_file($caminho) && is_file($caminhoPadrao)) {
            $caminho = $caminhoPadrao;
        }
        $tipo = $comprovante['mime_type'];
    } else {
        $arquivo = (string) ($comprovante['arquivo'] ?? '');
        $caminho = dirname(__DIR__, 2) . '/' . $arquivo;
        if (!is_file($caminho) && $arquivo !== '') {
            $diretorioUpload = getenv('PAYMENT_UPLOAD_DIR') ?: __DIR__ . '/uploads';
            $caminho = rtrim($diretorioUpload, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . basename($arquivo);
        }
        $tipo = $caminho !== '' && is_file($caminho) ? mime_content_type($caminho) : false;
    }

    if (!is_file($caminho)) {
        http_response_code(404);
        exit('Arquivo do comprovante não encontrado.');
    }

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
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="comprovante-' . (int) $id . '"');

    readfile($caminho);
    exit;

} catch (PDOException $e) {

    http_response_code(500);
    exit('Erro ao carregar o comprovante.');
}