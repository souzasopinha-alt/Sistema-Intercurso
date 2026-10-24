
<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth/exigir_administrador.php';

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o banco indisponível.', 500);
}

try {
    exigirAdministrador($pdo);

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
