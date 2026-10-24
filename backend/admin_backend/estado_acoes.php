<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth/exigir_administrador.php';

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

try {
    exigirAdministrador($pdo);

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