# Sistema de Intercursos

## Banco de dados

Para uma instalação nova, execute `backend/docs/banco_completo.sql` no banco
`sistema_intercurso`. Em uma instalação existente que use a API de pagamentos
isolada em `backend/pagamento_backend/api`, execute também
`backend/pagamento_backend/sql/schema.sql` uma vez após o esquema principal.
Essa migração é reexecutável e acrescenta os campos de compatibilidade para os
comprovantes sem remover os dados antigos.

Configure `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` no ambiente do PHP. O
valor padrão de `DB_NAME` é `sistema_intercurso`.

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
