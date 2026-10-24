# Sistema de Intercursos

## Backend v2 em paralelo

O novo backend PHP enxuto está sendo preparado isoladamente em
`backend/v2/`; o frontend e os endpoints atualmente ativos continuam sem
alterações nesta etapa. O procedimento de schema, cópia explícita de dados e
cutover gradual está em [`backend/v2/README.md`](backend/v2/README.md). Não
aponte o tráfego nem execute a cópia até concluir os testes em um banco Railway
separado.

## Banco de dados

Para uma instalação nova, execute `backend/docs/banco_completo.sql` no banco
`sistema_intercurso`. Em uma instalação existente que precise usar a API de
pagamentos isolada em `backend/pagamento_backend/api`, confira primeiro as
colunas reais do banco e avalie `backend/pagamento_backend/sql/schema.sql` como
uma migração manual. Não aplique essa migração automaticamente em produção.

Configure `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` no ambiente do PHP. O
valor padrão de `DB_NAME` é `sistema_intercurso`. Para o XAMPP local, os
padrões são `localhost`, `sistema_intercurso`, usuário `root` e senha vazia;
se o banco existente usar outros dados, configure-os em `backend/.env` (arquivo
ignorado pelo Git).

## Executar localmente com XAMPP

Inicie Apache e MySQL pelo painel do XAMPP. Como o projeto pode estar fora do
`DocumentRoot`, crie um link para a pasta `htdocs` se ainda não existir:

```sh
ln -s "$HOME/Sistema-Intercurso" /Applications/XAMPP/xamppfiles/htdocs/Sistema-Intercurso
```

Abra <http://localhost/Sistema-Intercurso/front-end/login.html>. A conexão usa
o banco local já configurado; não importe os arquivos SQL nem recrie tabelas
quando estiver usando uma base existente.

## Comprovantes de pagamento no Railway

O fluxo usado pelo painel principal continua em `backend/pagamentos/`. Os
comprovantes novos usam `PAYMENT_UPLOAD_DIR` se essa variável estiver
configurada; sem ela, o fluxo legado salva em `backend/pagamentos/uploads/` e
a API isolada salva em `backend/pagamento_backend/storage/comprovantes/`.

No Railway, anexe um volume persistente ao serviço PHP e configure
`PAYMENT_UPLOAD_DIR` com o caminho montado (por exemplo, `/data/comprovantes`).
O processo PHP precisa ter permissão de escrita nesse caminho. Use o mesmo
volume para todas as réplicas da aplicação. Os comprovantes antigos permanecem
nos locais atuais; configurar o volume não os move nem os apaga.

Configure `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` para apontar ao banco já
utilizado pela aplicação. A chave PIX e o valor exibidos no frontend são
informações estáticas; confirme-os com a organização antes de aceitar
pagamentos. O sistema registra e analisa comprovantes, mas não integra um
gateway nem confirma transferências automaticamente.

## Executar localmente com Docker

Com Docker e o plugin Docker Compose instalados, na raiz do projeto execute:

```sh
docker compose up --build
```

Na primeira execução, o Compose cria o banco, carrega
`backend/docs/banco_completo.sql` e adiciona contas de teste. Acesse
<http://localhost:8080/front-end/login.html>. Para entrar pela primeira vez,
use `admin@local.test`, `aluno@local.test` ou `lider@local.test` e escolha uma
senha; a conta é criada nesse primeiro login. As senhas ficam guardadas no
banco local persistente depois disso.

Para acompanhar a aplicação:

```sh
docker compose logs -f app db
```

Para parar os serviços sem apagar os dados:

```sh
docker compose down
```

O banco e os uploads são mantidos em volumes do Docker. Para recriar o ambiente
de teste apagando também os dados locais:

```sh
docker compose down --volumes
docker compose up --build
```

Use somente as contas e senhas de exemplo no ambiente local, nunca em produção.
