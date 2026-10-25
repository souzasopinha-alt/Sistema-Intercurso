# Relatório de reforma do backend e preparação de cutover seguro

## 1. Objetivo e escopo

Este relatório documenta a situação técnica do backend paralelo em `backend/v2/` e o estado real da migração/cutover do Sistema Intercurso, sem alterar o backend legado em produção nem reescrever o frontend atual.

O escopo foi verificar:

- a arquitetura do backend legado e do v2;
- a compatibilidade das rotas com os endpoints reais consumidos pelo frontend;
- a segurança do schema e da rotina de cópia de dados;
- a necessidade de staging/validacão real antes de qualquer troca de tráfego;
- o estado atual do cutover e do rollback.

O objetivo final não foi “ativar o v2 em produção”, mas confirmar que o sistema legado permanece ativo enquanto o v2 aguarda validação em staging com banco novo, volumes persistentes e aprovação do responsável.

## 2. Arquitetura anterior e nova

### Backend legado

O frontend atual continua apontando para os endpoints do backend legado, principalmente em:

- `backend/login.php`
- `backend/perfil_aluno.php`
- `backend/criar_pedido.php`
- `backend/pagamentos/...`
- `backend/admin_backend/...`
- `backend/uploud_backend/...`
- `backend/votacao_backend/votacao.php`

A inspeção do arquivo `backend/votacao_backend/api/index.php` mostrou que ele não é o endpoint ativo da votação: ele responde com `410` e informa que a API foi movida para `backend/pagamento_backend/api/`. O frontend real de votação acessa `../../backend/votacao_backend/votacao.php`, conforme `front-end/votacao_frontend/votacao.html` e `front-end/uploud_frontend/uploud_camisas.html`.

Isso confirma que o cutover precisa considerar o endereço real do frontend, não apenas a pasta de API legada vazia.

### Backend v2

A implementação migrada foi criada em `backend/v2/` e mantém isolamento claro:

- `backend/v2/index.php`: roteador que resolve por `route` ou `PATH_INFO`;
- `backend/v2/bootstrap.php`: bootstrap com carregamento de `.env`, PDO e sessão em banco;
- `backend/v2/src/AuthController.php`, `OrderController.php`, `PaymentController.php`, `VotingController.php`, `AdminController.php`: handlers para os fluxos do frontend;
- `backend/v2/database/schema.sql`: schema do backend paralelo;
- `backend/v2/bin/copy-data.php`: rotina segura de cópia de dados com dry-run e proteção contra sobrescrita;
- `backend/v2/route-rewrite.htaccess.example`: exemplo de rewrite para cutover no Apache, mas não ativo;
- `backend/v2/.env.example`: variáveis do banco novo e do volume persistente.

O README do v2 também deixa explícito que ele fica isolado, não está ativo e não deve alterar o banco de produção.

### Estado do sistema em produção

O backend legado permanece disponível e foi mantido ativo; o v2 não foi conectado ao tráfego do frontend nem ao banco de produção. A confirmação foi feita pela análise do código e pela ausência de qualquer ativação de rewrite em produção no repositório.

## 3. Mudanças realizadas e preservadas

### O que já existia no worktree e foi preservado

A partir do contexto do projeto, o worktree já continha correções anteriores em login, sessão, redirecionamento, loop de votação e alguns ajustes de pagamento. Não houve reversão nem descarte dessas alterações; elas foram mantidas intactas.

### O que foi validado/inspecionado neste ciclo

- `backend/v2/index.php`: roteador mapeia as rotas compatíveis com os endpoints do frontend atual;
- `backend/v2/bootstrap.php`: sessão em banco e carregamento de configurações via `.env`/Railway;
- `backend/v2/src/AuthController.php`: login, criação do primeiro usuário, sessão e autorização;
- `backend/v2/src/OrderController.php`: criação e consulta de pedidos; regras de pedido único/recusa/substituição;
- `backend/v2/src/PaymentController.php`: upload de comprovante, análise, visualização e persistência;
- `backend/v2/src/VotingController.php`: estado da votação, listagem, envio de propostas e registro de votos;
- `backend/v2/src/AdminController.php`: estados, clientes, importações e ações administrativas;
- `backend/v2/bin/copy-data.php`: validações de origem/destino e bloqueio de sobrescrita de tabela não vazia;
- `backend/v2/README.md`: documentação de migração e cutover com instruções de segurança;
- `backend/v2/route-rewrite.htaccess.example`: exemplo de roteamento para produção, mas não atuante.

