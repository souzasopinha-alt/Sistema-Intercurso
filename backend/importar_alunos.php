<?php

session_start();

header('Content-Type: application/json; charset=utf-8');

if (
    !isset($_SESSION['usuario_id']) ||
    ($_SESSION['tipo'] ?? '') !== 'ADMIN'
) {
    http_response_code(403);
    echo json_encode([
        'erro' => 'Acesso não autorizado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'erro' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['conteudo'])) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Envie um arquivo CSV válido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conteudo = is_string($payload['conteudo']) ? base64_decode($payload['conteudo'], true) : false;
if ($conteudo === false) {
    http_response_code(400);
    echo json_encode(['erro' => 'O conteúdo do CSV é inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($conteudo) > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'O arquivo não pode ultrapassar 5 MB.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$extensao = strtolower(
    pathinfo((string) ($payload['nome'] ?? ''), PATHINFO_EXTENSION)
);

if ($extensao !== 'csv') {
    http_response_code(400);
    echo json_encode([
        'erro' => 'O arquivo precisa ter extensão .csv.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$handle = fopen('php://temp', 'r+');
if ($handle !== false) {
    fwrite($handle, $conteudo);
    rewind($handle);
}

if ($handle === false) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Não foi possível ler o arquivo.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cabecalho = fgetcsv($handle, 0, ';');

if ($cabecalho === false) {
    fclose($handle);
    http_response_code(400);
    echo json_encode([
        'erro' => 'O CSV está vazio.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cabecalho[0] = preg_replace(
    '/^\xEF\xBB\xBF/',
    '',
    $cabecalho[0]
);

$cabecalho = array_map(
    static fn($valor) => strtolower(trim($valor)),
    $cabecalho
);

$cabecalhoEsperado = ['curso', 'série', 'nome', 'email'];

if ($cabecalho !== $cabecalhoEsperado) {
    fclose($handle);
    http_response_code(400);
    echo json_encode([
        'erro' => 'Cabeçalho inválido. Use: curso;série;nome;email.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$importados = 0;
$duplicados = 0;
$invalidos = 0;
$linha = 1;

try {
    $pdo->beginTransaction();

    $consultar = $pdo->prepare(
        'SELECT id FROM emails_autorizados WHERE email = ? LIMIT 1'
    );

    $inserir = $pdo->prepare(
        'INSERT INTO emails_autorizados
            (email, nome, curso, serie, tipo, utilizado)
         VALUES (?, ?, ?, ?, ?, 0)'
    );

    while (($dados = fgetcsv($handle, 0, ';')) !== false) {
        $linha++;

        if (count($dados) !== 4) {
            $invalidos++;
            continue;
        }

        [$curso, $serie, $nome, $email] = array_map(
            static fn($valor) => trim($valor),
            $dados
        );

        $email = strtolower($email);

        if (
            $curso === '' ||
            $serie === '' ||
            $nome === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            mb_strlen($nome) > 100 ||
            mb_strlen($curso) > 100 ||
            mb_strlen($serie) > 30 ||
            mb_strlen($email) > 150
        ) {
            $invalidos++;
            continue;
        }

        $consultar->execute([$email]);

        if ($consultar->fetch()) {
            $duplicados++;
            continue;
        }

        $inserir->execute([
            $email,
            $nome,
            $curso,
            $serie,
            'ALUNO'
        ]);

        $importados++;
    }

    fclose($handle);

    $pdo->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Importação concluída.',
        'importados' => $importados,
        'duplicados' => $duplicados,
        'invalidos' => $invalidos,
        'linhas_lidas' => $linha - 1
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (is_resource($handle)) {
        fclose($handle);
    }

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erro na importação de alunos: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'erro' => 'Falha na importação. Nenhuma alteração foi confirmada.'
    ], JSON_UNESCAPED_UNICODE);
}
