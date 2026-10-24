
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
    'SELECT
        o.id,
        o.usuario_id,
        o.nome,
        o.imagem1,
        o.imagem2,
        o.imagem3,
        u.tipo AS tipo_criador,
        COUNT(v.id) AS total_votos
     FROM opcoes o
     JOIN usuarios u ON u.id = o.usuario_id
     LEFT JOIN votos_realizados v ON v.opcao_id = o.id
     WHERE o.aprovado = 1
     GROUP BY o.id, o.usuario_id, u.tipo, o.nome, o.imagem1, o.imagem2, o.imagem3
     ORDER BY o.id ASC'
);

    $opcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = [];

    foreach ($opcoes as $i => $opcao) {
        $numero = $i + 1;

            $resultado[] = [
        'id' => (int) $opcao['id'],
        'usuario_id' => (int) $opcao['usuario_id'],
        'criado_por_admin' => strtoupper((string) $opcao['tipo_criador']) === 'ADMIN',
        'numero' => $numero,
        'nome' => $opcao['nome'] ?: ('Opção ' . $numero),
        'imagem1' => $opcao['imagem1'],
        'imagem2' => $opcao['imagem2'],
        'imagem3' => $opcao['imagem3'],
        'total_votos' => (int) $opcao['total_votos']
    ];
    }

    resposta(true, '', 200, [
        'opcoes' => $resultado
    ]);

} catch (Throwable $e) {
    error_log('admin_votos.php: ' . $e->getMessage());

    resposta(false, 'Não foi possível carregar os votos.', 500);
}
