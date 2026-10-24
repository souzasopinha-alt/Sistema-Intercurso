<?php
declare(strict_types=1);

final class AuthController
{
    public static function login(PDO $db): void
    {
        self::requireMethod('POST');
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['senha'] ?? '');

        if ($email === '' || $password === '') {
            v2_json([
                'sucesso' => false,
                'erro' => 'E-mail e senha são obrigatórios.',
            ], 400);
        }

        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                'SELECT id, email, nome, curso, serie, tipo, utilizado
                 FROM emails_autorizados WHERE email = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$email]);
            $authorized = $stmt->fetch();

            if (!$authorized) {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'E-mail não autorizado.'], 401);
            }

            $stmt = $db->prepare(
                'SELECT id, nome, email, senha, tipo, curso, serie
                 FROM usuarios WHERE email = ? LIMIT 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                if (!password_verify($password, (string) $user['senha'])) {
                    $db->rollBack();
                    v2_json(['sucesso' => false, 'erro' => 'E-mail ou senha incorretos.'], 401);
                }
            } else {
                if ((int) $authorized['utilizado'] !== 0) {
                    $db->rollBack();
                    v2_json([
                        'sucesso' => false,
                        'erro' => 'Este e-mail autorizado já foi utilizado e não possui uma conta correspondente. Procure o administrador.',
                    ], 401);
                }

                $name = trim((string) ($authorized['nome'] ?? '')) ?: $email;
                $stmt = $db->prepare(
                    'INSERT INTO usuarios
                        (nome, email, curso, serie, senha, tipo, email_autorizado_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $name,
                    $email,
                    $authorized['curso'],
                    $authorized['serie'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $authorized['tipo'],
                    $authorized['id'],
                ]);
                $userId = (int) $db->lastInsertId();

                $stmt = $db->prepare(
                    'UPDATE emails_autorizados SET utilizado = 1
                     WHERE id = ? AND utilizado = 0'
                );
                $stmt->execute([$authorized['id']]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Authorized email could not be claimed.');
                }

                $stmt = $db->prepare(
                    'SELECT id, nome, email, tipo, curso, serie
                     FROM usuarios WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                if (!$user) {
                    throw new RuntimeException('Created user could not be reloaded.');
                }
            }

            $role = strtoupper(trim((string) ($user['tipo'] ?? '')));
            if (!in_array($role, ['ALUNO', 'LIDER', 'ADMIN'], true)) {
                $db->rollBack();
                v2_json(['sucesso' => false, 'erro' => 'O perfil desta conta não é válido.'], 403);
            }

            $nextStep = 'uploud_frontend/uploud_camisas.html';
            if ($role === 'ALUNO') {
                if (self::setting($db, 'votacao_aberta') === '1') {
                    $nextStep = 'votacao_frontend/votacao.html';
                } elseif (self::setting($db, 'pedidos_abertos') === '1') {
                    $stmt = $db->prepare('SELECT COUNT(*) FROM pedidos WHERE usuario_id = ?');
                    $stmt->execute([$user['id']]);
                    $nextStep = (int) $stmt->fetchColumn() === 0 ? 'pedido.html' : 'perfil.html';
                }
            }

            $db->commit();
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int) $user['id'];
            $_SESSION['nome'] = (string) $user['nome'];
            $_SESSION['email'] = (string) $user['email'];
            $_SESSION['tipo'] = $role;

            v2_json([
                'sucesso' => true,
                'mensagem' => 'Login realizado com sucesso.',
                'tipo' => $role,
                'proximo_passo' => $nextStep,
            ]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            v2_log_error('V2 login', $error);
            v2_json(['sucesso' => false, 'erro' => 'Erro interno ao realizar login.'], 500);
        }
    }

    public static function logout(): void
    {
        self::requireMethod('POST');
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        v2_json(['sucesso' => true, 'mensagem' => 'Sessão encerrada.']);
    }

    public static function adminSession(PDO $db): void
    {
        $userId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            v2_json(['logado' => false, 'admin' => false]);
        }

        $stmt = $db->prepare('SELECT tipo FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $isAdmin = strtoupper(trim((string) $stmt->fetchColumn())) === 'ADMIN';

        v2_json([
            'logado' => true,
            'admin' => $isAdmin,
            'nome' => $isAdmin ? ($_SESSION['nome'] ?? 'Administrador') : null,
            'email' => $isAdmin ? ($_SESSION['email'] ?? '') : null,
        ]);
    }

    private static function setting(PDO $db, string $key): ?string
    {
        $stmt = $db->prepare('SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            v2_json([
                'sucesso' => false,
                'erro' => 'Método não permitido.',
            ], 405);
        }
    }
}
