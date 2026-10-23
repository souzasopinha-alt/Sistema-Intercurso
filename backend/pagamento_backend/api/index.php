<?php
declare(strict_types=1);

/**
 * API de pagamento de comprovantes.
 *
 * POST /api/pedidos/{id}/comprovante
 * GET  /api/pedidos/{id}/comprovante
 * GET  /api/pedidos/{id}/pagamento
 * PATCH /api/pedidos/{id}/pagamento
 * GET  /api/admin/pagamentos
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/ComprovanteService.php';

try {
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $pos = strpos($path, '/api/');
    $rota = trim($pos === false ? $path : substr($path, $pos + 5), '/');
    $metodo = $_SERVER['REQUEST_METHOD'];
    $svc = new ComprovanteService(db());

    if (preg_match('#^pedidos/(\d+)/comprovante$#', $rota, $m)) {
        $pedidoId = (int) $m[1];

        if ($metodo === 'POST') {
            $usuarioId = Auth::exigirUsuario();
            if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                throw new HttpException(413, 'O arquivo excede o limite de 10 MB.');
            }

            json_response(
                $svc->enviar($pedidoId, $usuarioId, $_POST, $_FILES['comprovante'] ?? null),
                201
            );
        }

        if ($metodo === 'GET') {
            Auth::exigirAdmin();
            $comprovanteId = isset($_GET['id']) ? (int) $_GET['id'] : null;
            $svc->entregarArquivo($pedidoId, $comprovanteId);
        }

        header('Allow: GET, POST');
        throw new HttpException(405, 'Método não permitido.');
    }

    if (preg_match('#^pedidos/(\d+)/pagamento$#', $rota, $m)) {
        $pedidoId = (int) $m[1];

        if ($metodo === 'GET') {
            $usuarioId = Auth::exigirUsuario();
            json_response($svc->status($pedidoId, $usuarioId, Auth::ehAdmin()));
        }

        if ($metodo === 'PATCH' || $metodo === 'POST') {
            $adminId = Auth::exigirAdmin();
            if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
                throw new HttpException(415, 'Envie o corpo como application/json.');
            }

            $corpo = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($corpo)) {
                throw new HttpException(400, 'JSON inválido.');
            }

            json_response($svc->decidir(
                $pedidoId,
                (string) ($corpo['acao'] ?? ''),
                isset($corpo['motivo']) ? (string) $corpo['motivo'] : null,
                $adminId
            ));
        }

        header('Allow: GET, PATCH, POST');
        throw new HttpException(405, 'Método não permitido.');
    }

    if ($rota === 'admin/pagamentos' && $metodo === 'GET') {
        Auth::exigirAdmin();
        json_response(['dados' => $svc->listar($_GET['status'] ?? null)]);
    }

    throw new HttpException(404, 'Rota não encontrada.');
} catch (HttpException $e) {
    json_response(['erro' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log((string) $e);
    json_response(['erro' => 'Erro interno. Tente novamente em instantes.'], 500);
}
