# Monitoring for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/monitoring-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/monitoring-for-laravel)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue)](https://www.php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12.0-red)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

**Monitoring for Laravel** é um pacote de monitoramento completo para aplicações Laravel, desenvolvido pela [RiseTechApps](https://github.com/risetechapps). Ele captura automaticamente requisições HTTP, exceções, jobs, comandos, notificações, e-mails, gates e muito mais — com suporte a log manual estruturado, rastreabilidade por batch, exportação de relatórios e política de retenção automática.

---

## Sumário

- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Watchers](#watchers)
- [Loggly — Logger Estruturado](#loggly--logger-estruturado)
- [Trait HasLoggly — Auditoria de Models](#trait-hasloggly--auditoria-de-models)
- [Rastreabilidade por Tags e Batch ID](#rastreabilidade-por-tags-e-batch-id)
- [Exportação de Relatórios](#exportação-de-relatórios)
- [Política de Retenção (90 dias)](#política-de-retenção-90-dias)
- [Comandos Artisan](#comandos-artisan)
- [Rotas HTTP](#rotas-http)
- [Repositório — Consultando os Dados](#repositório--consultando-os-dados)
- [Middleware e Controle Programático](#middleware-e-controle-programático)
- [Estrutura da Tabela](#estrutura-da-tabela)
- [Otimização e Índices](#otimização-e-índices)
- [Troubleshooting](#troubleshooting)

---

## Requisitos

- PHP `^8.3`
- Laravel `^12.0`
- Laravel Sanctum `^4.0`

---

## Instalação

```bash
composer require risetechapps/monitoring-for-laravel
```

O pacote se registra automaticamente via _auto-discovery_. O `ServiceProvider` e o alias `Logs` são configurados sem nenhuma ação adicional.

### Publicar a configuração

```bash
php artisan vendor:publish --tag=config --provider="RiseTechApps\Monitoring\MonitoringServiceProvider"
```

Isso cria o arquivo `config/monitoring.php` na sua aplicação.

### Executar as migrações

```bash
php artisan migrate
```

Cria a tabela `monitoring` e os índices de performance (GIN no PostgreSQL, coluna virtual no MySQL).

---

## Configuração

### Variáveis de ambiente essenciais

```env
MONITORING_ENABLED=true
MONITORING_DRIVER=database
MONITORING_BUFFER_SIZE=5

# Retenção automática (opcional)
MONITORING_RETENTION_AUTO_SCHEDULE=true
MONITORING_RETENTION_DAYS=90
MONITORING_RETENTION_FORMAT=json
MONITORING_RETENTION_DISK=local
MONITORING_RETENTION_TIME=02:00
```

### `config/monitoring.php` completo

```php
return [

    // Liga/desliga o monitoramento globalmente
    'enabled' => env('MONITORING_ENABLED', true),

    // 'database' grava no banco | 'single' grava em arquivo de log
    'driver' => env('MONITORING_DRIVER', 'database'),

    // Entradas acumuladas no buffer antes de persistir
    'buffer_size' => (int) env('MONITORING_BUFFER_SIZE', 5),

    'watchers' => [
        \RiseTechApps\Monitoring\Watchers\RequestWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore_http_methods' => ['options'],
                'ignore_status_codes' => [],
                'ignore_paths'        => ['telescope', 'telescope-api'],
                'size_limit'          => (int) env('MONITORING_RESPONSE_SIZE_LIMIT_KB', 32),
            ],
        ],
        // ... demais watchers
    ],

    'drivers' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'pgsql'),
        ],
        'single' => [
            'path' => storage_path('logs/monitoring.log'),
        ],
    ],

    // Política de retenção de logs
    'retention' => [
        'auto_schedule' => env('MONITORING_RETENTION_AUTO_SCHEDULE', false),
        'days'          => (int) env('MONITORING_RETENTION_DAYS', 90),
        'format'        => env('MONITORING_RETENTION_FORMAT', 'json'),
        'disk'          => env('MONITORING_RETENTION_DISK', 'local'),
        'time'          => env('MONITORING_RETENTION_TIME', '02:00'),
        'chunk_size'    => (int) env('MONITORING_RETENTION_CHUNK', 500),
    ],
];
```

### Referência de variáveis de ambiente

| Variável | Padrão | Descrição |
|---|---|---|
| `MONITORING_ENABLED` | `true` | Liga/desliga o monitoramento |
| `MONITORING_DRIVER` | `database` | `database` ou `single` |
| `MONITORING_BUFFER_SIZE` | `5` | Entradas no buffer antes de persistir |
| `MONITORING_RESPONSE_SIZE_LIMIT_KB` | `32` | Limite do body da resposta capturado |
| `MONITORING_RETENTION_AUTO_SCHEDULE` | `false` | Ativa o agendamento automático da retenção |
| `MONITORING_RETENTION_DAYS` | `90` | Dias de retenção dos logs no banco |
| `MONITORING_RETENTION_FORMAT` | `json` | Formato do backup: `json` ou `csv` |
| `MONITORING_RETENTION_DISK` | `local` | Disco do Storage para os backups |
| `MONITORING_RETENTION_TIME` | `02:00` | Horário da execução diária automática |
| `MONITORING_RETENTION_CHUNK` | `500` | Registros por lote durante a retenção |

---

## Watchers

Os _watchers_ interceptam e registram cada categoria de evento. Todos podem ser habilitados/desabilitados individualmente na configuração.

### RequestWatcher — Requisições HTTP

Monitora todas as requisições HTTP recebidas.

**Dados coletados:** IP, URI, método, controller/action, middlewares, headers, payload, status da resposta, duração (ms), memória (MB).

> **Segurança:** `password`, `password_confirmation`, `authorization` e `php-auth-pw` são automaticamente mascarados.

```php
\RiseTechApps\Monitoring\Watchers\RequestWatcher::class => [
    'enabled' => true,
    'options' => [
        'ignore_http_methods' => ['options'],
        'ignore_status_codes' => [404],
        'ignore_paths'        => ['telescope', 'horizon', 'health'],
        'size_limit'          => 32,  // KB
    ],
],
```

### ExceptionWatcher

Captura exceções registradas via `Log::error()` e similares que recebam um `Throwable`.

**Dados coletados:** classe, arquivo, linha, mensagem, contexto, stack trace (20 frames), preview de código.

### EventWatcher

Registra eventos disparados na aplicação.

```php
\RiseTechApps\Monitoring\Watchers\EventWatcher::class => [
    'enabled' => true,
    'options' => [
        'ignore' => ['App\Events\MeuEventoInterno'],
    ],
],
```

### CommandWatcher

Monitora comandos Artisan. Os comandos internos do framework e do próprio pacote são ignorados automaticamente.

### ScheduleWatcher

Registra execuções do Laravel Scheduler (expressão cron, timezone, saída do comando).

### JobWatcher

Monitora o ciclo de vida de jobs: pendente → processado → falho.

**Dados de falha:** mensagem da exceção, trace, hostname.

### QueueWatcher

Detecta parada e retomada do worker de fila.

### GateWatcher

Monitora verificações de autorização (Gates e Policies).

```php
\RiseTechApps\Monitoring\Watchers\GateWatcher::class => [
    'options' => ['ignore_abilities' => ['viewNova']],
],
```

### NotificationWatcher

Captura notificações enviadas (classe, canal, notifiable, UUID).

### MailWatcher

Registra e-mails enviados (remetente, destinatários, assunto, corpo HTML).

### ClientRequestWatcher

Monitora requisições HTTP feitas **pela** aplicação via `Http::` facade.

```php
\RiseTechApps\Monitoring\Watchers\ClientRequestWatcher::class => [
    'options' => [
        'ignore_hosts' => ['api.interna.com'],
        'size_limit'   => 32,
    ],
],
```

---

## Loggly — Logger Estruturado

O `Loggly` é a API de log manual do pacote. Ele é um **singleton** no container do Laravel, o que garante que todos os logs sejam entregues ao banco independentemente do contexto (HTTP ou console).

### Helpers disponíveis

```php
loggly()          // nível info (padrão)
logglyInfo()
logglyDebug()
logglyWarning()
logglyNotice()
logglyError()
logglyCritical()
logglyAlert()
logglyEmergency()
logglyModel()
```

### API fluente

```php
loggly()
    ->level('error')
    ->performedOn($user)                     // Model Eloquent ou string
    ->exception($exception)                  // Throwable — captura trace automaticamente
    ->withProperties(['order_id' => $id])    // Dados extras estruturados
    ->withContext(['tenant' => 'acme'])      // Contexto adicional
    ->withTags(['pagamento', 'critico'])     // Tags de categorização
    ->withRequest($request->all())
    ->withResponse($response->json())
    ->at(new DateTime())                     // Timestamp personalizado
    ->encrypt()                              // Encripta a mensagem
    ->to('loggly')                           // 'loggly' (banco) ou 'file' (arquivo)
    ->log('Mensagem do log');
```

### Exemplos

```php
// Log simples
logglyInfo()->log('Usuário autenticado.');

// Exceção com contexto
try {
    // ...
} catch (Exception $e) {
    logglyError()
        ->performedOn(PaymentService::class)
        ->exception($e)
        ->withProperties(['order_id' => $orderId])
        ->log('Falha ao processar pagamento.');
}

// Log de modelo
logglyModel()
    ->performedOn($produto)
    ->withContext(['acao' => 'preco_atualizado'])
    ->log('Preço do produto alterado.');

// Salvar em arquivo (canal seguro, sem risco de recursão)
loggly()->to('file')->exception($e)->log('Erro interno.');
```

> **Importante:** O `Loggly` é singleton. Após cada chamada a `log()`, o estado fluente (nível, tags, exceção etc.) é resetado automaticamente para garantir isolamento entre chamadas consecutivas.

---

## Trait `HasLoggly` — Auditoria de Models

Adicione a trait a qualquer Model Eloquent para auditar criação, atualização, exclusão e restauração automaticamente.

```php
use RiseTechApps\Monitoring\Traits\HasLoggly\HasLoggly;

class Produto extends Model
{
    use HasLoggly;
}
```

### Eventos monitorados

| Evento Eloquent | O que é registrado |
|---|---|
| `created` | Atributos do novo registro |
| `updated` | Diff `old`/`new` de cada campo alterado |
| `deleted` | `Deleted record.` ou `Force deleted record.` |
| `restored` | `Restored record.` (apenas com `SoftDeletes`) |

---

## Rastreabilidade por Tags e Batch ID

### Como funciona

Cada entrada registrada pelo pacote carrega um `batch_id` que agrupa **todos os eventos gerados em uma mesma requisição ou job**. Além disso, quando um usuário autenticado dispara a requisição, seu `user_id` é automaticamente adicionado ao JSON de `tags`.

### Busca por tags com expansão de batch

O método `getEventsByTags()` usa uma estratégia em 3 fases para reconstruir o fluxo completo:

1. Localiza os logs cujas `tags` JSON contêm os pares `chave => valor` informados
2. Coleta os `batch_id` únicos desses logs
3. Retorna **todos os logs** que compartilham esses `batch_id` — mesmo os que não têm a tag filtrada

Isso permite, por exemplo, partir de um `user_id` e recuperar não só as requisições daquele usuário, mas também todos os jobs, exceções e gates disparados nas mesmas requisições.

### Via repositório

```php
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

// Todos os logs de um usuário + fluxo completo de cada batch
$events = $repository->getEventsByTags(['user_id' => $userId]);

// Atalho direto para busca por usuário
$events = $repository->getEventsByUserId($userId);

// Tags múltiplas
$events = $repository->getEventsByTags([
    'user_id' => $userId,
    'action'  => 'checkout',
]);
```

### Via HTTP

```http
# Busca por tags (com expansão automática de batch)
POST /monitoring/tags
Content-Type: application/json

{
    "tags": { "user_id": "550e8400-e29b-41d4-a716-446655440000" }
}

# Atalho por usuário
GET /monitoring/user/550e8400-e29b-41d4-a716-446655440000
```

---

## Exportação de Relatórios

Gera arquivos CSV ou JSON com os logs filtrados. O CSV usa BOM UTF-8 e separador `;` para abrir corretamente no Excel e Google Sheets sem configuração adicional.

### Colunas do relatório

| Coluna | Descrição |
|---|---|
| ID | UUID do evento |
| Batch ID | UUID do batch |
| Tipo de Evento | Request HTTP / Exceção / Job / Comando / etc. |
| Status HTTP | Código da resposta (quando aplicável) |
| Método | GET / POST / PUT / DELETE |
| URI / Descrição | Caminho da requisição ou nome do job/comando |
| Usuário (ID) | `user_id` extraído das tags ou do campo `user` |
| Usuário (E-mail) | E-mail do usuário (quando disponível) |
| Tags (JSON) | JSON completo das tags |
| Data/Hora | Timestamp de criação |

### Via HTTP

```http
POST /monitoring/export
Content-Type: application/json

{
    "format":       "csv",
    "type":         "request",
    "user_id":      "550e8400-e29b-41d4-a716-446655440000",
    "batch_id":     "uuid-do-batch",
    "from":         "2025-01-01",
    "to":           "2025-01-31",
    "expand_batch": true
}
```

A resposta é o download direto do arquivo com os headers `Content-Disposition` e `Content-Type` corretos.

### Via Artisan

```bash
# Exportação padrão (todos os logs, CSV, storage local)
php artisan monitoring:export

# Com filtros
php artisan monitoring:export \
  --type=exception \
  --user-id=550e8400-e29b-41d4-a716-446655440000 \
  --from=2025-01-01 \
  --to=2025-01-31 \
  --format=csv \
  --output=s3

# Inclui todos os logs do mesmo batch dos resultados encontrados
php artisan monitoring:export --user-id=uuid --expand-batch

# Imprimir na saída padrão (útil para pipes)
php artisan monitoring:export --stdout
```

Os arquivos são salvos em `storage/app/monitoring/exports/`.

---

## Política de Retenção (90 dias)

Gerencia o ciclo de vida dos logs: exporta os registros antigos para o Storage e, **somente após a confirmação de gravação**, os remove do banco. Em caso de falha na exportação, os dados são preservados.

### Ativação automática

```env
MONITORING_RETENTION_AUTO_SCHEDULE=true
MONITORING_RETENTION_DAYS=90
MONITORING_RETENTION_DISK=s3
```

O agendamento ocorre diariamente no horário configurado em `MONITORING_RETENTION_TIME` (padrão `02:00`).

### Execução manual

```bash
# Execução padrão (90 dias, JSON, disco local, pede confirmação)
php artisan monitoring:retention

# Personalizado
php artisan monitoring:retention --days=60 --format=csv --disk=s3

# Simulação — não altera nada
php artisan monitoring:retention --dry-run

# Sem confirmação interativa (para uso em scripts/CI)
php artisan monitoring:retention --force
```

### Estrutura dos backups

```
storage/app/monitoring/retention/
└── 2025-01-01/
    ├── 20250101_020000_batch1.json
    ├── 20250101_020000_batch2.json
    └── ...
```

---

## Comandos Artisan

| Comando | Descrição |
|---|---|
| `monitoring:diagnose` | Diagnóstico completo do pipeline (config, banco, singleton, buffer) |
| `monitoring:diagnose --write` | Diagnóstico + teste real de gravação no banco |
| `monitoring:retention` | Exporta logs antigos e remove do banco |
| `monitoring:retention --dry-run` | Simulação sem alterações |
| `monitoring:export` | Exporta logs filtrados para CSV ou JSON |
| `monitoring:export --stdout` | Imprime na saída padrão |

### `monitoring:diagnose` — Ferramenta de troubleshooting

Execute este comando sempre que os logs não aparecerem no banco:

```bash
php artisan monitoring:diagnose --write
```

O comando verifica sequencialmente:

1. `monitoring.enabled` está `true`
2. Conexão com o banco de dados estabelecida
3. Tabela `monitoring` existe e é acessível
4. `Loggly` está registrado como **singleton** no container
5. `MonitoringRepositoryInterface` resolve corretamente
6. `Monitoring::isEnabled()` retorna `true`
7. Conteúdo de `storage/logs/monitoring-internal.log` (erros silenciosos)
8. *(com `--write`)* Gravação real + contagem antes/depois

---

## Rotas HTTP

Registre as rotas no `AppServiceProvider` ou `RouteServiceProvider`:

```php
use RiseTechApps\Monitoring\Monitoring;

// Básico (middleware 'api')
Monitoring::routes();

// Com autenticação e rate limit
Monitoring::routes([
    'middleware'  => ['api', 'auth:sanctum'],
    'authorize'   => 'view-monitoring',
    'rate_limit'  => '60,1',
]);
```

### Endpoints disponíveis

| Método | URI | Nome | Descrição |
|---|---|---|---|
| `GET` | `/monitoring` | `monitoring.index` | Lista todos os eventos |
| `GET` | `/monitoring/{id}` | `monitoring.show` | Detalhe + eventos relacionados do batch |
| `GET` | `/monitoring/type/{type}` | `monitoring.type` | Filtra por tipo |
| `POST` | `/monitoring/tags` | `monitoring.tags` | Busca por tags JSON + expansão de batch |
| `GET` | `/monitoring/user/{userId}` | `monitoring.user` | Todos os logs de um usuário + batches |
| `POST` | `/monitoring/export` | `monitoring.export` | Download de relatório CSV ou JSON |

> As rotas do painel usam automaticamente o middleware `monitoring.disable` para não serem monitoradas.

---

## Repositório — Consultando os Dados

### Injeção via interface

```php
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringController extends Controller
{
    public function __construct(
        protected MonitoringRepositoryInterface $monitoring
    ) {}
}
```

### Métodos disponíveis

```php
// Todos os eventos (ordenado por created_at DESC)
$monitoring->getAllEvents();

// Evento por ID (com related_events do mesmo batch)
$monitoring->getEventById('uuid-aqui');

// Filtrar por tipo
$monitoring->getEventsByTypes(EntryType::REQUEST);
$monitoring->getEventsByTypes(EntryType::EXCEPTION);
$monitoring->getEventsByTypes(EntryType::JOB);

// Busca por tags com expansão de batch
$monitoring->getEventsByTags(['user_id' => $userId]);

// Atalho por usuário
$monitoring->getEventsByUserId($userId);

// Todos os eventos de um batch
$monitoring->getByBatch('batch-uuid');

// Por período
$monitoring->getLast24Hours();
$monitoring->getLast7Days();
$monitoring->getLast15Days();
$monitoring->getLast30Days();
$monitoring->getLast60Days();
$monitoring->getLast90Days();
```

### Suporte por driver

| Método | `database` | `single` |
|---|:---:|:---:|
| `getAllEvents()` | ✅ | ❌ |
| `getEventById()` | ✅ | ❌ |
| `getEventsByTypes()` | ✅ | ❌ |
| `getEventsByTags()` | ✅ | ❌ |
| `getEventsByUserId()` | ✅ | ❌ |
| `getByBatch()` | ✅ | ❌ |
| `getLast*Days()` | ✅ | ❌ |

---

## Middleware e Controle Programático

### Desabilitar em rotas específicas

```php
Route::middleware('monitoring.disable')->group(function () {
    Route::get('/health', fn () => response()->json(['ok' => true]));
});
```

### Controle manual

```php
use RiseTechApps\Monitoring\Monitoring;

// Desabilitar
Monitoring::disable();

// Verificar estado
Monitoring::isEnabled();

// Forçar flush imediato do buffer
Monitoring::flushAll();

// Tags globais para todas as entradas
Monitoring::tag(fn ($entry) => ['tenant' => tenant()->id]);

// Ocultar campos sensíveis
Monitoring::$hiddenRequestParameters  = ['cartao_numero', 'cvv'];
Monitoring::$hiddenResponseParameters = ['token_acesso'];
```

---

## Estrutura da Tabela

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID (PK) | Identificador primário |
| `uuid` | UUID (unique) | Identificador único da entrada |
| `batch_id` | UUID | Agrupa eventos da mesma requisição/job |
| `type` | string(20) | Tipo da entrada |
| `content` | JSON | Dados específicos do evento |
| `tags` | JSON | Tags associadas (inclui `user_id` automaticamente) |
| `user` | JSON | Dados do usuário autenticado |
| `device` | JSON | Dados do dispositivo/browser |
| `created_at` | timestamp | — |
| `updated_at` | timestamp | — |

### Tipos de entrada (`type`)

| Constante | Valor |
|---|---|
| `EntryType::REQUEST` | `request` |
| `EntryType::EXCEPTION` | `exception` |
| `EntryType::EVENT` | `event` |
| `EntryType::COMMAND` | `command` |
| `EntryType::SCHEDULED_TASK` | `schedule` |
| `EntryType::JOB` | `job` |
| `EntryType::GATE` | `gate` |
| `EntryType::NOTIFICATION` | `notification` |
| `EntryType::MAIL` | `mail` |
| `EntryType::CLIENT_REQUEST` | `client_request` |
| `EntryType::LOG` | `log` |
| `EntryType::MODEL` | `model` |
| `EntryType::QUERY` | `query` |

---

## Otimização e Índices

A migration `2025_01_01_000001_add_indexes_to_monitorings_table.php` cria automaticamente:

**PostgreSQL:**
- Índice `GIN` na coluna `tags` para consultas JSON eficientes
- Índice de expressão em `tags->>'user_id'` para filtro por usuário

**MySQL / MariaDB:**
- Coluna virtual gerada `tags_user_id` + índice na virtual
- Compatível com MySQL 5.7+ e MariaDB

**Ambos:**
- Índice composto `(type, created_at)` para queries de retenção e filtro por tipo

O `MonitoringQueryService` centraliza toda a lógica de queries em **scopes reutilizáveis**, garantindo que todas as consultas passem pelos índices corretos:

```php
// Acessível diretamente quando necessário
$queryService = app(\RiseTechApps\Monitoring\Services\MonitoringQueryService::class);

// Busca por tags com expansão recursiva de batch
$rows = $queryService->getByTagsWithBatchExpansion(['user_id' => $userId]);

// Builder com filtros combinados (para exportações customizadas)
$query = $queryService->queryForExport([
    'type'    => 'exception',
    'from'    => '2025-01-01',
    'to'      => '2025-01-31',
    'user_id' => $userId,
]);
```

---

## Troubleshooting

### Logs não aparecem no banco

Execute o diagnóstico completo:

```bash
php artisan monitoring:diagnose --write
```

**Causas mais comuns:**

| Sintoma | Causa | Solução |
|---|---|---|
| Nenhum registro inserido | `MONITORING_ENABLED=false` | Definir `MONITORING_ENABLED=true` no `.env` |
| Nenhum registro inserido | Tabela não existe | `php artisan migrate` |
| Nenhum registro inserido | Conexão de banco errada | Verificar `DB_CONNECTION` e `monitoring.drivers.database.connection` |
| Logs apenas em console | `Loggly` não é singleton | Verificar se o `MonitoringServiceProvider` está registrado |
| Erros silenciosos | Exception em `flushBuffer` | Verificar `storage/logs/monitoring-internal.log` |
| Falha em Octane/FrankenPHP | `terminating()` não dispara | A partir da v2.1, `register_shutdown_function` resolve isso automaticamente |

### Verificar erros silenciosos

```bash
cat storage/logs/monitoring-internal.log
```

A partir da v2.1, erros em `flushBuffer()` também aparecem no log padrão do PHP (`error_log()`), visível no `storage/logs/laravel.log` ou no log do servidor web.

### Testar o pipeline manualmente

```php
// Em tinker ou em um controller de teste
logglyInfo()->withTags(['test' => true])->log('Teste manual');
\RiseTechApps\Monitoring\Monitoring::flushAll();

// Verificar se foi inserido
\Illuminate\Support\Facades\DB::table('monitoring')
    ->where('created_at', '>=', now()->subMinutes(1))
    ->get();
```

---

## Canal de Log `monitoring`

O pacote registra automaticamente um canal de log chamado `monitoring`:

```php
use Illuminate\Support\Facades\Log;

Log::channel('monitoring')->info('Mensagem de log.');
// Grava em storage/logs/monitoring.log
```

---

## Facade `Logs`

```php
use Logs; // alias para MonitoringFacade

Logs::disable();
Logs::isEnabled();
Logs::routes(['middleware' => ['api', 'auth:sanctum']]);
```

---

## Changelog

Veja o [CHANGELOG](CHANGELOG.md) para o histórico completo de versões e detalhes de cada correção.

## Contribuindo

Veja o [CONTRIBUTING](CONTRIBUTING.md) para detalhes sobre como contribuir.

## Licença

Este pacote é open-source, licenciado sob a [MIT license](LICENSE.md).

---

Desenvolvido com ❤️ por [RiseTechApps](https://github.com/risetechapps) — [apps@risetech.com.br](mailto:apps@risetech.com.br)
