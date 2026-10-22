-- Permite que um administrador cadastre mais de uma opção de votação.
-- Pode ser executada em bancos antigos ou novos, mesmo que o índice já tenha
-- sido removido anteriormente.
SET @banco_atual = DATABASE();
SET @sql = IF(
    EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @banco_atual
          AND TABLE_NAME = 'opcoes'
          AND INDEX_NAME = 'uq_opcoes_usuario'
    ),
    'ALTER TABLE opcoes DROP INDEX uq_opcoes_usuario',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
