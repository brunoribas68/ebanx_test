# Documentação de Arquitetura

Este documento descreve as decisões técnicas do projeto, o raciocínio por trás de cada escolha e os trade-offs considerados.

---

## Visão geral

```
HTTP Request
     │
     ▼
EventRequest          ← valida e rejeita inputs inválidos antes de chegar ao controller
     │
     ▼
AccountController     ← traduz HTTP para chamadas de serviço; sem lógica de negócio
     │
     ▼
AccountService        ← toda a lógica de negócio; lê e escreve no storage
     │
     ▼
storage/app/
  accounts.json       ← estado persistido entre requisições
```

---

## 1. Separação de responsabilidades

O projeto segue uma divisão em três camadas bem definidas.

**`AccountController`** é responsável exclusivamente pelo protocolo HTTP: ler os parâmetros da requisição, chamar o método correto do serviço e converter o resultado em uma resposta HTTP com o status code apropriado. Não há nenhuma regra de negócio no controller — nem mesmo a verificação de "conta existe?". Se amanhã a API precisar suportar gRPC ou CLI, apenas o controller muda.

**`AccountService`** contém 100% da lógica de negócio. É aqui que se decide o que acontece quando uma conta não existe, como o saldo é atualizado, o que constitui uma transferência válida. O serviço não sabe nada sobre HTTP — recebe tipos PHP primitivos e retorna arrays. Isso o torna testável em isolamento, sem precisar fazer requisições HTTP.

**`EventRequest`** isola a validação de input. O controller nunca recebe dados não validados — se o payload estiver incompleto ou inválido, o `EventRequest` rejeita a requisição antes mesmo de o controller ser chamado.

---

## 2. Persistência via arquivo JSON

### O problema

O spec diz que "durability is NOT a requirement" e que não é necessário usar banco de dados. A leitura natural disso seria usar um array PHP em memória. Esse foi o plano original — e ele passou em todos os testes internos do PHPUnit.

O problema surgiu nos testes reais via HTTP: `php artisan serve` e servidores PHP-FPM tradicionais **reiniciam o processo PHP a cada requisição**. Um array em memória, mesmo registrado como singleton no container do Laravel, existe apenas dentro de um único processo. Quando a requisição termina, o processo termina, e o estado some junto.

### A solução

O estado é persistido em `storage/app/accounts.json` — um arquivo JSON simples. A cada operação de escrita (`deposit`, `withdraw`, `transfer`, `reset`), o serviço lê o arquivo, aplica a mudança em memória e reescreve o arquivo. Leituras (`getBalance`) apenas leem.

```
{ "100": 20, "300": 15 }
```

Esse formato é intencional: chave é o `account_id` (string), valor é o saldo (número). Simples de ler, simples de debugar.

### Por que não usar cache, sessão ou outra alternativa?

| Alternativa | Problema |
|---|---|
| Cache do Laravel (`Cache::put`) | Driver padrão é `file`, funciona, mas adiciona TTL e complexidade desnecessária |
| Session | Vinculado ao cliente (cookie/session ID), não ao estado global da API |
| SQLite | É um banco de dados — o spec pede para evitar banco de dados |
| Redis/Memcached | Requer serviço externo, contradiz a simplicidade pedida |
| Arquivo JSON direto | Simples, sem dependências, sem ambiguidade — escolhido |

O arquivo JSON é honesto: é exatamente o que parece ser. Qualquer desenvolvedor que abra o projeto entende o mecanismo sem precisar de documentação.

### Trade-offs aceitos

O arquivo não tem lock de concorrência — se duas requisições chegarem simultaneamente, pode haver race condition. Para o propósito deste assignment (teste técnico, ambiente single-thread), isso é aceitável. Em produção real, usaríamos um banco de dados com transações ou pelo menos um lock de arquivo.

---

## 3. Transferência atômica

O método `transfer()` em `AccountService` segue este padrão:

```php
$accounts = $this->read();          // 1. lê estado atual

if (!array_key_exists($origin, $accounts)) {
    return null;                    // 2. valida antes de tocar qualquer conta
}

$accounts[$origin]      -= $amount; // 3. atualiza ambas as contas em memória
$accounts[$destination]  = ($accounts[$destination] ?? 0) + $amount;

$this->write($accounts);            // 4. persiste apenas se tudo deu certo
```

A atomicidade aqui significa: ou as duas contas são atualizadas, ou nenhuma é. Não há estado intermediário onde a origem já foi debitada mas o destino ainda não foi creditado — porque ambas as mudanças acontecem no array em memória e só então são escritas em uma única chamada `write()`.

