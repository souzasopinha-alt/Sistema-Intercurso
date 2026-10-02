<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$email = trim($_POST['email'] ?? '');
$senha = $_POST['senha'] ?? '';

if ($email === '' || $senha === '') {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'E-mail e senha são obrigatórios.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, nome, email, senha, tipo
         FROM usuarios
         WHERE email = ?
         LIMIT 1'
    );

    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'E-mail ou senha incorretos.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $senhaValida = password_verify($senha, (string)$usuario['senha']);

    if (!$senhaValida) {
        $senhaValida = hash_equals((string)$usuario['senha'], (string)$senha);
    }

    if (!$senhaValida) {
        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'E-mail ou senha incorretos.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['usuario_id'] = (int)$usuario['id'];
    $_SESSION['nome'] = $usuario['nome'];
    $_SESSION['email'] = $usuario['email'];
    $_SESSION['tipo'] = $usuario['tipo'];

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Login realizado com sucesso.',
        'usuario_id' => (int)$usuario['id'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'tipo' => $usuario['tipo']
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro no servidor: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
