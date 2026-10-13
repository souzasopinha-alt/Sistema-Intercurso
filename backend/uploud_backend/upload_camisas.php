<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $ok, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resposta(false, 'Método não permitido.', 405);
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado para enviar as camisas.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

$usuarioId = (int) $_SESSION['usuario_id'];

$stmt = $pdo->prepare('SELECT id, nome FROM usuarios WHERE id = ? LIMIT 1');
$stmt->execute([$usuarioId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    resposta(false, 'Usuário não encontrado.', 401);
}

$campos = ['imagem1', 'imagem2', 'imagem3'];
$permitidos = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];
$maxBytes = 10 * 1024 * 1024;
$finfo = new finfo(FILEINFO_MIME_TYPE);
$arquivos = [];

foreach ($campos as $campo) {
    if (
        !isset($_FILES[$campo]) ||
        $_FILES[$campo]['error'] !== UPLOAD_ERR_OK
    ) {
        resposta(false, "A imagem {$campo} não foi recebida corretamente.", 400);
    }

    $tmp = $_FILES[$campo]['tmp_name'];
    $tamanho = (int) $_FILES[$campo]['size'];
    $mime = $finfo->file($tmp);

    if ($tamanho <= 0 || $tamanho > $maxBytes) {
        resposta(false, "A imagem {$campo} deve ter até 10 MB.", 400);
    }

    if (!isset($permitidos[$mime])) {
        resposta(false, "Formato inválido para {$campo}.", 400);
    }

    $arquivos[$campo] = [
        'tmp' => $tmp,
        'ext' => $permitidos[$mime]
    ];
}

/*
 * Local padrão: backend/uploads/camisas
 * No Railway, monte o volume persistente nesse mesmo caminho absoluto
 * ou configure CAMISAS_UPLOAD_DIR para o caminho montado.
 */
$baseDir = getenv('CAMISAS_UPLOAD_DIR')
    ?: (__DIR__ . '/../uploads/camisas');

$baseDir = rtrim($baseDir, '/');
$uploadId = bin2hex(random_bytes(12));
$relativeDir = 'usuarios/' . $usuarioId . '/' . $uploadId;
$destinoDir = $baseDir . '/' . $relativeDir;

if (!is_dir($destinoDir) && !mkdir($destinoDir, 0755, true)) {
    resposta(false, 'Não foi possível preparar a pasta para as imagens.', 500);
}

$paths = [];
$arquivosSalvos = [];

try {
    foreach ($arquivos as $campo => $arquivo) {
        $nomeArquivo = $campo . '.' . $arquivo['ext'];
        $destino = $destinoDir . '/' . $nomeArquivo;

        if (!move_uploaded_file($arquivo['tmp'], $destino)) {
            throw new RuntimeException("Não foi possível salvar {$campo}.");
        }

        $arquivosSalvos[] = $destino;
        $paths[$campo] = 'uploads/camisas/' . $relativeDir . '/' . $nomeArquivo;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO opcoes
            (usuario_id, nome, imagem1, imagem2, imagem3, aprovado)
         VALUES (?, ?, ?, ?, ?, 1)'
    );

    $stmt->execute([
        $usuarioId,
        $usuario['nome'],
        $paths['imagem1'],
        $paths['imagem2'],
        $paths['imagem3']
    ]);

    $opcaoId = (int) $pdo->lastInsertId();

    resposta(true, 'As três imagens foram salvas com sucesso.', 200, [
        'opcao_id' => $opcaoId,
        'imagens' => $paths
    ]);

} catch (Throwable $e) {
    foreach ($arquivosSalvos as $arquivoSalvo) {
        if (is_file($arquivoSalvo)) {
            unlink($arquivoSalvo);
        }
    }

    error_log('upload_camisas.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível salvar a proposta. Verifique o servidor.', 500);
}
