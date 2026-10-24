# Backend PHP v2 (paralelo)

Esta implementação fica isolada do backend atualmente consumido pelo frontend.
Ela não está ativa e não altera o banco de produção.

## Requisitos

- PHP 8.0 ou mais recente com `pdo_mysql`, `fileinfo`, `mbstring` e `gd`.
- MariaDB/MySQL em um banco vazio criado para a nova versão.
- O frontend deve continuar sendo servido pela mesma origem para que os cookies
  de sessão existentes no navegador sejam enviados corretamente.

## Preparação do banco novo

1. Crie um banco novo separado do banco atual.
2. Aplique manualmente `database/schema.sql` somente nesse banco vazio.
3. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` para o
   banco novo.
4. Confirme que as tabelas de negócio estão vazias antes de copiar os dados.

O schema contém as mesmas entidades funcionais do sistema atual, com nomes de
colunas compatíveis para permitir copiar os registros sem recriar contas ou
perder referências. A tabela `app_sessions` é nova; sessões temporárias não são
copiadas.
Em uma instalação realmente vazia, aplique também
`database/seed-empty-install.sql`. Não aplique esse seed depois da cópia.
Para iniciar uma instalação sem migrar usuários, cadastre o e-mail do primeiro
administrador em `emails_autorizados` com `tipo = 'ADMIN'`; o fluxo de primeiro
acesso atual continua definindo a senha. Não use credenciais de exemplo em
produção.

## Cópia explícita de dados

Configure `SOURCE_DB_HOST`, `SOURCE_DB_PORT`, `SOURCE_DB_NAME`,
`SOURCE_DB_USER` e `SOURCE_DB_PASS` para uma conta de leitura do banco atual.
Deixe `DB_*` apontando exclusivamente para o banco novo.

Primeiro execute apenas o modo de simulação:

```sh
php backend/v2/bin/copy-data.php
```

Revise as contagens por tabela. Só depois execute a cópia:

```sh
php backend/v2/bin/copy-data.php --apply
```

O comando recusa tabelas de destino que já contenham dados, nunca apaga linhas,
mantém os IDs e senhas hash e verifica as contagens antes de confirmar. Ele não
copia arquivos de uploads nem sessões. Para manter referências de imagens e
comprovantes, monte no serviço novo os volumes persistentes correspondentes e
confirme que os caminhos configurados continuam disponíveis. Não execute
simultaneamente com gravações na origem; faça a janela final de cópia com a
aplicação em modo de manutenção.

## Railway

Para testar localmente sem misturar as credenciais do backend antigo, copie
`.env.example` para `backend/v2/.env` e preencha as variáveis do banco novo.
Esse arquivo é ignorado pelo Git.

O runtime entende as variáveis padrão `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASS` e também `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`,
`MYSQLUSER`, `MYSQLPASSWORD`. Não versionar `.env` nem valores secretos.

Configure `PAYMENT_UPLOAD_DIR` e `CAMISAS_UPLOAD_DIR` para volumes persistentes
graváveis pelo PHP. Monte o volume de imagens em
`/var/www/html/backend/uploads/camisas` e use esse mesmo caminho em
`CAMISAS_UPLOAD_DIR`, pois o frontend atual abre essas imagens pelo caminho
público `/backend/uploads/camisas/...`. O volume de comprovantes pode ser
montado em `/var/www/html/backend/pagamentos/uploads` e usado também como
`PAYMENT_UPLOAD_DIR`; isso mantém os caminhos gravados existentes. Se o ambiente
atual usa outro local, preserve o volume antigo e configure uma cópia sincronizada
antes do corte. Aponte o novo serviço para o banco novo durante o estágio.
Não faça cutover do tráfego até validar todos os endpoints consumidos pelo
frontend atual e comparar os dados copiados.

## Ativar os caminhos atuais do frontend

O frontend continuará chamando os caminhos PHP existentes. Para testar o v2
sem substituir esses endpoints, use `index.php?route=<caminho-relativo>`; por
exemplo, `/backend/v2/index.php?route=health`.

Somente depois da validação em staging, copie as regras de
`route-rewrite.htaccess.example` para `backend/.htaccess`. Isso encaminha os
endereços atuais ao roteador v2 sem alterar os arquivos do frontend. Mantenha
uma cópia das regras anteriores para reverter o cutover rapidamente.
