<?php

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);

echo json_encode([
    'erro' => 'Esta API foi desativada. Use backend/votacao_backend/votacao.php.'
], JSON_UNESCAPED_UNICODE);
