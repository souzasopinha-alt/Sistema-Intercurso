-- Versioned schema for the parallel PHP backend.
-- Apply only to a newly created database. Existing data is copied separately.

CREATE TABLE IF NOT EXISTS app_schema_migrations (
    version VARCHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emails_autorizados (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(150) NOT NULL,
    nome VARCHAR(100) DEFAULT NULL,
    curso VARCHAR(100) DEFAULT NULL,
    serie VARCHAR(30) DEFAULT NULL,
    tipo ENUM('ALUNO','LIDER','ADMIN') NOT NULL,
    utilizado TINYINT(1) DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emails_autorizados_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    curso VARCHAR(100) DEFAULT NULL,
    serie VARCHAR(30) DEFAULT NULL,
    senha VARCHAR(255) NOT NULL,
    tipo ENUM('ALUNO','LIDER','ADMIN') NOT NULL,
    email_autorizado_id INT NOT NULL,
    envios_finalizados TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_email_autorizado (email_autorizado_id),
    CONSTRAINT fk_v2_usuarios_email_autorizado
        FOREIGN KEY (email_autorizado_id) REFERENCES emails_autorizados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(100) NOT NULL,
    valor VARCHAR(255) NOT NULL,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    numero INT NOT NULL,
    tamanho VARCHAR(10) NOT NULL,
    nome_camisa VARCHAR(50) NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 35.00,
    data_pedido TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status_pagamento ENUM('PENDENTE','APROVADO','RECUSADO') DEFAULT 'PENDENTE',
    metodo_pagamento ENUM('PIX','DINHEIRO') DEFAULT NULL,
    substituido_por_id INT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_pedidos_usuario (usuario_id),
    KEY idx_pedidos_status_data (status_pagamento, data_pedido),
    KEY idx_pedidos_substituido_por (substituido_por_id),
    CONSTRAINT fk_v2_pedidos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_v2_pedidos_substituido_por
        FOREIGN KEY (substituido_por_id) REFERENCES pedidos(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opcoes (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    nome VARCHAR(100) NOT NULL,
    imagem1 VARCHAR(255) NOT NULL,
    imagem2 VARCHAR(255) NOT NULL,
    imagem3 VARCHAR(255) NOT NULL,
    aprovado TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_opcoes_aprovado (aprovado),
    KEY idx_opcoes_usuario (usuario_id),
    CONSTRAINT fk_v2_opcoes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camisas (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    imagem VARCHAR(255) NOT NULL,
    data_envio TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_camisas_usuario (usuario_id),
    CONSTRAINT fk_v2_camisas_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS votos (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    camisa_id INT NOT NULL,
    data_voto TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votos_usuario (usuario_id),
    KEY idx_votos_camisa (camisa_id),
    CONSTRAINT fk_v2_votos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_v2_votos_camisa
        FOREIGN KEY (camisa_id) REFERENCES camisas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS votos_realizados (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    opcao_id INT NOT NULL,
    data_voto TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votos_realizados_usuario (usuario_id),
    KEY idx_votos_realizados_opcao (opcao_id),
    CONSTRAINT fk_v2_votos_realizados_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_v2_votos_realizados_opcao
        FOREIGN KEY (opcao_id) REFERENCES opcoes(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comprovantes (
    id INT NOT NULL AUTO_INCREMENT,
    pedido_id INT NOT NULL,
    arquivo VARCHAR(255) DEFAULT NULL,
    metodo_pagamento ENUM('pix','dinheiro') DEFAULT NULL,
    descricao TEXT DEFAULT NULL,
    arquivo_nome VARCHAR(64) DEFAULT NULL,
    arquivo_original VARCHAR(255) DEFAULT NULL,
    mime_type VARCHAR(50) DEFAULT NULL,
    tamanho INT UNSIGNED DEFAULT NULL,
    hash_sha256 CHAR(64) DEFAULT NULL,
    status ENUM('em_analise','aprovado','recusado') NOT NULL DEFAULT 'em_analise',
    motivo_recusa VARCHAR(255) DEFAULT NULL,
    analisado_por INT DEFAULT NULL,
    analisado_em DATETIME DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_envio TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comprovantes_pedido (pedido_id),
    KEY idx_comprovantes_hash (hash_sha256),
    KEY idx_comprovantes_status_data (status, data_envio),
    CONSTRAINT fk_v2_comprovantes_pedido
        FOREIGN KEY (pedido_id) REFERENCES pedidos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_sessions (
    session_id CHAR(64) NOT NULL,
    session_data MEDIUMBLOB NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (session_id),
    KEY idx_app_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_schema_migrations (version)
VALUES ('20261024_001_initial')
ON DUPLICATE KEY UPDATE version = VALUES(version);
