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

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->query(
            "SELECT chave, valor FROM configuracoes
             WHERE chave IN ('envios_abertos', 'votacao_aberta', 'pedidos_abertos') FOR UPDATE"
        );

        $cfg = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cfg[$row['chave']] = $row['valor'];
        }

        if (($cfg['votacao_aberta'] ?? '0') === '1') {
            $pdo->rollBack();
            resposta(false, 'A votação já está aberta.', 409);
        }

        if (($cfg['envios_abertos'] ?? '0') === '1') {
            $pdo->rollBack();
            resposta(false, 'Encerre os envios antes de abrir a votação.', 409);
        }

        if (($cfg['pedidos_abertos'] ?? '0') === '1') {
            $pdo->rollBack();
            resposta(false, 'Encerre os pedidos antes de reabrir a votação.', 409);
        }

        $pdo->exec("UPDATE configuracoes SET valor = '1' WHERE chave = 'votacao_aberta'");

        $pdo->commit();

        resposta(true, 'Votação reaberta com sucesso.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('reabrir_votacao.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível reabrir a votação.', 500);
}