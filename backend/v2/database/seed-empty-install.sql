-- Optional defaults for a genuinely empty installation.
-- Do not run after copying data from the existing application.
INSERT INTO configuracoes (chave, valor) VALUES
    ('envios_abertos', '1'),
    ('votacao_aberta', '0'),
    ('pedidos_abertos', '0');
