<?php
declare(strict_types=1);

function v2_config(string $key, ?string $fallback = null): ?string
{
    static $fileConfig;
    if ($fileConfig === null) {
        $file = __DIR__ . '/.env';
        $fileConfig = is_file($file) ? (parse_ini_file($file) ?: []) : [];
    }

    $railwayKeys = [
        'DB_HOST' => 'MYSQLHOST',
        'DB_PORT' => 'MYSQLPORT',
        'DB_NAME' => 'MYSQLDATABASE',
        'DB_USER' => 'MYSQLUSER',
        'DB_PASS' => 'MYSQLPASSWORD',
    ];
    $candidates = array_filter([$key, $railwayKeys[$key] ?? null]);

    foreach ($candidates as $candidate) {
        $value = getenv($candidate);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($fileConfig[$candidate]) && $fileConfig[$candidate] !== '') {
            return (string) $fileConfig[$candidate];
        }
    }

    return $fallback;
}

function v2_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = v2_config('DB_HOST', 'localhost');
    $port = v2_config('DB_PORT', '3306');
    $name = v2_config('DB_NAME');
    $user = v2_config('DB_USER');
    $pass = v2_config('DB_PASS', '');

    if ($name === null || $user === null) {
        throw new RuntimeException('Database environment is incomplete.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

final class V2DatabaseSessionHandler implements SessionHandlerInterface
{
    private ?string $lockName = null;

    public function __construct(private PDO $db) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        if ($this->lockName !== null) {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$this->lockName]);
            $this->lockName = null;
        }

        return true;
    }

    public function read(string $id): string|false
    {
        $this->lockName = 'ics_' . substr(hash('sha256', $id), 0, 60);
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$this->lockName]);

        if ((int) $stmt->fetchColumn() !== 1) {
            error_log('Could not acquire the database session lock.');
            $this->lockName = null;
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT session_data, expires_at
             FROM app_sessions
             WHERE session_id = ?'
        );
        $stmt->execute([$id]);
        $session = $stmt->fetch();

        if (!$session || (int) $session['expires_at'] < time()) {
            if ($session) {
                $this->destroy($id);
            }
            return '';
        }

        return (string) $session['session_data'];
    }

    public function write(string $id, string $data): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO app_sessions (session_id, session_data, expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                session_data = VALUES(session_data),
                expires_at = VALUES(expires_at)'
        );

        return $stmt->execute([
            $id,
            $data,
            time() + (int) ini_get('session.gc_maxlifetime')
        ]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM app_sessions WHERE session_id = ?'
        );

        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->db->prepare(
            'DELETE FROM app_sessions WHERE expires_at < ?'
        );
        $stmt->execute([time()]);

        return $stmt->rowCount();
    }
}

function v2_start_session(PDO $db): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    );

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('intercurso_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_set_save_handler(new V2DatabaseSessionHandler($db), true);

    if (!session_start()) {
        throw new RuntimeException('Unable to start the application session.');
    }
}

function v2_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function v2_require_user(): int
{
    $userId = filter_var(
        $_SESSION['usuario_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    if (!$userId || $userId < 1) {
        v2_json(['sucesso' => false, 'erro' => 'Usuário não autenticado.'], 401);
    }

    return $userId;
}

function v2_require_admin(PDO $db): int
{
    $userId = v2_require_user();
    $stmt = $db->prepare('SELECT tipo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);

    if (strtoupper(trim((string) $stmt->fetchColumn())) !== 'ADMIN') {
        v2_json(['success' => false, 'message' => 'Acesso permitido somente para administradores.'], 403);
    }

    return $userId;
}

function v2_log_error(string $context, Throwable $error): void
{
    error_log($context . ': ' . $error->getMessage());
}
