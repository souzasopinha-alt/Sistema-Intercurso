<?php

session_start();

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

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
    $pdo->beginTransaction();

    // Bloqueia o registro durante a validação do primeiro acesso.
    $stmt = $pdo->prepare("
        SELECT id, email, nome, curso, serie, tipo, utilizado
        FROM emails_autorizados
        WHERE email = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$email]);
    $autorizado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$autorizado) {
        $pdo->rollBack();

        http_response_code(401);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'E-mail não autorizado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verifica se já existe uma conta para esse e-mail.
    $stmt = $pdo->prepare("
        SELECT id, nome, email, senha, tipo, curso, serie
        FROM usuarios
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario) {
        // Usuários existentes continuam entrando com sua senha.
        if (!password_verify($senha, $usuario['senha'])) {
            $pdo->rollBack();

            http_response_code(401);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'E-mail ou senha incorretos.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // Um e-mail já utilizado não pode criar outra conta.
        if ((int) $autorizado['utilizado'] !== 0) {
            $pdo->rollBack();

            http_response_code(401);
            echo json_encode([
                'sucesso' => false,
                'erro' => 'Este e-mail autorizado já foi utilizado e não possui uma conta correspondente. Procure o administrador.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $nome = trim($autorizado['nome'] ?? '');

        if ($nome === '') {
            $nome = $email;
        }

        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (
                nome,
                email,
                curso,
                serie,
                senha,
                tipo,
                email_autorizado_id
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

        // Só marca como utilizado se ainda estiver disponível.
        $stmt = $pdo->prepare("
            UPDATE emails_autorizados
            SET utilizado = 1
            WHERE id = ?
              AND utilizado = 0
        ");
        $stmt->execute([$autorizado['id']]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Não foi possível confirmar o uso do e-mail autorizado.'
            );
        }

        $stmt = $pdo->prepare("
    SELECT id, nome, email, tipo
    FROM usuarios
    WHERE email = ?
    LIMIT 1
");
$stmt->execute([$email]);

$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    throw new RuntimeException(
        'A conta foi criada, mas não foi possível recuperá-la.'
    );
}
    }

    // Confirma a criação da conta e a atualização do e-mail em conjunto.
    $pdo->commit();

    // Regenera o identificador da sessão após autenticar.
    session_regenerate_id(true);

    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['nome'] = $usuario['nome'];
    $_SESSION['email'] = $usuario['email'];
    $_SESSION['tipo'] = $usuario['tipo'];

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Login realizado com sucesso.',
        'tipo' => $usuario['tipo']
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erro no login: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro interno ao realizar login.'
    ], JSON_UNESCAPED_UNICODE);
}
