
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
        array_merge(['success' => $ok, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o banco indisponível.', 500);
}

$usuarioId = (int) $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    $stmt = $pdo->query(
        "SELECT valor FROM configuracoes
         WHERE chave = 'votacao_aberta' LIMIT 1"
    );
    $votacaoAberta = $stmt->fetchColumn() === '1';

    if ($method === 'GET') {
        $stmt = $pdo->query(
            'SELECT o.id, o.nome, o.imagem1, o.imagem2, o.imagem3
             FROM opcoes o
             WHERE o.aprovado = 1
             ORDER BY o.id ASC'
        );

        $opcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            'SELECT 1 FROM votos_realizados
             WHERE usuario_id = ? LIMIT 1'
        );
        $stmt->execute([$usuarioId]);
        $jaVotou = (bool) $stmt->fetchColumn();

        foreach ($opcoes as $i => &$opcao) {
            $opcao['numero'] = $i + 1;
        }
        unset($opcao);

        resposta(true, '', 200, [
            'opcoes' => $opcoes,
            'ja_votou' => $jaVotou,
            'votacao_aberta' => $votacaoAberta
        ]);
    }

    if ($method !== 'POST') {
        resposta(false, 'Método não permitido.', 405);
    }

    if (!$votacaoAberta) {
        resposta(false, 'A votação está encerrada.', 403, [
            'votacao_aberta' => false
        ]);
    }

    $dados = json_decode(file_get_contents('php://input'), true);
    $opcaoId = filter_var(
        is_array($dados) ? ($dados['opcao_id'] ?? null) : null,
        FILTER_VALIDATE_INT
    );

    if (!$opcaoId || $opcaoId < 1) {
        resposta(false, 'Opção inválida.', 400);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->query(
            "SELECT valor FROM configuracoes
             WHERE chave = 'votacao_aberta' FOR UPDATE"
        );

        if ($stmt->fetchColumn() !== '1') {
            $pdo->rollBack();
            resposta(false, 'A votação está encerrada.', 403, [
                'votacao_aberta' => false
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM opcoes
             WHERE id = ? AND aprovado = 1 LIMIT 1'
        );
        $stmt->execute([$opcaoId]);

        if (!$stmt->fetchColumn()) {
            $pdo->rollBack();
            resposta(false, 'Essa opção não está disponível.', 400);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO votos_realizados (usuario_id, opcao_id)
             VALUES (?, ?)'
        );
        $stmt->execute([$usuarioId, $opcaoId]);

        $pdo->commit();

        resposta(true, 'Voto registrado com sucesso.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (($e->errorInfo[1] ?? null) == 1062) {
            resposta(false, 'Você já votou. Cada aluno pode votar uma vez.', 409);
        }

        throw $e;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('votacao.php: ' . $e->getMessage());
    resposta(false, 'Não foi possível concluir a operação.', 500);
}
