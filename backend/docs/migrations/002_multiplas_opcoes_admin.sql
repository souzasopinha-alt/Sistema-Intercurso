-- Permite que um administrador cadastre mais de uma opção de votação.
-- Execute uma vez em bancos existentes antes de publicar a nova função.
ALTER TABLE opcoes DROP INDEX uq_opcoes_usuario;
