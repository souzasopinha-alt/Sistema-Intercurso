<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    resposta(false, 'Método não permitido.', 405);
}
if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

$dados = json_decode(file_get_contents('php://input'), true);
$opcaoId = filter_var(is_array($dados) ? ($dados['id'] ?? null) : null, FILTER_VALIDATE_INT);
if (!$opcaoId || $opcaoId < 1) {
    resposta(false, 'Opção inválida.', 400);
}

try {
    $stmt = $pdo->prepare('SELECT tipo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    if (strtoupper((string) $stmt->fetchColumn()) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'votacao_aberta' FOR UPDATE");
    if ($stmt->fetchColumn() === '1') {
        $pdo->rollBack();
        resposta(false, 'Encerre a votação antes de excluir uma opção.', 409);
    }

    $stmt = $pdo->prepare(
        "SELECT o.usuario_id, o.imagem1, o.imagem2, o.imagem3
         FROM opcoes o
         JOIN usuarios u ON u.id = o.usuario_id
         WHERE o.id = ? AND UPPER(u.tipo) = 'ADMIN'
         FOR UPDATE"
    );
    $stmt->execute([$opcaoId]);
    $opcao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$opcao) {
        $pdo->rollBack();
        resposta(false, 'Opção administrativa não encontrada.', 404);
    }

    $stmt = $pdo->prepare('DELETE FROM votos_realizados WHERE opcao_id = ?');
    $stmt->execute([$opcaoId]);
    $stmt = $pdo->prepare('DELETE FROM opcoes WHERE id = ?');
    $stmt->execute([$opcaoId]);
    $pdo->commit();

    $baseDir = realpath(getenv('CAMISAS_UPLOAD_DIR') ?: (__DIR__ . '/../uploads/camisas'));
    if ($baseDir !== false) {
        foreach (['imagem1', 'imagem2', 'imagem3'] as $campo) {
            $path = (string) $opcao[$campo];
            if (!str_starts_with($path, 'uploads/camisas/admin/')) continue;
            $arquivo = realpath($baseDir . '/' . substr($path, strlen('uploads/camisas/')));
            if ($arquivo !== false && str_starts_with($arquivo, $baseDir . DIRECTORY_SEPARATOR) && is_file($arquivo)) {
                unlink($arquivo);
            }
        }
    }

    resposta(true, 'Opção administrativa excluída.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('excluir_opcao_votacao.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível excluir a opção.', 500);
}
