<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message = '', int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

try {
    $stmt = $pdo->prepare('SELECT tipo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $tipo = $stmt->fetchColumn();

    if (strtoupper((string) $tipo) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }

    $stmt = $pdo->query(
        "SELECT chave, valor FROM configuracoes
         WHERE chave IN ('envios_abertos', 'votacao_aberta', 'pedidos_abertos')"
    );

    $cfg = [
        'envios_abertos' => '0',
        'votacao_aberta' => '0',
        'pedidos_abertos' => '0'
    ];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cfg[$row['chave']] = $row['valor'];
    }

    resposta(true, '', 200, [
        'envios_abertos' => $cfg['envios_abertos'] === '1',
        'votacao_aberta' => $cfg['votacao_aberta'] === '1',
        'pedidos_abertos' => $cfg['pedidos_abertos'] === '1'
    ]);

} catch (Throwable $e) {
    error_log('estado_acoes.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível consultar o estado.', 500);
}