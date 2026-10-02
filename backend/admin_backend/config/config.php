<?php
// Configurações do sistema. Ajuste o banco e, se quiser, use variáveis de ambiente.
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'name' => getenv('DB_NAME') ?: 'db.php',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    'upload' => [
        // Fora do alcance público: os arquivos só saem pela API (e só para o admin).
        'dir'       => dirname(__DIR__) . '/storage/comprovantes',
        'max_bytes' => 10 * 1024 * 1024, // 10 MB (igual ao limite do formulário)
        'mimes'     => [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ],
    ],
];
