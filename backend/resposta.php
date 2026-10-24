<?php

function resposta(
    bool $success,
    string $message = '',
    int $status = 200,
    array $extra = []
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}
