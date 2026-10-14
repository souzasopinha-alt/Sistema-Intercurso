-- Sistema Intercurso
-- Migração 001: estrutura da votação
-- A votação começa fechada.

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

    CONSTRAINT fk_opcoes_usuario
        FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS votos_realizados (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    opcao_id INT NOT NULL,
    data_voto TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_votos_usuario (usuario_id),
    KEY idx_votos_opcao (opcao_id),

    CONSTRAINT fk_votos_realizados_usuario
        FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_votos_realizados_opcao
        FOREIGN KEY (opcao_id)
        REFERENCES opcoes(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(100) NOT NULL,
    valor VARCHAR(255) NOT NULL,

    PRIMARY KEY (chave)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


INSERT INTO configuracoes (chave, valor)
VALUES ('votacao_aberta', '0')
ON DUPLICATE KEY UPDATE chave = VALUES(chave);
