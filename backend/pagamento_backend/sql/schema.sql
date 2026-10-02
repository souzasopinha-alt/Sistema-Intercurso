-- 1) Status do pagamento no pedido (ajuste se sua tabela tiver outro nome/colunas).
--    Se a coluna já existir, ignore este comando.
ALTER TABLE pedidos
  ADD COLUMN status_pagamento ENUM('pendente','em_analise','aprovado','recusado')
  NOT NULL DEFAULT 'pendente';

-- 2) Comprovantes: um pedido pode ter vários (histórico de recusas + reenvio).
CREATE TABLE IF NOT EXISTS comprovantes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pedido_id        INT NOT NULL,                      -- mesmo tipo de pedidos.id
  metodo_pagamento ENUM('pix','dinheiro') NOT NULL,
  descricao        TEXT NULL,
  arquivo_nome     VARCHAR(64)  NULL,                 -- nome aleatório salvo em disco
  arquivo_original VARCHAR(255) NULL,                 -- nome enviado pelo cliente (só exibição)
  mime_type        VARCHAR(50)  NULL,
  tamanho          INT UNSIGNED NULL,
  hash_sha256      CHAR(64)     NULL,                 -- detecta o mesmo comprovante em outro pedido
  status           ENUM('em_analise','aprovado','recusado') NOT NULL DEFAULT 'em_analise',
  motivo_recusa    VARCHAR(255) NULL,
  analisado_por    INT NULL,
  analisado_em     DATETIME NULL,
  criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_comprovantes_pedido (pedido_id),
  INDEX idx_comprovantes_status (status),
  INDEX idx_comprovantes_hash (hash_sha256),
  CONSTRAINT fk_comprovantes_pedido FOREIGN KEY (pedido_id)
    REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
