<?php

session_start();

require_once 'db.php';

header('Content-Type: application/json');

$email = $_POST['email'] ?? '';
$senha = $_POST['senha'] ?? '';

if ($email === '' || $senha === '') {
    http_response_code(400);

    echo json_encode([
        'erro' => 'E-mail e senha são obrigatórios.'
    ]);

    exit;
}

$stmt = $pdo->prepare("
    SELECT id, email, tipo, utilizado
    FROM emails_autorizados
    WHERE email = ?
");

$stmt->execute([$email]);

$autorizado = $stmt->fetch();

if (!$autorizado) {
    http_response_code(401);

    echo json_encode([
        'erro' => 'E-mail não autorizado.'
    ]);

    exit;
}
$stmt = $pdo->prepare("
    SELECT id, nome, email, senha, tipo
    FROM usuarios
    WHERE email = ?
");

$stmt->execute([$email]);

$usuario = $stmt->fetch();

if (!$usuario) {
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO usuarios (
            nome,
            email,
            senha,
            tipo,
            email_autorizado_id
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $email,
        $email,
        $senhaHash,
        $autorizado['tipo'],
        $autorizado['id']
    ]);

    $stmt = $pdo->prepare("
        UPDATE emails_autorizados
        SET utilizado = 1
        WHERE id = ?
    ");

    $stmt->execute([$autorizado['id']]);

    $usuario = [
        'id' => $pdo->lastInsertId(),
        'nome' => $email,
        'email' => $email,
        'senha' => $senhaHash,
        'tipo' => $autorizado['tipo']
    ];
} elseif (!password_verify($senha, $usuario['senha'])) {
    http_response_code(401);

    echo json_encode([
        'erro' => 'E-mail ou senha incorretos.'
    ]);

    exit;
}

$_SESSION['usuario_id'] = $usuario['id'];
$_SESSION['nome'] = $usuario['nome'];
$_SESSION['email'] = $usuario['email'];
$_SESSION['tipo'] = $usuario['tipo'];

echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Login realizado com sucesso.',
    'tipo' => $usuario['tipo']
]);
