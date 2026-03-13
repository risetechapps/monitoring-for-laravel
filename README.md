# Monitoring for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/risetechapps/monitoring-for-laravel.svg?style=flat-square)](https://packagist.org/packages/risetechapps/monitoring-for-laravel)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue)](https://www.php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12.0-red)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

**Monitoring for Laravel** é um pacote de monitoramento completo para aplicações Laravel, desenvolvido pela [RiseTechApps](https://github.com/risetechapps). Ele captura automaticamente eventos da aplicação — requisições HTTP, exceções, jobs, comandos, notificações, e-mails, gates de autorização, eventos e muito mais — com suporte a múltiplos drivers de armazenamento.

---

## Requisitos

- PHP `^8.3`
- Laravel `^12.0`
- Laravel Sanctum `^4.0`

---

## Instalação

Instale o pacote via Composer:

```bash
composer require risetechapps/monitoring-for-laravel
```

O pacote se registra automaticamente via _auto-discovery_ do Laravel. O `ServiceProvider` e o alias `Logs` são configurados automaticamente.

### Publicar a configuração

```bash
php artisan vendor:publish --tag=config --provider="RiseTechApps\Monitoring\MonitoringServiceProvider"
```

Isso criará o arquivo `config/monitoring.php` na sua aplicação.

### Executar as migrações (drivers MySQL / PostgreSQL)

```bash
php artisan migrate
```

---

## Configuração

O arquivo `config/monitoring.php` centraliza todas as opções do pacote.

### Opções principais

```php
return [
    // Habilita ou desabilita o monitoramento globalmente
    'enabled' => env('MONITORING_ENABLED', true),

    // Driver de armazenamento: 'single' ou 'database'
    'driver' => env('MONITORING_DRIVER', 'single'),

    // Quantidade de entradas acumuladas antes de persistir (buffer)
    'buffer_size' => (int) env('MONITORING_BUFFER_SIZE', 5),

    'watchers' => [ ... ],

    'drivers' => [ ... ],
];
```

### Variáveis de ambiente

| Variável | Padrão | Descrição |
|---|---|---|
| `MONITORING_ENABLED` | `true` | Liga/desliga o monitoramento |
| `MONITORING_DRIVER` | `single` | Driver de armazenamento |

---

## Drivers de Armazenamento

O pacote suporta quatro drivers, configuráveis via `MONITORING_DRIVER`.

### `single` — Arquivo de Log

Persiste os eventos em um arquivo local (`storage/logs/monitoring.log`). Ideal para desenvolvimento ou ambientes sem banco de dados.

```env
MONITORING_DRIVER=single
```

### `mysql` — MySQL

Armazena os eventos na tabela `monitoring` de uma conexão MySQL.

```env
MONITORING_DRIVER=mysql
DB_CONNECTION=mysql
```

```php
// config/monitoring.php
'mysql' => [
    'connection' => env('DB_CONNECTION', 'mysql'),
],
```

### `pgsql` — PostgreSQL

Idêntico ao driver MySQL, mas para conexões PostgreSQL.

```env
MONITORING_DRIVER=pgsql
DB_CONNECTION=pgsql
```

```php
'pgsql' => [
    'connection' => env('DB_CONNECTION', 'pgsql'),
],
```

---

## Watchers

Os _watchers_ são os componentes responsáveis por interceptar e registrar cada categoria de evento. Todos podem ser habilitados/desabilitados individualmente no arquivo de configuração.

### RequestWatcher — Requisições HTTP

Monitora todas as requisições HTTP recebidas pela aplicação.

**Dados coletados:** IP do cliente, URI, método HTTP, controller/action, middlewares, headers, payload, sessão, resposta, duração (ms) e uso de memória (MB).

**Opções de filtro:**

```php
\RiseTechApps\Monitoring\Watchers\RequestWatcher::class => [
    'enabled' => true,
    'options' => [
        'ignore_http_methods' => ['options'],   // Ignora métodos HTTP específicos
        'ignore_status_codes' => [404],         // Ignora códigos de status HTTP
        'ignore_paths' => ['telescope', 'horizon'], // Ignora paths específicos
        'size_limit' => 64,                     // Limite de tamanho da resposta em KB
    ],
],
```

> **Segurança:** Os campos `password`, `password_confirmation`, `authorization` e `php-auth-pw` são automaticamente mascarados como `********`.

---

### ExceptionWatcher — Exceções

Captura exceções registradas via `Log::error()`, `Log::critical()` e similares que passem uma instância de `Throwable` no contexto.

**Dados coletados:** classe da exceção, arquivo, linha, mensagem, contexto adicional, stack trace e preview de código (linhas ao redor da exceção).

---

### EventWatcher — Eventos

Registra os eventos disparados na aplicação, excluindo eventos internos do framework por padrão.

**Dados coletados:** nome do evento, payload formatado, lista de listeners e indicação se o evento é broadcast.

**Opções:**

```php
\RiseTechApps\Monitoring\Watchers\EventWatcher::class => [
    'enabled' => true,
    'options' => [
        'ignore' => [
            'App\Events\MeuEventoInterno',
        ],
    ],
],
```

---

### CommandWatcher — Artisan Commands

Monitora comandos Artisan executados.

**Dados coletados:** nome do comando, código de saída (`exit_code`), argumentos e opções.

> Os comandos `schedule:run`, `schedule:finish` e `package:discover` são ignorados automaticamente.

**Opções:**

```php
\RiseTechApps\Monitoring\Watchers\CommandWatcher::class => [
    'enabled' => true,
    'options' => [
        'ignore' => ['meu:comando-interno'],
    ],
],
```

---

### ScheduleWatcher — Tarefas Agendadas

Registra a execução das tarefas agendadas do Laravel Scheduler.

**Dados coletados:** comando ou closure, descrição, expressão cron, timezone, usuário e saída do comando.

---

### JobWatcher — Jobs de Fila

Monitora o ciclo de vida completo de jobs, incluindo pendente, processado e falho.

**Dados coletados:**
- **Pendente:** nome, conexão, fila, tentativas, timeout, payload, `batch_id`
- **Processado:** status, nome do job, tentativas, hostname
- **Falho:** status, nome do job, mensagem de exceção, trace, preview de código, hostname

---

### QueueWatcher — Worker de Fila

Detecta parada e retomada do worker de fila. Cria um arquivo `storage/framework/queue_stopping` ao parar e o remove ao retomar.

---

### GateWatcher — Autorização

Monitora todas as verificações de autorização (Gates e Policies).

**Dados coletados:** ability verificada, resultado (`allowed`/`denied`), argumentos e arquivo/linha de origem da chamada.

**Opções:**

```php
\RiseTechApps\Monitoring\Watchers\GateWatcher::class => [
    'enabled' => true,
    'options' => [
        'ignore_abilities' => ['viewNova'],
    ],
],
```

---

### NotificationWatcher — Notificações

Captura notificações enviadas pela aplicação.

**Dados coletados:** classe da notificação, se é queued, notifiable, canal, resposta e UUID da notificação.

---

### MailWatcher — E-mails

Registra todos os e-mails enviados.

**Dados coletados:** classe mailable, se é queued, remetente, reply-to, destinatários (to, cc, bcc), assunto, corpo HTML e e-mail bruto.

---

### ClientRequestWatcher — HTTP Client (Saída)

Monitora requisições HTTP feitas _pela_ aplicação usando o `Http::` facade do Laravel.

**Dados coletados:** método, URI, headers, payload, status da resposta, headers da resposta, corpo da resposta e duração (ms).

> Requisições para os hosts da própria plataforma de monitoramento são ignoradas automaticamente para evitar loops.

**Opções:**

```php
\RiseTechApps\Monitoring\Watchers\ClientRequestWatcher::class => [
    'enabled' => true,
    'options' => [
        'ignore_hosts' => ['api.interna.com'],
        'size_limit' => 64,
    ],
],
```

---

## Loggly — Logger Estruturado

O `Loggly` é o componente de log manual do pacote, acessível via função helper global. Ele oferece uma API fluente para registrar logs ricos e estruturados.

### Helpers disponíveis

```php
loggly()          // Info (padrão)
logglyInfo()      // Nível INFO
logglyDebug()     // Nível DEBUG
logglyWarning()   // Nível WARNING
logglyNotice()    // Nível NOTICE
logglyError()     // Nível ERROR
logglyCritical()  // Nível CRITICAL
logglyAlert()     // Nível ALERT
logglyEmergency() // Nível EMERGENCY
logglyModel()     // Nível MODEL
```

### API fluente

```php
loggly()
    ->level('error')                        // Define o nível
    ->performedOn($user)                    // Modelo ou string do contexto
    ->exception($exception)                 // Captura dados da exceção
    ->withProperties(['key' => 'value'])    // Propriedades extras
    ->withContext(['request_id' => '123'])  // Contexto adicional
    ->withTags(['pagamento', 'critico'])    // Tags para categorização
    ->withRequest($request->all())          // Dados da requisição
    ->withResponse($response->json())       // Dados da resposta
    ->at(new DateTime())                    // Timestamp personalizado
    ->encrypt()                             // Encripta a mensagem
    ->to('loggly')                          // Destino: 'loggly' ou 'file'
    ->log('Mensagem do log');
```

### Exemplos de uso

**Log simples:**
```php
logglyInfo()->log('Usuário autenticado com sucesso.');
```

**Log de exceção com contexto:**
```php
try {
    // ...
} catch (Exception $e) {
    logglyError()
        ->performedOn(PaymentService::class)
        ->exception($e)
        ->withProperties(['order_id' => $orderId])
        ->log('Falha ao processar pagamento.');
}
```

**Log de modelo:**
```php
logglyModel()
    ->performedOn($produto)
    ->withContext(['acao' => 'preco_atualizado'])
    ->log('Preço do produto foi alterado.');
```

**Salvar em arquivo (sem enviar para o driver principal):**
```php
loggly()->to('file')->log('Debug local.');
```

---

## Trait `HasLoggly` — Auditoria de Models

Adicione a trait `HasLoggly` a qualquer Model Eloquent para registrar automaticamente as operações de criação, atualização, exclusão e restauração.

```php
use RiseTechApps\Monitoring\Traits\HasLoggly\HasLoggly;

class Produto extends Model
{
    use HasLoggly;
}
```

### Eventos monitorados

| Evento Eloquent | Ação registrada |
|---|---|
| `created` | `Created record.` |
| `updated` | `Updated record.` (com diff dos campos alterados) |
| `deleted` | `Deleted record.` ou `Force deleted record.` |
| `restored` | `Restored record.` (apenas com SoftDeletes) |

### Dados coletados

- **updated:** classe do model, valores antigos, valores novos e um diff com `old`/`new` para cada campo alterado.
- **created/deleted/restored:** classe do model e atributos atuais do model.

> Se o Model usar `SoftDeletes`, o evento `restored` é monitorado automaticamente.

---

## Middleware `monitoring.disable`

Use o middleware para desabilitar o monitoramento em rotas específicas, como painéis administrativos ou endpoints de health check.

### Registrando nas rotas

```php
// routes/web.php ou routes/api.php

Route::middleware('monitoring.disable')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::get('/admin/dashboard', [AdminController::class, 'index']);
});
```

### Aplicando em um controller

```php
public function __construct()
{
    $this->middleware('monitoring.disable');
}
```

---

## Controle Programático

### Desabilitar o monitoramento

```php
use RiseTechApps\Monitoring\Monitoring;

Monitoring::disable();
```

### Verificar se está habilitado

```php
if (Monitoring::isEnabled()) {
    // ...
}
```

### Adicionar tags globais a todas as entradas

```php
// Em um ServiceProvider
Monitoring::tag(function ($entry) {
    return ['ambiente:' . app()->environment()];
});
```

### Ocultar parâmetros sensíveis

```php
// Em um ServiceProvider
Monitoring::$hiddenRequestParameters = ['cartao_numero', 'cvv'];
Monitoring::$hiddenResponseParameters = ['token_acesso', 'secret'];
```

### Registrar rotas do pacote

```php
Monitoring::routes();
```

---

## Estrutura da Tabela (`monitoring`)

Quando usando os drivers `mysql` ou `pgsql`, os eventos são armazenados na tabela `monitoring`:

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID (PK) | Identificador primário |
| `uuid` | UUID (unique) | Identificador único da entrada |
| `batch_id` | UUID | Agrupa entradas de uma mesma requisição/job |
| `type` | string(20) | Tipo da entrada (veja abaixo) |
| `content` | JSON | Dados específicos do evento |
| `tags` | JSON | Tags associadas à entrada |
| `user` | JSON | Dados do usuário autenticado |
| `device` | JSON | Dados do dispositivo/browser |
| `created_at` | timestamp | — |
| `updated_at` | timestamp | — |

### Tipos de entrada (`type`)

| Constante | Valor | Descrição |
|---|---|---|
| `EntryType::REQUEST` | `request` | Requisição HTTP recebida |
| `EntryType::EXCEPTION` | `exception` | Exceção capturada |
| `EntryType::EVENT` | `event` | Evento da aplicação |
| `EntryType::COMMAND` | `command` | Comando Artisan |
| `EntryType::SCHEDULED_TASK` | `schedule` | Tarefa agendada |
| `EntryType::JOB` | `job` | Job de fila |
| `EntryType::GATE` | `gate` | Verificação de autorização |
| `EntryType::NOTIFICATION` | `notification` | Notificação enviada |
| `EntryType::MAIL` | `mail` | E-mail enviado |
| `EntryType::CLIENT_REQUEST` | `client_request` | Requisição HTTP de saída |
| `EntryType::LOG` | `log` | Log manual via Loggly |
| `EntryType::MODEL` | `model` | Operação em Model Eloquent |
| `EntryType::QUERY` | `query` | Query no banco de dados |

---

## Buffer de Entradas

O pacote utiliza um buffer em memória para otimizar a performance, evitando uma escrita no storage para cada evento individualmente.

- As entradas são acumuladas no buffer até atingir `buffer_size` (padrão: `5`).
- O buffer é descarregado automaticamente ao final de cada requisição HTTP (evento `RequestHandled`).
- Em ambientes de console (jobs, commands), o buffer é descarregado imediatamente após cada entrada.

Configure o tamanho do buffer:

```env
MONITORING_BUFFER_SIZE=10
```

---

## Consultando os Registros de Monitoramento

O pacote fornece um repositório com métodos padronizados para leitura dos eventos, disponíveis para os drivers `dabase`. O driver `single` (arquivo) **não suporta leitura** — seus métodos retornam coleções vazias.

### Injetando o Repositório

Você pode injetar o `MonitoringRepositoryInterface` em qualquer controller, service ou job:

```php
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringDashboardController extends Controller
{
    public function __construct(
        protected MonitoringRepositoryInterface $monitoring
    ) {}
}
```

Ou resolver diretamente via container:

```php
$monitoring = app(MonitoringRepositoryInterface::class);
```

---

### Métodos de Consulta

Todos os métodos retornam uma `Illuminate\Support\Collection`.

#### Todos os eventos

```php
$events = $monitoring->getAllEvents();
// Retorna todos os registros ordenados por created_at DESC
```

#### Evento por ID

Retorna o evento específico **e todos os eventos relacionados do mesmo `batch_id`** no campo `related_events`.

```php
$event = $monitoring->getEventById('uuid-ou-id-aqui');

// Estrutura de retorno:
// [
//   'id'             => '...',
//   'uuid'           => '...',
//   'batch_id'       => '...',
//   'type'           => 'request',
//   'content'        => [...],
//   'tags'           => [...],
//   'user'           => [...],
//   'device'         => [...],
//   'created_at'     => '...',
//   'updated_at'     => '...',
//   'related_events' => [...],  // outros eventos do mesmo batch
// ]
```

#### Eventos por tipo

Filtra por um dos tipos definidos em `EntryType`. Retorna cada evento com seu `related_events`.

```php
use RiseTechApps\Monitoring\Entry\EntryType;

$requests    = $monitoring->getEventsByTypes(EntryType::REQUEST);
$exceptions  = $monitoring->getEventsByTypes(EntryType::EXCEPTION);
$jobs        = $monitoring->getEventsByTypes(EntryType::JOB);
$commands    = $monitoring->getEventsByTypes(EntryType::COMMAND);
$mails       = $monitoring->getEventsByTypes(EntryType::MAIL);
$logs        = $monitoring->getEventsByTypes(EntryType::LOG);
$models      = $monitoring->getEventsByTypes(EntryType::MODEL);
$gates       = $monitoring->getEventsByTypes(EntryType::GATE);
$events      = $monitoring->getEventsByTypes(EntryType::EVENT);
$schedules   = $monitoring->getEventsByTypes(EntryType::SCHEDULED_TASK);
$notifs      = $monitoring->getEventsByTypes(EntryType::NOTIFICATION);
$outbound    = $monitoring->getEventsByTypes(EntryType::CLIENT_REQUEST);
```

#### Eventos por batch

Retorna todos os eventos que pertencem a um mesmo ciclo de requisição ou job, agrupados pelo `batch_id`.

```php
$batchEvents = $monitoring->getByBatch('batch-uuid-aqui');
```

#### Lista de tipos disponíveis

```php
$types = $monitoring->getEventsByTags();
// Retorna: ['command', 'event', 'exception', 'job', 'log', 'mail',
//           'model', 'notification', 'query', 'request',
//           'schedule', 'client_request', 'gate']
```

---

### Consulta por Período

Filtra eventos pelos períodos pré-definidos:

```php
$monitoring->getLast24Hours();  // Últimas 24 horas
$monitoring->getLast7Days();    // Últimos 7 dias
$monitoring->getLast15Days();   // Últimos 15 dias
$monitoring->getLast30Days();   // Últimos 30 dias
$monitoring->getLast60Days();   // Últimos 60 dias
$monitoring->getLast90Days();   // Últimos 90 dias
```

---

### Suporte por Driver

| Método | `database` | `single` |
|---|:----------:|:--------:|
| `getAllEvents()` |     ✅      | ❌ |
| `getEventById()` |     ✅      | ❌ |
| `getEventsByTypes()` |     ✅      | ❌ |
| `getEventsByTags()` |     ✅      | ✅* |
| `getByBatch()` |     ✅      | ❌ |
| `getLast24Hours()` |     ✅      | ❌ |
| `getLast7Days()` |     ✅      | ❌ |
| `getLast15Days()` |     ✅      | ❌ |
| `getLast30Days()` |     ✅      | ❌ |
| `getLast60Days()` |     ✅      | ❌ |
| `getLast90Days()` |     ✅      | ❌ |

> *`getEventsByTags()` no driver `single` retorna apenas a lista de tipos disponíveis.

---

### Rotas HTTP de Consulta

O pacote disponibiliza rotas prontas para expor os eventos via API. Registre-as no `AppServiceProvider` ou `RouteServiceProvider`:

```php
use RiseTechApps\Monitoring\Monitoring;

// Registro básico (usa middleware 'api' por padrão)
Monitoring::routes();
```

**Opções disponíveis:**

```php
Monitoring::routes([
    'middleware'  => ['api', 'auth:sanctum'],  // middlewares customizados
    'authorize'   => 'view-monitoring',         // Gate/Policy de autorização
    'rate_limit'  => '60,1',                    // throttle: 60 req/min
    'as'          => 'monitoring.',             // prefixo dos nomes das rotas
]);
```

**Endpoints registrados:**

| Método | URI | Nome | Descrição |
|---|---|---|---|
| `GET` | `/monitoring` | `monitoring.index` | Lista todos os eventos |
| `GET` | `/monitoring/{id}` | `monitoring.show` | Detalhe de um evento + relacionados |
| `GET` | `/monitoring/type/{type}` | `monitoring.type` | Filtra por tipo |
| `POST` | `/monitoring/tags` | `monitoring.tags` | Lista tipos disponíveis |

> As rotas do painel de monitoramento usam automaticamente o middleware `monitoring.disable` para não serem monitoradas.

**Exemplos de requisição:**

```bash
# Todos os eventos
GET /monitoring

# Evento específico com relacionados
GET /monitoring/018e4b2a-1234-7000-abcd-ef0123456789

# Filtrar por tipo
GET /monitoring/type/exception
GET /monitoring/type/request
GET /monitoring/type/job

# Listar tipos disponíveis
POST /monitoring/tags
```

---

### Exemplo Completo: Dashboard de Monitoramento

```php
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Entry\EntryType;

class MonitoringDashboardController extends Controller
{
    public function __construct(
        protected MonitoringRepositoryInterface $monitoring
    ) {}

    public function index()
    {
        return response()->json([
            'resumo' => [
                'requisicoes'  => $this->monitoring->getEventsByTypes(EntryType::REQUEST)->count(),
                'excecoes'     => $this->monitoring->getEventsByTypes(EntryType::EXCEPTION)->count(),
                'jobs_falhos'  => $this->monitoring->getEventsByTypes(EntryType::JOB)
                                    ->where('content.status', 'failed')->count(),
            ],
            'ultimas_24h'  => $this->monitoring->getLast24Hours(),
            'ultimos_7d'   => $this->monitoring->getLast7Days(),
        ]);
    }

    public function show(string $id)
    {
        return response()->json(
            $this->monitoring->getEventById($id)
        );
    }

    public function byBatch(string $batchId)
    {
        // Útil para ver tudo que aconteceu em uma requisição específica:
        // request + events + jobs + gates disparados juntos
        return response()->json(
            $this->monitoring->getByBatch($batchId)
        );
    }
}
```

---

## Canal de Log `monitoring`

O pacote registra automaticamente um canal de log chamado `monitoring` no Laravel. Você pode usá-lo diretamente com a facade `Log`:

```php
use Illuminate\Support\Facades\Log;

Log::channel('monitoring')->info('Mensagem de log.');
```

Os logs são gravados em `storage/logs/monitoring.log`.

---

## Facade `Logs`

O pacote registra o alias `Logs` para a facade `MonitoringFacade`:

```php
use Logs; // alias para MonitoringFacade

Logs::disable();
Logs::isEnabled();
```

---

## Changelog

Veja o [CHANGELOG](CHANGELOG.md) para o histórico completo de versões.

## Contribuindo

Veja o [CONTRIBUTING](CONTRIBUTING.md) para detalhes sobre como contribuir com o projeto.

## Licença

Este pacote é open-source, licenciado sob a [MIT license](LICENSE.md).

---

Desenvolvido com ❤️ por [RiseTechApps](https://github.com/risetechapps) — [apps@risetech.com.br](mailto:apps@risetech.com.br)
