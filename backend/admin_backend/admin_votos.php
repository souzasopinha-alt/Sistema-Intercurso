
<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(
    bool $ok,
    string $message = '',
    int $status = 200,
    array $extra = []
): never {
    http_response_code($status);

    echo json_encode(
        array_merge([
            'success' => $ok,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Sessão não encontrada.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

try {
    $stmt = $pdo->prepare(
        'SELECT tipo
         FROM usuarios
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $tipo = $stmt->fetchColumn();

    if (strtoupper((string) $tipo) !== 'ADMIN') {
        resposta(false, 'Acesso permitido somente para administradores.', 403);
    }

    $stmt = $pdo->query(
    'SELECT
        o.id,
        o.nome,
        o.imagem1,
        o.imagem2,
        o.imagem3,
        COUNT(v.id) AS total_votos
     FROM opcoes o
     LEFT JOIN votos_realizados v ON v.opcao_id = o.id
     WHERE o.aprovado = 1
     GROUP BY o.id, o.nome, o.imagem1, o.imagem2, o.imagem3
     ORDER BY o.id ASC'
);

    $opcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = [];

    foreach ($opcoes as $i => $opcao) {
        $numero = $i + 1;

            $resultado[] = [
        'id' => (int) $opcao['id'],
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
