
<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message = '', int $status = 200, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $ok, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
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

    if (strtoupper((string) $stmt->fetchColumn()) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }

    $stmt = $pdo->query(
        "SELECT chave, valor
         FROM configuracoes
         WHERE chave IN ('envios_abertos', 'votacao_aberta')"
    );

    $config = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $config[$linha['chave']] = $linha['valor'];
    }

    resposta(true, '', 200, [
        'envios_abertos' => ($config['envios_abertos'] ?? '0') === '1',
        'votacao_aberta' => ($config['votacao_aberta'] ?? '0') === '1'
    ]);
} catch (Throwable $e) {
    error_log('estado_votacao.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível consultar o estado da votação.', 500);
}
