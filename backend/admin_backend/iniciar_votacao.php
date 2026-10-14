
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message, int $status = 200): never {
    http_response_code($status);
    echo json_encode(
        ['success' => $ok, 'message' => $message],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    resposta(false, 'Método não permitido.', 405);
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o banco indisponível.', 500);
}

try {
    $stmt = $pdo->prepare(
        'SELECT tipo FROM usuarios WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $tipo = $stmt->fetchColumn();

    if (strtoupper((string) $tipo) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }

    
$pdo->beginTransaction();

$stmt = $pdo->query(
    "SELECT valor
     FROM configuracoes
     WHERE chave = 'votacao_aberta'
     FOR UPDATE"
);

if ($stmt->fetchColumn() === '1') {
    $pdo->rollBack();
    resposta(false, 'A votação já foi iniciada.', 409);
}

$stmt = $pdo->query(
    "SELECT valor
     FROM configuracoes
     WHERE chave = 'envios_abertos'
     FOR UPDATE"
);

if ($stmt->fetchColumn() !== '1') {
    $pdo->rollBack();
    resposta(false, 'Os envios já foram encerrados.', 409);
}

$stmt = $pdo->query(
    "SELECT valor
     FROM configuracoes
     WHERE chave = 'pedidos_abertos'
     FOR UPDATE"
);

if ($stmt->fetchColumn() === '1') {
    $pdo->rollBack();
    resposta(false, 'Encerre os pedidos antes de iniciar a votação.', 409);
}
$total = (int) $pdo->query(
    'SELECT COUNT(*) FROM opcoes'
)->fetchColumn();


    if ($total === 0) {
        $pdo->rollBack();
        resposta(false, 'Ainda não há propostas enviadas.', 409);
    }

    $pdo->exec('UPDATE opcoes SET aprovado = 1');

    $stmt = $pdo->prepare(
        "INSERT INTO configuracoes (chave, valor)
         VALUES ('envios_abertos', '0')
         ON DUPLICATE KEY UPDATE valor = '0'"
    );
    $stmt->execute();

    $stmt = $pdo->prepare(
        "INSERT INTO configuracoes (chave, valor)
         VALUES ('votacao_aberta', '1')
         ON DUPLICATE KEY UPDATE valor = '1'"
    );
    $stmt->execute();

    $pdo->commit();
    resposta(true, 'Envios encerrados e votação iniciada.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('iniciar_votacao.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível iniciar a votação.', 500);
}