Se a origem não existir, `return null` acontece antes de qualquer modificação. O arquivo não é tocado.

---

## 4. GET sem efeito colateral

`GET /balance` é estritamente de leitura. O método `getBalance()` no serviço:

```php
public function getBalance(string $accountId): int|float|null
{
    $accounts = $this->read();

    return array_key_exists($accountId, $accounts)
        ? $accounts[$accountId]
        : null;
}
```

Retorna `null` para contas inexistentes em vez de criar a conta com saldo zero. Isso garante que uma consulta nunca altera o estado — chamar `GET /balance?account_id=qualquer-coisa` 100 vezes não tem efeito diferente de chamá-la uma vez.

O controller traduz `null` para `404 0` e um número para `200 {saldo}`.

---

## 5. Rotas sem prefixo `/api`

O Laravel 11 monta as rotas de `routes/api.php` por padrão com o prefixo `/api`. O test suite do EBANX espera as rotas em `/reset`, `/balance` e `/event` — sem prefixo.

A solução está em `bootstrap/app.php`:

```php
->withRouting(
    using: function () {
        Route::middleware('api')
            ->group(base_path('routes/api.php'));
    },
)
```

O parâmetro `using` substitui o comportamento padrão de roteamento do Laravel, permitindo montar as rotas da API na raiz sem o prefixo. O middleware `api` é mantido para throttling e stateless session handling.

---

## 6. Validação de input

`EventRequest` define regras condicionais baseadas no `type` do evento:

```php
public function rules(): array
{
    $type = $this->input('type');

    $rules = [
        'type'   => ['required', 'string', 'in:deposit,withdraw,transfer'],
        'amount' => ['required', 'numeric', 'gt:0'],
    ];

    if (in_array($type, ['deposit', 'transfer'], strict: true)) {
        $rules['destination'] = ['required', 'string'];
    }

    if (in_array($type, ['withdraw', 'transfer'], strict: true)) {
        $rules['origin'] = ['required', 'string'];
    }

    return $rules;
}
```

Se a validação falhar, `failedValidation()` lança uma exceção que retorna `422 0` — consistente com o padrão `404 0` usado em todo o resto da API.

Valores de `amount` zero ou negativos são rejeitados pela regra `gt:0` antes de chegar ao serviço.

---

## 7. Estrutura de testes

Os testes são divididos em dois arquivos com responsabilidades distintas.

### `AccountServiceTest` (Unit)

Testa a lógica de negócio em isolamento, sem HTTP. Usa `Storage::fake('local')` para substituir o filesystem real por um fake em memória durante o teste. Cada teste instancia `AccountService` diretamente e chama seus métodos.

O que esses testes cobrem:
- Cada operação individualmente (`deposit`, `withdraw`, `transfer`, `getBalance`, `reset`)
- Efeitos no estado persistido (verifica via `getBalance` depois da operação)
- Casos de falha (conta inexistente)
- Atomicidade da transferência (estado inalterado em caso de falha)
- Ausência de efeitos colaterais em leituras

### `AccountApiTest` (Feature)

Testa a API via HTTP, do ponto de entrada até o storage. Usa o test client do Laravel (`$this->postJson()`, `$this->get()`). Cada rota tem sua própria seção de testes.

O que esses testes cobrem:
- Cada rota individualmente com seus casos de sucesso e erro
- Validação de input (campos ausentes, valores inválidos)
- Estado real persistido (verifica via `GET /balance` depois de operações)
- O fluxo completo do test suite do EBANX, passo a passo

O `setUp()` de ambos os arquivos chama `reset()` antes de cada teste para garantir isolamento — cada teste começa com estado limpo.

---

## 8. Docker

O `Dockerfile` usa `php:8.4-cli-alpine` como imagem base (~50MB, comparado a ~900MB do Ubuntu que o Sail usa por padrão). Instala apenas git, unzip, curl e o Composer. Sem Nginx, sem Supervisor, sem extensões PHP desnecessárias — o servidor embutido do PHP (`php artisan serve`) é suficiente para o propósito do assignment.

O `docker-compose.yml` tem apenas um serviço (`app`). Não há MySQL, Redis ou outros serviços porque o estado é gerenciado pelo arquivo JSON local ao container.

```yaml
services:
  app:
    build: .
    ports:
      - "${APP_PORT:-8000}:8000"
```

A porta é configurável via `APP_PORT` no `.env` — padrão `8000`.
