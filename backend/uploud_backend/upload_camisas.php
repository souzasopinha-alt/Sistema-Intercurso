<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

function resposta(bool $ok, string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $ok,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    resposta(false, 'Método não permitido.', 405);
}

if (empty($_SESSION['usuario_id'])) {
    resposta(false, 'Você precisa estar logado para enviar as camisas.', 401);
}

$usuarioId = (int) $_SESSION['usuario_id'];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    resposta(false, 'A conexão com o MariaDB não foi encontrada. Verifique o db.php.', 500);
}

// Confirma no MariaDB quem está logado. Não confiamos em nome/e-mail enviados pelo navegador.
$stmt = $pdo->prepare('SELECT id, nome, email FROM usuarios WHERE id = ? LIMIT 1');
$stmt->execute([$usuarioId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    resposta(false, 'Usuário da sessão não foi encontrado no MariaDB.', 401);
}

foreach (['imagem1', 'imagem2', 'imagem3'] as $campo) {
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
        resposta(false, "A imagem {$campo} não foi recebida corretamente.", 400);
    }
}

// Limite de segurança do servidor. A compressão continua sendo feita no navegador.
$maxBytes = 10 * 1024 * 1024;
$permitidos = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];

$arquivos = [];
$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach (['imagem1', 'imagem2', 'imagem3'] as $campo) {
    $tmp = $_FILES[$campo]['tmp_name'];
    $tamanho = (int) $_FILES[$campo]['size'];
    $mime = $finfo->file($tmp);

    if ($tamanho <= 0 || $tamanho > $maxBytes) {
        resposta(false, "A {$campo} ultrapassa o limite permitido de 10 MB.", 400);
    }

    if (!isset($permitidos[$mime])) {
        resposta(false, "A {$campo} não é um formato de imagem permitido.", 400);
    }

    $arquivos[$campo] = [
        'tmp' => $tmp,
        'mime' => $mime,
        'ext' => $permitidos[$mime]
    ];
}

/*
 * CHAVE DO SUPABASE:
 * Coloque a Service Role Key SOMENTE no servidor, nunca no HTML/JS.
 * Preferencialmente configure a variável de ambiente SUPABASE_SERVICE_ROLE_KEY.
 */
$supabaseUrl = 'https://ecjnysmrbylbpibsibzh.supabase.co';
$supabaseKey = getenv('SUPABASE_SERVICE_ROLE_KEY');

if (!$supabaseKey) {
    resposta(false, 'SUPABASE_SERVICE_ROLE_KEY não está configurada no servidor.', 500);
}

$bucket = 'imagens';
$uploadId = bin2hex(random_bytes(12));
$basePath = 'usuarios/' . $usuarioId . '/' . $uploadId;
$paths = [];
$enviados = [];

function supabaseRequest(string $url, string $method, string $key, $body, string $contentType): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Content-Type: ' . $contentType,
            'x-upsert: false'
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Erro de conexão com o Supabase: ' . $error);
    }

    return [$status, $response];
}

try {
    foreach ($arquivos as $campo => $arquivo) {
        $path = $basePath . '/' . $campo . '.' . $arquivo['ext'];
        $url = $supabaseUrl . '/storage/v1/object/' . $bucket . '/' . str_replace('%2F', '/', rawurlencode($path));
        $conteudo = file_get_contents($arquivo['tmp']);

        [$status, $response] = supabaseRequest(
            $url,
            'POST',
            $supabaseKey,
            $conteudo,
            $arquivo['mime']
        );

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Falha ao enviar {$campo} para o Storage. HTTP {$status}: {$response}");
        }

        $paths[$campo] = $path;
        $enviados[] = $path;
    }

    // O nome/e-mail vêm do MariaDB e o usuario_id vem da sessão PHP.
    $payload = json_encode([
        'usuario_id' => $usuarioId,
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'imagem1' => $paths['imagem1'],
        'imagem2' => $paths['imagem2'],
        'imagem3' => $paths['imagem3'],
        'aprovado' => true
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($supabaseUrl . '/rest/v1/opcoes');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $supabaseKey,
            'Authorization: Bearer ' . $supabaseKey,
            'Content-Type: application/json',
            'Prefer: return=representation'
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60
    ]);

    $dbResponse = curl_exec($ch);
    $dbError = curl_error($ch);
    $dbStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($dbResponse === false || $dbStatus < 200 || $dbStatus >= 300) {
        throw new RuntimeException('As imagens foram enviadas, mas não foi possível salvar o registro na tabela opcoes. ' . ($dbError ?: "HTTP {$dbStatus}: {$dbResponse}"));
    }

    resposta(true, 'As três imagens foram enviadas e vinculadas ao usuário logado.', 200, [
        'usuario_id' => $usuarioId,
        'nome' => $usuario['nome'],
        'email' => $usuario['email']
    ]);

} catch (Throwable $e) {
    // Tenta apagar os arquivos já enviados para não deixar lixo no Storage.
    foreach ($enviados as $path) {
        $url = $supabaseUrl . '/storage/v1/object/' . $bucket . '/' . str_replace('%2F', '/', rawurlencode($path));
        try {
            supabaseRequest($url, 'DELETE', $supabaseKey, '', 'application/octet-stream');
        } catch (Throwable $ignore) {
        }
    }

    error_log('upload_camisas.php: ' . $e->getMessage());
    resposta(false, $e->getMessage(), 500);
}
