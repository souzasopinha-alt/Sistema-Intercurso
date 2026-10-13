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
        array_merge(
            ['success' => $ok, 'message' => $message],
            $extra
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado para votar.', 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'Conexão com o MariaDB não encontrada.', 500);
}

$usuarioId = (int) $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Busca as propostas aprovadas no MariaDB.
        $stmt = $pdo->query(
            'SELECT id, nome, imagem1, imagem2, imagem3
             FROM opcoes
             WHERE aprovado = 1
             ORDER BY id ASC'
        );

        $opcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Busca a quantidade de votos de cada proposta.
        $stmt = $pdo->query(
            'SELECT opcao_id, total_votos FROM contagem_votos'
        );

        $contagens = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $contagens[(int) $linha['opcao_id']] =
                (int) $linha['total_votos'];
        }

        // Verifica se o aluno já votou.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM votos_realizados
             WHERE usuario_id = ?
             LIMIT 1'
        );

        $stmt->execute([$usuarioId]);
        $jaVotou = (bool) $stmt->fetchColumn();

        foreach ($opcoes as $i => &$opcao) {
            $opcao['numero'] = $i + 1;
            $opcao['total_votos'] =
                $contagens[(int) $opcao['id']] ?? 0;
        }

        unset($opcao);

        resposta(true, '', 200, [
            'opcoes' => $opcoes,
            'ja_votou' => $jaVotou
        ]);
    }

    if ($method !== 'POST') {
        resposta(false, 'Método não permitido.', 405);
    }

    // Recebe a opção escolhida pelo aluno.
    $dados = json_decode(file_get_contents('php://input'), true);

    if (!is_array($dados)) {
        resposta(false, 'Dados inválidos.', 400);
    }

    $opcaoId = filter_var(
        $dados['opcao_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$opcaoId || $opcaoId < 1) {
        resposta(false, 'Opção inválida.', 400);
    }

    // Confirma que a proposta existe e está aprovada.
    $stmt = $pdo->prepare(
        'SELECT id FROM opcoes
         WHERE id = ? AND aprovado = 1'
    );

    $stmt->execute([$opcaoId]);

    if (!$stmt->fetchColumn()) {
        resposta(
            false,
            'A opção escolhida não existe ou não está disponível.',
            400
        );
    }

    // Registra o voto e atualiza a contagem na mesma transação.
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO votos_realizados (usuario_id, opcao_id)
             VALUES (?, ?)'
        );

        $stmt->execute([$usuarioId, $opcaoId]);

        $stmt = $pdo->prepare(
            'INSERT INTO contagem_votos (opcao_id, total_votos)
             VALUES (?, 1)
             ON DUPLICATE KEY UPDATE
             total_votos = total_votos + 1'
        );

        $stmt->execute([$opcaoId]);

        $pdo->commit();

        resposta(true, 'Voto registrado com sucesso.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // A restrição UNIQUE impede votos duplicados do mesmo aluno.
        if (($e->errorInfo[1] ?? null) == 1062) {
            resposta(
                false,
                'Você já votou. Cada usuário pode votar apenas uma vez.',
                409
            );
        }

        throw $e;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('votacao.php: ' . $e->getMessage());

    resposta(false, 'Não foi possível concluir a votação.', 500);
}
