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

$supabaseUrl = 'https://ecjnysmrbylbpibsibzh.supabase.co';
$supabaseKey = getenv('SUPABASE_SERVICE_ROLE_KEY');
if (!$supabaseKey) resposta(false, 'SUPABASE_SERVICE_ROLE_KEY não está configurada no servidor.', 500);

try {
    $url = $supabaseUrl . '/rest/v1/opcoes?select=id,nome,aprovado&aprovado=eq.true&order=id.asc';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: '.$supabaseKey,
            'Authorization: Bearer '.$supabaseKey,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    $body = curl_exec($ch);
    $erro = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($erro ?: "Erro ao consultar o Supabase. HTTP {$status}.");
    }

    $opcoes = json_decode($body, true);
    if (!is_array($opcoes)) throw new RuntimeException('Resposta inválida do Supabase.');

    $ids = array_map(fn($o)=>(int)($o['id'] ?? 0), $opcoes);
    $contagens = [];

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT opcao_id,total_votos FROM contagem_votos WHERE opcao_id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contagens[(int)$row['opcao_id']] = (int)$row['total_votos'];
        }
    }

    $resultado = [];
    foreach ($opcoes as $i=>$opcao) {
        $id = (int)$opcao['id'];
        $resultado[] = [
            'id'=>$id,
            'numero'=>$i+1,
            'nome'=>$opcao['nome'] ?? ('Opção '.($i+1)),
            'total_votos'=>$contagens[$id] ?? 0
        ];
    }

    resposta(true, '', 200, ['opcoes'=>$resultado]);
} catch (Throwable $e) {
    error_log('admin_votos.php: '.$e->getMessage());
    resposta(false, $e->getMessage(), 500);
}