## 4. Problemas e evidências verificadas

### 4.1. Contrato de response e autenticação tem que seguir o frontend real

Evidência:

- `front-end/login.js` aceita `resultado.sucesso`, `resultado.erro`, `resultado.mensagem`, `resultado.tipo`, `resultado.proximo_passo`.
- `front-end/uploud_frontend/uploud_camisas.html` usa `success`, `message`, `opcoes`, `envios_abertos`, `envios_finalizados`.
- `front-end/votacao_frontend/votacao.html` usa `success`, `message`, `opcoes`, `ja_votou`, `votacao_aberta`.
- `front-end/admin_frontend/admin.js` usa `success`/`message` em administradores e `dados` sem wrapper em alguns endpoints.

Conclusão:

- o v2 está alinhado ao contrato `success/message` e ao formato de JSON que o frontend moderno espera;
- isso é um ponto de compatibilidade que foi observado e preservado como requisito para cutover.

### 4.2. A rota de votação legado em `backend/votacao_backend/api/index.php` não oferece a API ativa

Evidência:

- `backend/votacao_backend/api/index.php` retorna `410` com a mensagem “A API de pagamento foi movida...”.
- `front-end/votacao_frontend/votacao.html` chama `../../backend/votacao_backend/votacao.php` diretamente e não a pasta `api/`.

Conclusão:

- não há justificativa para alterar a rota de votação partindo da pasta `api/` do legado, porque o fluxo real do frontend usa outro arquivo;
- qualquer cutover de votação precisa mapear o endpoint real do frontend e validar isto em staging antes do corte.

### 4.3. O v2 está isolado e não foi validado com banco real

Evidência:

- `backend/v2/README.md` exige novo banco vazio e `.env` separado;
- `backend/v2/.env.example` define entradas de `DB_*` e `SOURCE_DB_*` sem valores preenchidos;
- `backend/v2/bin/copy-data.php` exige `SOURCE_DB_*` e `DB_*` diferentes, e rejeita destino não vazio;
- `Dockerfile` cria diretórios persistentes, mas não há staging com credenciais reais nesta execução.

Conclusão:

- o schema e a cópia foram implementados com proteção, mas nunca executados contra um banco real nesta sessão;
- isso bloqueia qualquer conclusão de “pronto para produção”.

### 4.4. Não houve prova de que o schema do repositório seja idêntico ao banco real

Evidência:

- `backend/docs/schema.sql` e `backend/docs/banco_completo.sql` são artefatos versionados no repositório;
- o README do v2 explicitamente diz que `backend/docs/schema.sql` não é prova de que o banco de produção seja idêntico.

Conclusão:

- a migração segura exige comparar schema real da origem usando leitura autorizada e banco de staging, não apenas o `schema.sql` do repositório.

## 5. Riscos encontrados que não puderam ser reproduzidos

Os seguintes riscos permaneceram bloqueados neste ambiente e não puderam ser reproduzidos de forma confiável:

1. Acesso real ao banco de staging e ao banco de origem em produção, com credenciais e privilégios adequados.
2. Validação real de sessão/HTTP em ambiente Railway ou Apache com volumes persistentes.
3. Checagem de persistência e acessibilidade das imagens e comprovantes após redeploy/restart.
4. Teste end-to-end dos fluxos reais de login, pedidos, pagamentos, upload e votação em ambiente isolado.
5. Validação de política de CORS/mesma origem e cookies em produção, especialmente no caso de subdomínios ou proxies.

## 6. Testes executados e resultados reais

### Comandos executados

1. Verificação do estado do Git antes de qualquer edição:

```bash
cd /home/sosoph/PROJETO INTERCURSO/Sistema-Intercurso && git status --short && git --no-pager diff --stat
```

Resultado: o repositório estava limpo e sem alterações pendentes no momento da auditoria.

2. Validação sintática dos arquivos PHP do v2:

```bash
cd /home/sosoph/PROJETO INTERCURSO/Sistema-Intercurso && find backend/v2 -name '*.php' -print0 | xargs -0 -n1 php -l
```

Resultado real:

