<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

function resposta(
    bool $ok,
    string $message,
    int $status = 200,
    array $extra = []
): never {
    http_response_code($status);

    echo json_encode(
        array_merge(
            ['success' => $ok, 'message' => $message],
            $extra
        ),
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
$arquivosSalvos = [];
$destinoDir = null;

try {
    // Confere o usuário.
    $stmt = $pdo->prepare(
        'SELECT nome FROM usuarios WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$usuarioId]);
    $nome = $stmt->fetchColumn();

    if ($nome === false) {
        resposta(false, 'Usuário não encontrado.', 401);
    }

    // Valida as três imagens antes de salvá-las.
    foreach ($campos as $campo) {
        if (
            !isset($_FILES[$campo]) ||
            $_FILES[$campo]['error'] !== UPLOAD_ERR_OK
        ) {
            resposta(false, "A imagem {$campo} não foi recebida corretamente.", 400);
        }

        $tmp = $_FILES[$campo]['tmp_name'];
        $tamanho = (int) $_FILES[$campo]['size'];

        if (!is_uploaded_file($tmp)) {
            resposta(false, "Arquivo inválido para {$campo}.", 400);
        }

        if ($tamanho <= 0 || $tamanho > $maxBytes) {
            resposta(false, "A imagem {$campo} deve ter até 10 MB.", 400);
        }

        $mime = $finfo->file($tmp);

        if (!isset($permitidos[$mime])) {
            resposta(false, "Formato inválido para {$campo}.", 400);
        }

        $arquivos[$campo] = [
            'tmp' => $tmp,
            'ext' => $permitidos[$mime]
        ];
    }

    /*
     * O bloqueio e a consulta da proposta existente são feitos
     * dentro da transação. A restrição UNIQUE no banco impede
     * duplicações mesmo em requisições simultâneas.
     */
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT valor FROM configuracoes
         WHERE chave = 'envios_abertos'
         FOR UPDATE"
    );
    $stmt->execute();

    if ($stmt->fetchColumn() !== '1') {
        $pdo->rollBack();
        resposta(false, 'O período de envio de camisas foi encerrado.', 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM opcoes WHERE usuario_id = ? LIMIT 1'
    );
    $stmt->execute([$usuarioId]);

    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        resposta(false, 'Você já enviou uma proposta de camisas.', 409);
    }

    $baseDir = getenv('CAMISAS_UPLOAD_DIR')
        ?: (__DIR__ . '/../uploads/camisas');

    $baseDir = rtrim($baseDir, '/');

    $uploadId = bin2hex(random_bytes(12));
    $relativeDir = 'usuarios/' . $usuarioId . '/' . $uploadId;
    $destinoDir = $baseDir . '/' . $relativeDir;

    if (!is_dir($destinoDir) && !mkdir($destinoDir, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de imagens.');
    }

    $paths = [];

    foreach ($arquivos as $campo => $arquivo) {
        $nomeArquivo = $campo . '.' . $arquivo['ext'];
        $destino = $destinoDir . '/' . $nomeArquivo;

        if (!move_uploaded_file($arquivo['tmp'], $destino)) {
            throw new RuntimeException("Não foi possível salvar {$campo}.");
        }

        $arquivosSalvos[] = $destino;

        $paths[$campo] =
            'uploads/camisas/' . $relativeDir . '/' . $nomeArquivo;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO opcoes
            (usuario_id, nome, imagem1, imagem2, imagem3, aprovado)
         VALUES (?, ?, ?, ?, ?, 0)'
    );

    $stmt->execute([
        $usuarioId,
        $nome,
        $paths['imagem1'],
        $paths['imagem2'],
        $paths['imagem3']
    ]);

    $opcaoId = (int) $pdo->lastInsertId();

    $pdo->commit();

    resposta(true, 'As três imagens foram salvas com sucesso.', 200, [
        'opcao_id' => $opcaoId,
        'imagens' => $paths
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach ($arquivosSalvos as $arquivoSalvo) {
        if (is_file($arquivoSalvo)) {
            unlink($arquivoSalvo);
        }
    }

    if (($e->errorInfo[1] ?? null) == 1062) {
        resposta(false, 'Você já enviou uma proposta de camisas.', 409);
    }

    error_log('upload_camisas.php: ' . $e->getMessage());

    resposta(false, 'Não foi possível salvar a proposta.', 500);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach ($arquivosSalvos as $arquivoSalvo) {
        if (is_file($arquivoSalvo)) {
            unlink($arquivoSalvo);
        }
    }

    error_log('upload_camisas.php: ' . $e->getMessage());

    resposta(false, 'Não foi possível salvar a proposta. Verifique o servidor.', 500);
}