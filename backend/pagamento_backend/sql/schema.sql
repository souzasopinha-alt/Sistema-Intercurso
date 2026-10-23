-- Compatibility migration for the standalone payment API.
-- Run after backend/docs/banco_completo.sql on an existing system_intercurso DB.
-- The statements are safe to rerun.

SET @banco_atual = DATABASE();

SET @sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @banco_atual
          AND TABLE_NAME = 'pedidos'
          AND COLUMN_NAME = 'total'
    ),
    'SELECT 1',
    'ALTER TABLE pedidos ADD COLUMN total DECIMAL(10,2) NOT NULL DEFAULT 35.00'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @banco_atual
          AND TABLE_NAME = 'comprovantes'
          AND COLUMN_NAME = 'arquivo'
    ),
    'ALTER TABLE comprovantes MODIFY COLUMN arquivo VARCHAR(255) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'metodo_pagamento'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN metodo_pagamento ENUM(''pix'',''dinheiro'') DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'descricao'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN descricao TEXT DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'arquivo_nome'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN arquivo_nome VARCHAR(64) DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'arquivo_original'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN arquivo_original VARCHAR(255) DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'mime_type'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN mime_type VARCHAR(50) DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'tamanho'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN tamanho INT UNSIGNED DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'hash_sha256'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN hash_sha256 CHAR(64) DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'status'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN status ENUM(''em_analise'',''aprovado'',''recusado'') NOT NULL DEFAULT ''em_analise'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'motivo_recusa'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN motivo_recusa VARCHAR(255) DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'analisado_por'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN analisado_por INT DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'analisado_em'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN analisado_em DATETIME DEFAULT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @banco_atual AND TABLE_NAME = 'comprovantes' AND COLUMN_NAME = 'criado_em'),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD COLUMN criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @banco_atual
          AND TABLE_NAME = 'comprovantes'
          AND INDEX_NAME = 'idx_comprovantes_hash'
    ),
    'SELECT 1',
    'ALTER TABLE comprovantes ADD INDEX idx_comprovantes_hash (hash_sha256)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