- `No syntax errors detected in backend/v2/bin/copy-data.php`
- `No syntax errors detected in backend/v2/index.php`
- `No syntax errors detected in backend/v2/src/AdminController.php`
- `No syntax errors detected in backend/v2/src/OrderController.php`
- `No syntax errors detected in backend/v2/src/PaymentController.php`
- `No syntax errors detected in backend/v2/src/VotingController.php`
- `No syntax errors detected in backend/v2/src/AuthController.php`
- `No syntax errors detected in backend/v2/bootstrap.php`

### Testes ainda pendentes / bloqueados

Não foram executados:

- integração HTTP com banco de staging real;
- login ALUNO/ADMIN em ambiente real;
- testes de upload de comprovantes e imagens em volume persistente;
- validação de sessão e autorização admin em staging;
- migração `copy-data.php` em dry-run real;
- operação `--apply` em banco descartável;
- cutover em Railway com roteamento do frontend.

Isso permanece bloqueado porque não há credenciais, banco staging, volumes persistentes, acesso ao Railway ou ambiente de teste configurado neste contexto.

## 7. Estado do schema e da migração

### Schema

O schema do v2 está em `backend/v2/database/schema.sql` e inclui:

- tabelas para `emails_autorizados`, `usuarios`, `configuracoes`, `pedidos`, `opcoes`, `camisas`, `votos`, `votos_realizados`, `comprovantes` e `app_sessions`;
- nomes compatíveis para permitir cópia de dados sem perda de referência;
- proteção contra incompatibilidades por coluna e por destino não vazio.

### Migração

A rotina `backend/v2/bin/copy-data.php` foi implementada com medidas de proteção:

- bloqueia se a origem e o destino forem iguais;
- rejeita destino que já tenha dados;
- compara colunas entre origem e destino;
- valida contagens antes de confirmar;
- nunca apaga registros da origem;
- não copia `app_sessions` nem uploads;
- exige dry-run primeiro e depois `--apply` com autorização explícita.

### Estado real da execução

Nenhuma execução real de schema, seed ou cópia foi feita neste ambiente. Não houve aplicação em banco de produção nem em staging, pois não há credenciais nem banco configurado. A produção permanece sem alterações.

## 8. Etapas de staging, cutover e rollback

### Staging

1. Criar banco novo e isolado, sem reutilizar credenciais do legado.
2. Aplicar `backend/v2/database/schema.sql` apenas neste banco vazio.
3. Configurar `backend/v2/.env` com credenciais do banco novo e do banco origem read-only.
4. Executar `php backend/v2/bin/copy-data.php` em dry-run para checar contagens e compatibilidades.
5. Validar os endpoints via HTTP em staging, usando o frontend existente sobre o mesmo domínio/mesma origem.
6. Confirmar que volumes persistentes de imagens e comprovantes continuam acessíveis após restart.
7. Somente então considerar a migração final e o corte do tráfego.

### Cutover

1. Manter o backend legado intacto.
2. Validar `backend/v2/route-rewrite.htaccess.example` no ambiente real do Apache/host.
3. Ativar o rewrite somente após testes completos em staging.
4. Redirecionar os caminhos do frontend atual para o roteador v2 sem alterar os arquivos do frontend.
5. Testar login, pedidos, pagamentos, upload, votação e administração por HTTP, em um fluxo completo com sessão.

### Rollback

1. Reverter o rewrite do Apache para a versão anterior.
2. Repor a configuração de `DocumentRoot` e regras do backend legado.
3. Voltar a servir os endpoints antigos do frontend.
4. Manter os dados de produção e o banco legado intactos até que o v2 seja validado e aprovado.

## 9. Itens pendentes

- credenciais de staging do banco e do Railway;
- banco de staging disponível e isolado;
- volumes persistentes de upload confirmados;
- execução real do dry-run e do `--apply` em ambiente descartável;
- teste HTTP dos fluxos do frontend atual;
- confirmação de autorização e sessão admin no servidor;
- aprovação do responsável para qualquer cutover real.

## 10. Confirmações finais

- Nenhum segredo foi incluído neste relatório nem em logs.
- O banco de produção permaneceu sem alterações durante a análise.
- O v2 está funcionalmente isolado, sintaticamente válido e documentado, mas não estava validado em staging nem em produção.
- A conclusão correta é que o sistema não está pronto para cutover sem a etapa de staging real, testes HTTP e aprovação do responsável.
