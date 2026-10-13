<?php

session_start();

require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

$email = trim($_POST['email'] ?? '');
$senha = $_POST['senha'] ?? '';

if ($email === '' || $senha === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'E-mail e senha são obrigatórios.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, email, nome, curso, serie, tipo, utilizado
        FROM emails_autorizados
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $autorizado = $stmt->fetch();

    if (!$autorizado) {
        http_response_code(401);
        echo json_encode(['erro' => 'E-mail não autorizado.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, nome, email, senha, tipo, curso, serie
        FROM usuarios
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        $nome = trim($autorizado['nome'] ?? '');

        if ($nome === '') {
            $nome = $email;
        }

        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (
                nome, email, curso, serie, senha, tipo, email_autorizado_id
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $nome,
            $email,
            $autorizado['curso'],
            $autorizado['serie'],
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
            'nome' => $nome,
            'email' => $email,
            'senha' => $senhaHash,
            'tipo' => $autorizado['tipo'],
            'curso' => $autorizado['curso'],
            'serie' => $autorizado['serie']
        ];
    } elseif (!password_verify($senha, $usuario['senha'])) {
        http_response_code(401);
        echo json_encode(['erro' => 'E-mail ou senha incorretos.']);
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['nome'] = $usuario['nome'];
    $_SESSION['email'] = $usuario['email'];
    $_SESSION['tipo'] = $usuario['tipo'];

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Login realizado com sucesso.',
        'tipo' => $usuario['tipo']
    ]);
} catch (PDOException $e) {
    error_log('Erro no login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno ao realizar login.']);
}
