<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message, int $status = 200, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    resposta(false, 'Método não permitido.', 405);
}
if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    resposta(false, 'Conteúdo JSON inválido.', 400);
}
$nome = trim($payload['nome'] ?? '');
if ($nome === '' || mb_strlen($nome) > 100) {
    resposta(false, 'Informe um nome de até 100 caracteres para a opção.', 400);
}

$campos = ['imagem1', 'imagem2', 'imagem3'];
$permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$arquivos = [];
$salvos = [];
$diretorio = null;

try {
    $stmt = $pdo->prepare('SELECT tipo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    if (strtoupper((string) $stmt->fetchColumn()) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }

    foreach ($campos as $campo) {
        $conteudo = $payload[$campo]['data'] ?? '';
        $bytes = is_string($conteudo) ? base64_decode($conteudo, true) : false;
        if ($bytes === false) {
            resposta(false, "Envie a imagem {$campo}.", 400);
        }
        $tamanho = strlen($bytes);
        $mime = $finfo->buffer($bytes);
        if ($tamanho < 1 || $tamanho > 10 * 1024 * 1024 || !isset($permitidos[$mime])) {
            resposta(false, "A imagem {$campo} deve ser JPG, PNG ou WEBP e ter até 10 MB.", 400);
        }
        $arquivos[$campo] = ['bytes' => $bytes, 'ext' => $permitidos[$mime]];
    }

    $pdo->beginTransaction();
    $stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'votacao_aberta' FOR UPDATE");
    if ($stmt->fetchColumn() === '1') {
        $pdo->rollBack();
        resposta(false, 'Encerre a votação antes de adicionar uma nova opção.', 409);
    }

    $baseDir = rtrim(getenv('CAMISAS_UPLOAD_DIR') ?: (__DIR__ . '/../uploads/camisas'), '/');
    $idArquivo = bin2hex(random_bytes(12));
    $diretorioRelativo = 'admin/' . (int) $_SESSION['usuario_id'] . '/' . $idArquivo;
    $diretorio = $baseDir . '/' . $diretorioRelativo;
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de imagens.');
    }

    $paths = [];
    foreach ($arquivos as $campo => $arquivo) {
        $arquivoNome = $campo . '.' . $arquivo['ext'];
        $destino = $diretorio . '/' . $arquivoNome;
        if (file_put_contents($destino, $arquivo['bytes'], LOCK_EX) === false) {
            throw new RuntimeException("Não foi possível salvar {$campo}.");
        }
        $salvos[] = $destino;
        $paths[$campo] = 'uploads/camisas/' . $diretorioRelativo . '/' . $arquivoNome;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO opcoes (usuario_id, nome, imagem1, imagem2, imagem3, aprovado)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        (int) $_SESSION['usuario_id'], $nome,
        $paths['imagem1'], $paths['imagem2'], $paths['imagem3']
    ]);
    $opcaoId = (int) $pdo->lastInsertId();
    $pdo->commit();

    resposta(true, 'Opção adicionada à votação.', 200, ['opcao_id' => $opcaoId]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($salvos as $arquivo) {
        if (is_file($arquivo)) unlink($arquivo);
    }
    error_log('adicionar_opcao_votacao.php: ' . $e->getMessage());
    if (($e->errorInfo[1] ?? null) === 1062) {
        resposta(
            false,
            'O banco ainda impede múltiplas opções por administrador. Execute a migração backend/docs/migrations/002_multiplas_opcoes_admin.sql.',
            500
        );
    }
    resposta(false, 'Não foi possível adicionar a opção.', 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($salvos as $arquivo) {
        if (is_file($arquivo)) unlink($arquivo);
    }
    error_log('adicionar_opcao_votacao.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível adicionar a opção.', 500);
}
