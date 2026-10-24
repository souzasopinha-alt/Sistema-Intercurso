<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/AuthController.php';
require_once __DIR__ . '/src/OrderController.php';
require_once __DIR__ . '/src/PaymentController.php';
require_once __DIR__ . '/src/VotingController.php';
require_once __DIR__ . '/src/AdminController.php';

$route = trim((string) ($_GET['route'] ?? $_SERVER['PATH_INFO'] ?? ''), '/');
if ($route === '') {
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $route = is_string($requestPath)
        ? preg_replace('#^/backend/#', '', $requestPath)
        : '';
}

if ($route === 'health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'backend' => 'v2']);
    exit;
}

$routes = [
    'login.php' => static function (PDO $db): void {
        AuthController::login($db);
    },
    'logout.php' => static function (PDO $db): void {
        AuthController::logout();
    },
    'perfil_aluno.php' => static function (PDO $db): void {
        OrderController::profile($db);
    },
    'criar_pedido.php' => static function (PDO $db): void {
        OrderController::create($db);
    },
    'pagamentos/comprovante.php' => static function (PDO $db): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            PaymentController::status($db);
        } else {
            PaymentController::submit($db);
        }
    },
    'pagamentos/aprovar.php' => static function (PDO $db): void {
        PaymentController::decide($db, 'APROVADO');
    },
    'pagamentos/recusar.php' => static function (PDO $db): void {
        PaymentController::decide($db, 'RECUSADO');
    },
    'pagamentos/visualizar_comprovante.php' => static function (PDO $db): void {
        PaymentController::viewReceipt($db);
    },
    'admin_backend/auth/verificar_sessao.php' => static function (PDO $db): void {
        AuthController::adminSession($db);
    },
    'admin_backend/admin_pedidos.php' => static function (PDO $db): void {
        AdminController::orders($db, false);
    },
    'pedidos.php' => static function (PDO $db): void {
        AdminController::orders($db, true);
    },
    'admin_backend/admin.php' => static function (PDO $db): void {
        AdminController::summary($db);
    },
    'admin_backend/admin_votos.php' => static function (PDO $db): void {
        VotingController::adminResults($db);
    },
    'usuarios.php' => static function (PDO $db): void {
        AdminController::users($db);
    },
    'importar_alunos.php' => static function (PDO $db): void {
        AdminController::importAuthorized($db);
    },
    'admin_backend/estado_acoes.php' => static function (PDO $db): void {
        AdminController::state($db, 'acoes');
    },
    'admin_backend/estado_votacao.php' => static function (PDO $db): void {
        AdminController::state($db, 'votacao');
    },
    'admin_backend/iniciar_votacao.php' => static function (PDO $db): void {
        AdminController::changeState($db, 'iniciar_votacao');
    },
    'admin_backend/encerrar_votacao.php' => static function (PDO $db): void {
        AdminController::changeState($db, 'encerrar_votacao');
    },
    'admin_backend/reabrir_envios.php' => static function (PDO $db): void {
        AdminController::changeState($db, 'reabrir_envios');
    },
    'admin_backend/reabrir_votacao.php' => static function (PDO $db): void {
        AdminController::changeState($db, 'reabrir_votacao');
    },
    'admin_backend/iniciar_pedidos.php' => static function (PDO $db): void {
        AdminController::changeState($db, 'iniciar_pedidos');
    },
    'admin_backend/encerrar_pedidos.php' => static function (PDO $db): void {
        AdminController::changeState($db, 'encerrar_pedidos');
    },
    'admin_backend/reabrir_pedidos.php' => static function (PDO $db): void {
        AdminController::changeState($db, 'reabrir_pedidos');
    },
    'admin_backend/excluir_pedido.php' => static function (PDO $db): void {
        AdminController::deleteOrder($db);
    },
    'admin_backend/excluir_usuario.php' => static function (PDO $db): void {
        AdminController::deleteUser($db);
    },
    'admin_backend/adicionar_opcao_votacao.php' => static function (PDO $db): void {
        VotingController::submitProposal($db, true);
    },
    'admin_backend/excluir_opcao_votacao.php' => static function (PDO $db): void {
        VotingController::deleteAdminOption($db);
    },
    'uploud_backend/upload_camisas.php' => static function (PDO $db): void {
        VotingController::submitProposal($db, false);
    },
    'uploud_backend/listar_opcoes.php' => static function (PDO $db): void {
        VotingController::listOwnProposals($db);
    },
    'uploud_backend/estado_envios.php' => static function (PDO $db): void {
        VotingController::submissionState($db);
    },
    'uploud_backend/finalizar_envios.php' => static function (PDO $db): void {
        VotingController::finalizeSubmissions($db);
    },
    'votacao_backend/votacao.php' => static function (PDO $db): void {
        VotingController::vote($db);
    },
    'admin_backend/exportar_pedidos.php' => static function (PDO $db): void {
        AdminController::exportOrders($db);
    },
];

if (!isset($routes[$route])) {
    v2_json(['erro' => 'Rota não encontrada.'], 404);
}

try {
    $db = v2_db();
    v2_start_session($db);
    $routes[$route]($db);
} catch (Throwable $error) {
    v2_log_error('V2 request ' . $route, $error);
    v2_json(
        str_starts_with($route, 'admin_backend/')
            ? ['success' => false, 'message' => 'Serviço temporariamente indisponível.']
            : ['sucesso' => false, 'erro' => 'Serviço temporariamente indisponível.'],
        500
    );
}
