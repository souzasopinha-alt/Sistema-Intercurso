
<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth/exigir_administrador.php';

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
    exigirAdministrador($pdo);

    $pdo->beginTransaction();

    $stmt = $pdo->query(
        "SELECT valor FROM configuracoes
         WHERE chave = 'votacao_aberta'
         FOR UPDATE"
    );

    if ($stmt->fetchColumn() !== '1') {
        $pdo->rollBack();
        resposta(false, 'A votação já está encerrada.', 409);
    }

    $stmt = $pdo->prepare(
        "UPDATE configuracoes
         SET valor = '0'
         WHERE chave = 'votacao_aberta'"
    );
    $stmt->execute();

    $pdo->commit();

    resposta(true, 'Votação encerrada com sucesso.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('encerrar_votacao.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível encerrar a votação.', 500);
}
