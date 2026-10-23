<?php

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'erro' => 'A API de pagamento foi movida para backend/pagamento_backend/api/.'
], JSON_UNESCAPED_UNICODE);
