<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message = '', int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$ok,'message'=>$message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['usuario_id'])) resposta(false, 'Sessão não encontrada.', 401);
if (!isset($pdo) || !($pdo instanceof PDO)) resposta(false, 'Conexão com o MariaDB não encontrada.', 500);

$usuarioId = (int)$_SESSION['usuario_id'];
$stmt = $pdo->prepare('SELECT id, tipo FROM usuarios WHERE id = ? LIMIT 1');
$stmt->execute([$usuarioId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario || strtoupper((string)$usuario['tipo']) !== 'ADMIN') {
    resposta(false, 'Acesso permitido somente para administradores.', 403);
}

try {
$stmt = $pdo->query(
'SELECT id, nome
FROM opcoes
WHERE aprovado = 1
ORDER BY id ASC'
);

$opcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query(
    'SELECT opcao_id, total_votos
     FROM contagem_votos'
);

$contagens = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $contagens[(int) $row['opcao_id']] =
        (int) $row['total_votos'];
}

$resultado = [];

foreach ($opcoes as $i => $opcao) {
    $id = (int) $opcao['id'];

    $resultado[] = [
        'id' => $id,
        'numero' => $i + 1,
        'nome' => $opcao['nome'] ?: ('Opção ' . ($i + 1)),
        'total_votos' => $contagens[$id] ?? 0
    ];
}

resposta(true, '', 200, ['opcoes' => $resultado]);


} catch (Throwable $e) {
error_log('admin_votos.php: ' . $e->getMessage());
resposta(false, 'Não foi possível carregar os votos.', 500);
}
