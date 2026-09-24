<?php
header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';
$dbname = 'sistema_votacao';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha na conexão com o banco de dados.']);
    exit;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS votos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proposta_id INT NOT NULL,
        aluno_id INT NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_votos_aluno_proposta (proposta_id, aluno_id),
        FOREIGN KEY (proposta_id) REFERENCES propostas(id) ON DELETE CASCADE,
        FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'POST' && preg_match('#^/api/camisas/(\d+)/voto$#', $uri, $m)) {
    registrarVoto($pdo, (int) $m[1]);
} elseif ($method === 'GET' && preg_match('#^/api/camisas/votos$#', $uri)) {
    listarVotosPorProposta($pdo);
} elseif ($method === 'GET' && preg_match('#^/api/camisas/(\d+)/votos$#', $uri, $m)) {
    contarVotosDaProposta($pdo, (int) $m[1]);
} else {
    http_response_code(404);
    echo json_encode(['erro' => 'Rota não encontrada.']);
}

function registrarVoto(PDO $pdo, int $propostaId): void
{
    $dados = json_decode(file_get_contents('php://input'), true);
    $alunoId = $dados['aluno_id'] ?? null;

    if (!is_numeric($alunoId)) {
        http_response_code(400);
        echo json_encode(['erro' => 'Campo "aluno_id" é obrigatório e deve ser numérico.']);
        return;
    }
    $alunoId = (int) $alunoId;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id FROM propostas WHERE id = :id');
        $stmt->execute([':id' => $propostaId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['erro' => 'Proposta de camisa não encontrada.']);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO votos (proposta_id, aluno_id) VALUES (:proposta_id, :aluno_id)');
        $stmt->execute([':proposta_id' => $propostaId, ':aluno_id' => $alunoId]);

        $pdo->commit();
        http_response_code(201);
        echo json_encode(['mensagem' => 'Voto registrado com sucesso.']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['erro' => 'Este aluno já votou nesta proposta.']);
        } else {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro interno ao registrar o voto.']);
        }
    }
}

function listarVotosPorProposta(PDO $pdo): void
{
    $stmt = $pdo->query('
        SELECT p.id, p.nome, COUNT(v.id) AS total_votos
        FROM propostas p
        LEFT JOIN votos v ON v.proposta_id = p.id
        GROUP BY p.id, p.nome
        ORDER BY total_votos DESC, p.id ASC
    ');
    http_response_code(200);
    echo json_encode($stmt->fetchAll());
}

function contarVotosDaProposta(PDO $pdo, int $propostaId): void
{
    $stmt = $pdo->prepare('
        SELECT p.id, p.nome, COUNT(v.id) AS total_votos
        FROM propostas p
        LEFT JOIN votos v ON v.proposta_id = p.id
        WHERE p.id = :id
        GROUP BY p.id, p.nome
    ');
    $stmt->execute([':id' => $propostaId]);
    $resultado = $stmt->fetch();

    if (!$resultado) {
        http_response_code(404);
        echo json_encode(['erro' => 'Proposta de camisa não encontrada.']);
        return;
    }
    http_response_code(200);
    echo json_encode($resultado);
}
