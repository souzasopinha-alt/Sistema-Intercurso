<?php
declare(strict_types=1);

/**
 * Roteador da API.
 *
 *  POST   /api/pedidos/{id}/comprovante   cliente envia comprovante (multipart) ou escolhe dinheiro
 *  GET    /api/pedidos/{id}/comprovante   ADMIN visualiza o arquivo (?id= para um comprovante específico)
 *  GET    /api/pedidos/{id}/pagamento     cliente (dono) ou admin consulta o status
 *  PATCH  /api/pedidos/{id}/pagamento     ADMIN aprova/recusa  {"acao":"aprovar"|"recusar","motivo":"..."}
 *  GET    /api/admin/pagamentos           ADMIN lista comprovantes (?status=em_analise|aprovado|recusado)
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/ComprovanteService.php';

try {
    $path   = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $pos    = strpos($path, '/api/');
    $rota   = trim($pos === false ? $path : substr($path, $pos + 5), '/');
    $metodo = $_SERVER['REQUEST_METHOD'];
    $svc    = new ComprovanteService(db());

    // ---------- /pedidos/{id}/comprovante ----------
    if (preg_match('#^pedidos/(\d+)/comprovante$#', $rota, $m)) {
        $pedidoId = (int) $m[1];

        if ($metodo === 'POST') {
            $usuarioId = Auth::exigirUsuario();

            // Quando o corpo passa de post_max_size o PHP zera $_POST e $_FILES.
            if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                throw new HttpException(413, 'O arquivo excede o limite de 10 MB.');
            }

            $resultado = $svc->enviar($pedidoId, $usuarioId, $_POST, $_FILES['comprovante'] ?? null);
            json_response($resultado, 201);
        }

        if ($metodo === 'GET') {
            Auth::exigirAdmin();
            $cid = isset($_GET['id']) ? (int) $_GET['id'] : null;
            $svc->entregarArquivo($pedidoId, $cid);
        }

        header('Allow: GET, POST');
        throw new HttpException(405, 'Método não permitido.');
    }

    // ---------- /pedidos/{id}/pagamento ----------
    if (preg_match('#^pedidos/(\d+)/pagamento$#', $rota, $m)) {
        $pedidoId = (int) $m[1];

        if ($metodo === 'GET') {
            $usuarioId = Auth::exigirUsuario();
            json_response($svc->status($pedidoId, $usuarioId, Auth::ehAdmin()));
        }

        if ($metodo === 'PATCH' || $metodo === 'POST') {
            $adminId = Auth::exigirAdmin();

            // Exigir JSON também protege contra CSRF por formulário.
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

        header('Allow: GET, PATCH');
        throw new HttpException(405, 'Método não permitido.');
    }

    // ---------- /admin/pagamentos ----------
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
