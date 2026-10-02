<?php
declare(strict_types=1);

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function config(string $key): array
{
    return $GLOBALS['config'][$key];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Autenticação baseada na sessão do seu login atual.
 * >>> Se o seu login usa outras chaves de sessão, ajuste APENAS esta classe. <<<
 *   $_SESSION['usuario_id']     -> id do usuário logado
 *   $_SESSION['usuario_perfil'] -> 'admin' para administradores
 */
final class Auth
{
    public static function usuarioId(): ?int
    {
        return isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
    }

    public static function exigirUsuario(): int
    {
        return self::usuarioId() ?? throw new HttpException(401, 'Faça login para continuar.');
    }

    public static function ehAdmin(): bool
    {
        return self::usuarioId() !== null && ($_SESSION['usuario_perfil'] ?? '') === 'admin';
    }

    public static function exigirAdmin(): int
    {
        if (!self::ehAdmin()) {
            throw new HttpException(self::usuarioId() ? 403 : 401, 'Acesso restrito a administradores.');
        }
        return self::usuarioId();
    }
}
