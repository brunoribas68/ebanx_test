# EBANX – Take-home Assignment

API financeira simples implementada em Laravel 11. Gerencia contas e saldos via três operações: depósito, saque e transferência.

## Stack

| | |
|---|---|
| Linguagem | PHP 8.4 |
| Framework | Laravel 11 |
| Persistência | Arquivo JSON em `storage/app/accounts.json` |
| Testes | PHPUnit via `php artisan test` |
| Docker | `Dockerfile` + `docker-compose.yml` na raiz |

---

## Rodando o projeto

### Com Docker (recomendado)

```bash
# Clonar e entrar no diretório
git clone <repo-url>
cd ebanx-api

# Copiar variáveis de ambiente
cp .env.example .env

# Build e start (a primeira vez baixa a imagem base)
docker compose up --build

# API disponível em http://localhost:8000
```

### Sem Docker

Requisitos: PHP 8.2+, Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
# API disponível em http://localhost:8000
```

---

## Rodando os testes

```bash
php artisan test
```

Saída esperada:

```
PASS  Tests\Unit\AccountServiceTest
Tests\Unit\AccountServiceTest
  ✓ reset clears all existing accounts
  ✓ reset on empty state does not throw
  ✓ get balance returns null for unknown account
  ✓ get balance returns correct value after deposit
  ✓ get balance is read only and does not create the account
  ✓ get balance called twice returns same value
  ✓ deposit creates account when it does not exist
  ✓ deposit persists balance readable via get balance
  ✓ deposit accumulates on existing account
  ✓ deposit does not affect other accounts
  ✓ withdraw returns null for unknown account
  ✓ withdraw reduces balance correctly
  ✓ withdraw persists new balance
  ✓ withdraw failure does not create account
  ✓ withdraw failure does not affect other accounts
  ✓ transfer returns null when origin does not exist
  ✓ transfer deducts from origin
  ✓ transfer adds to destination
  ✓ transfer creates destination if it does not exist
  ✓ transfer returns both updated accounts
  ✓ transfer failure is atomic destination unchanged
  ✓ transfer is atomic origin not modified on failure

PASS  Tests\Feature\AccountApiTest
Tests\Feature\AccountApiTest
  ✓ reset returns 200 ok
  ✓ reset wipes all accounts
  ✓ reset is idempotent when called on empty state
  ✓ balance returns 404 for non existing account
  ✓ balance returns 200 with correct value
  ✓ balance reflects multiple deposits
  ✓ balance does not change state on multiple reads
  ✓ balance does not create account for unknown id
  ✓ deposit creates account with initial balance
  ✓ deposit into existing account accumulates balance
  ✓ deposit does not affect other accounts
  ✓ deposit missing destination returns error
  ✓ deposit with zero amount returns error
  ✓ deposit with negative amount returns error
  ✓ withdraw from non existing account returns 404
  ✓ withdraw from existing account reduces balance
  ✓ withdraw failure does not create account
  ✓ withdraw failure does not affect other accounts
  ✓ withdraw missing origin returns error
  ✓ transfer from non existing account returns 404
  ✓ transfer deducts from origin
  ✓ transfer adds to destination
  ✓ transfer returns both accounts in response
  ✓ transfer creates destination account when it does not exist
  ✓ transfer failure is atomic destination unchanged
  ✓ transfer missing origin returns error
  ✓ transfer missing destination returns error
  ✓ unknown event type returns error
  ✓ full ebanx automated suite sequence
```

---

## Referência da API

### `POST /reset`

Limpa todas as contas. Usado pelo test suite antes de cada rodada.

**Resposta**
```
200 OK
```

---

### `GET /balance?account_id={id}`

Retorna o saldo atual de uma conta. Operação de leitura pura — não altera estado.

**Respostas**

| Status | Body | Condição |
|--------|------|----------|
| `200` | `20` | Conta existe |
| `404` | `0` | Conta não existe |

**Exemplo**
```
GET /balance?account_id=100
→ 200  20
```

---

### `POST /event`

Processa um evento financeiro. O campo `type` determina a operação.

**Corpo da requisição (JSON)**

| Campo | Tipo | Obrigatório em |
|-------|------|----------------|
| `type` | `"deposit"` \| `"withdraw"` \| `"transfer"` | sempre |
| `amount` | número positivo | sempre |
| `destination` | string | `deposit`, `transfer` |
| `origin` | string | `withdraw`, `transfer` |

#### Deposit

Cria a conta se não existir. Acumula saldo se já existir.

```bash
POST /event
{"type":"deposit", "destination":"100", "amount":10}

→ 201
{"destination":{"id":"100","balance":10}}
```

#### Withdraw

```bash
POST /event
{"type":"withdraw", "origin":"100", "amount":5}

→ 201                                       # conta existe
{"origin":{"id":"100","balance":15}}

→ 404  0                                    # conta não existe
```

#### Transfer

Deduz da origem e credita no destino atomicamente. Cria a conta de destino se não existir.

```bash
POST /event
{"type":"transfer", "origin":"100", "amount":15, "destination":"300"}

→ 201                                       # origem existe
{"origin":{"id":"100","balance":0},"destination":{"id":"300","balance":15}}

→ 404  0                                    # origem não existe
```

**Erros de validação**

Campos obrigatórios ausentes, `amount` zero ou negativo, ou `type` desconhecido retornam `422 0`.

---

## Estrutura do projeto

```
app/
├── Http/
│   ├── Controllers/
│   │   └── AccountController.php   # camada HTTP: parseia request, formata response
│   └── Requests/
│       └── EventRequest.php        # validação de input do POST /event
├── Providers/
│   └── AppServiceProvider.php      # registra AccountService no container
└── Services/
    └── AccountService.php          # toda a lógica de negócio

bootstrap/
└── app.php                         # remove o prefixo /api das rotas

routes/
└── api.php                         # definição das rotas

tests/
├── Feature/
│   └── AccountApiTest.php          # testa cada rota HTTP individualmente
└── Unit/
    └── AccountServiceTest.php      # testa lógica de negócio sem HTTP

Dockerfile                          # imagem de produção (php:8.4-cli-alpine)
docker-compose.yml                  # orquestração local
```

---

## Decisões de design

Para a documentação técnica completa, incluindo o raciocínio por trás de cada decisão, ver [ARCHITECTURE.md](./ARCHITECTURE.md).
