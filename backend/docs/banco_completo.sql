-- Sistema Intercurso: estrutura completa do banco de dados.
-- Pode ser executado em um banco vazio ou em um banco existente.
-- Em banco existente, as verificações no final adicionam colunas ausentes e
-- removem o índice que impedia o admin de cadastrar várias opções.
-- Selecione o banco correto no phpMyAdmin antes de executar este arquivo.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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
    CONSTRAINT fk_usuarios_email_autorizado
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
    data_pedido TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    status_pagamento ENUM('PENDENTE','APROVADO','RECUSADO') DEFAULT 'PENDENTE',
    metodo_pagamento ENUM('PIX','DINHEIRO') DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_pedidos_usuario (usuario_id),
    CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
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
    CONSTRAINT fk_opcoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camisas (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    imagem VARCHAR(255) NOT NULL,
    data_envio TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_camisas_usuario (usuario_id),
    CONSTRAINT fk_camisas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS votos (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    camisa_id INT NOT NULL,
    data_voto TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votos_usuario (usuario_id),
    KEY idx_votos_camisa (camisa_id),
    CONSTRAINT fk_votos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_votos_camisa FOREIGN KEY (camisa_id) REFERENCES camisas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS votos_realizados (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    opcao_id INT NOT NULL,
    data_voto TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_votos_realizados_usuario (usuario_id),
    KEY idx_votos_realizados_opcao (opcao_id),
    CONSTRAINT fk_votos_realizados_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_votos_realizados_opcao FOREIGN KEY (opcao_id) REFERENCES opcoes(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comprovantes (
    id INT NOT NULL AUTO_INCREMENT,
    pedido_id INT NOT NULL,
    arquivo VARCHAR(255) NOT NULL,
    data_envio TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comprovantes_pedido (pedido_id),
    CONSTRAINT fk_comprovantes_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Colunas introduzidas pela importação de alunos e pelo controle de envios.
SET @banco_atual = DATABASE();
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'emails_autorizados' AND COLUMN_NAME = 'nome'),
    'SELECT 1',
    'ALTER TABLE emails_autorizados ADD COLUMN nome VARCHAR(100) NULL AFTER email'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'emails_autorizados' AND COLUMN_NAME = 'curso'),
    'SELECT 1',
    'ALTER TABLE emails_autorizados ADD COLUMN curso VARCHAR(100) NULL AFTER nome'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'emails_autorizados' AND COLUMN_NAME = 'serie'),
    'SELECT 1',
    'ALTER TABLE emails_autorizados ADD COLUMN serie VARCHAR(30) NULL AFTER curso'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'curso'),
    'SELECT 1',
    'ALTER TABLE usuarios ADD COLUMN curso VARCHAR(100) NULL AFTER email'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'serie'),
    'SELECT 1',
    'ALTER TABLE usuarios ADD COLUMN serie VARCHAR(30) NULL AFTER curso'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'envios_finalizados'),
    'SELECT 1',
    'ALTER TABLE usuarios ADD COLUMN envios_finalizados TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Bancos antigos tinham este índice, que impedia o admin de cadastrar várias opções.
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'opcoes' AND INDEX_NAME = 'uq_opcoes_usuario'),
    'ALTER TABLE opcoes DROP INDEX uq_opcoes_usuario',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Estado inicial do sistema. Em banco existente, mantém as configurações já definidas.
INSERT INTO configuracoes (chave, valor) VALUES
    ('envios_abertos', '1'),
    ('votacao_aberta', '0'),
    ('pedidos_abertos', '0')
ON DUPLICATE KEY UPDATE chave = VALUES(chave);
