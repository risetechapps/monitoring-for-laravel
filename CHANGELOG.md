# Changelog — Monitoring for Laravel

Todas as mudanças notáveis deste projeto estão documentadas neste arquivo.
O formato segue o padrão [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).

---

## [5.0.1] - 2026-10-06

### 🐛 Corrigido
- **Log derrubava a requisição de upload (500)**: `Loggly::withRequest()` descrevia o arquivo enviado com `getSize()`, que faz `stat` no temporário do PHP. Como o log costuma vir depois de o arquivo ser movido para o storage (ex.: upload de mídia), lançava `RuntimeException` ("stat failed") — e o catch que logava o erro com `withRequest()` estourava de novo. Agora o tamanho fica `null` quando o temporário não existe e `withRequest()` nunca lança.

## [5.0.0] - 2026-10-06

### 🧾 Listagem paginada
- `GET /monitoring` agora pagina de verdade e responde no formato da tabela do painel (`data`, `recordsTotal`, `current_page`...). Antes devolvia só os itens da página, sem total, e o `search` era ignorado quando vinha junto com outro filtro. Aceita `type`, `from`/`to`, `unresolved`, `search` (sem `from`, nos últimos 30 dias), `tenant_id`, `sort_column`/`sort_direction` (lista fechada: `created_at`, `type`) e `page`/`pagesize` (teto 200). **Breaking** no formato da resposta (nenhum consumidor registrado).
- Novo `MonitoringRepositoryInterface::paginateEvents()` e `MonitoringQueryService::paginate()` / `scopeContentSearch()`.

### 🐛 Corrigido
- `GET /monitoring/{id}`, `/type/{type}`, `/search`, `POST /tags`, `/user/{id}` e `/exceptions/unresolved` respondiam **500**: passavam `Collection` ao `jsonSuccess`, que no risetools só aceita `array|JsonResource|null`.
- ⚠️ Pendente: `/exceptions/unresolved` usa `JSON_UNQUOTE/JSON_EXTRACT` (só MySQL) e falha em PostgreSQL/SQLite.

### 🔒 Segurança
- **Senha digitada ia em texto puro para o banco**: `Loggly::withRequest($request)` fazia `(array) $request`, que despeja as propriedades internas do objeto — inclusive o corpo cru (`content`). O AuthFlow loga "Invalid email or password" (e conta bloqueada/não verificada, onde a senha é a correta) com `withRequest`. Agora grava só método, URI e input.
- **Ocultação central (`Support\Redactor`)** aplicada em `IncomingEntry::toArray()` — toda entrada passa por ali. Antes o `RequestWatcher` escondia só `password`/`password_confirmation` (gravava `old_password`, `recovery_code`, `token`), a lista de resposta vinha vazia (gravava o `access_token` de todo login) e o `ClientRequestWatcher` não ocultava nada do HTTP de saída (OAuth `client_secret`/`refresh_token`/`access_token`). Padrões configuráveis em `monitoring.redact`; URLs com `?token=`/`?signature=` também.
- `MailWatcher` não grava mais o corpo por padrão (`record_html`): e-mail de redefinição/primeiro acesso carrega link com token e código em texto livre.
- **Rotas autenticadas por padrão**: `Monitoring::routes()` sem opções usava só o middleware `api`. Agora `['api', 'auth:sanctum']`. **Breaking** para quem dependia das rotas abertas.
- Novo comando `monitoring:redact` para ocultar o que já foi gravado (`--dry-run`, `--days`, `--force`; irreversível).

### ⚡ Performance
- **GeoIP fora do caminho do request**: o device de cada entrada chamava o ip-api (HTTP, até 6s) na primeira entrada do request — antes da resposta ir ao cliente. Agora `monitoring.device.geo_ip` (padrão `false`). Requer `risetools` >= 3.1 (com versões antigas o argumento é ignorado e o geo continua).
- **Métricas de performance atômicas no Redis**: cada request fazia `get` + `put` de um array de até 500 amostras (ler-modificar-gravar, perdendo requisições sob concorrência). Agora um script Lua incrementa um hash da janela (uma ida ao Redis, com TTL); percentis por histograma. Stores não-Redis mantêm o caminho antigo.
- **Métricas e geo fora do prefixo de tenant**: com tenancy, o Cache facade separava a chave por tenant/filial/usuário e o `/health` lia uma janela quase vazia. O store é resolvido direto da config (`monitoring.cache_store`).
- **Busca por tags pelo índice GIN**: `tags->>? = ?` (chave como parâmetro) não casava com índice nenhum. Agora contenção jsonb (`tags::jsonb @> ...`), aceitando valor texto ou numérico.
- Migration remove `monitoring_tags_user_id_idx` e `monitoring_tags_trgm_idx` (sem uso; custavam escrita em todo INSERT).
- **Nenhum INSERT no caminho do request HTTP**: o buffer só vai para o banco no `terminating()`, depois da resposta, em INSERTs multi-linha (`insert_chunk_size`, 200). Antes o flush por tamanho (buffer 5) rodava durante o request — num request do FinanceCore com ~450 eventos eram 89 INSERTs, ~540ms de 623ms de banco. Teto de memória: `http_max_buffer` (1000).
- `buffer_size` padrão 5 → 20 (agora só vale para console/worker).
- Teto de memória do HTTP também em bytes: `http_max_buffer_mb` (8).

### 🏷️ Tags globais
- Novo `Monitoring::registerTagResolver(Closure)`: registra uma função de tags avaliada a cada entrada (com o contexto daquele instante). `Monitoring::tag()` fica obsoleto e deixou de fazer `new static(...)` — o construtor religava o monitoring desligado e quebrava (propriedade estática não inicializada) onde o monitoring não sobe.
- **Driver `single` quebrado**: `MonitoringRepositorySingle` não implementava `getTimelineByTag()` da interface — qualquer uso de `MONITORING_DRIVER=single` dava erro fatal. Implementado (retorna vazio, como as demais consultas do driver de arquivo).

### 🛡️ Nenhum log perdido — rascunho em disco
- Cada entrada é anexada no ato a um `.jsonl` do processo (`Support\Spool`, `monitoring.spool`). Gravou no banco → o arquivo é zerado; banco fora → o arquivo fica (com `fsync`); processo morto (SIGKILL, OOM, timeout do FPM, deploy) → o arquivo fica, com a trava `flock` liberada pelo SO.
- Novo `monitoring:spool-recover`, agendado a cada minuto: grava no banco os arquivos sem dono e os apaga. Idempotente (`insertOrIgnore` por `uuid` único).
- A gravação passou a usar `insertOrIgnore` também no flush normal.
- Em Docker, a pasta precisa ser um volume compartilhado pelos containers do serviço (incluindo o scheduler). Os docker-compose do ecossistema já montam `monitoring_spool`.

### 🐛 Corrigido
- **Workers não gravavam nada**: em `horizon`/`queue:work` o monitoring era desligado por inteiro — `logglyError()` dentro de job e job que falhou sumiam, e o `JobWatcher` nunca rodava em produção. Agora há o modo worker (`monitoring.workers`): Loggly + exceções + jobs falhos, batch e flush por job.
- **Logs perdidos em rollback**: o flush por tamanho podia cair no meio de um `DB::beginTransaction()` na mesma conexão e ir embora no rollback. Agora o flush espera a transação fechar.
- **Retenção apagava ~metade por execução**: `chunk()` (OFFSET) apagando dentro do laço pulava um lote a cada página. Agora `chunkById`. Nova opção `retention.export` para não exportar ao disco (o `local` de container é efêmero).
- **Processo longo num batch só** (listener de eventos, daemon): o batch automático expira após `batch_max_age_seconds`; console também grava por tempo (`flush_interval_seconds`).

### 🧪 Testes
- Suíte PHPUnit (Testbench) — SQLite, PostgreSQL e Redis locais. Ver README → Testes.

## [4.0.1] - 2026-07-20
- Implementado verificação se coluna existe na tabela

## [4.0.0] — 2026-07-18

### 🔒 Segurança
- **Corpo da resposta HTTP era gravado CRU** no `RequestWatcher`: a redação usava lista vazia (`hideParameters($decoded, [])`), então um corpo com segredo (ex.: resposta de login com `access_token`) ficava exposto na tabela de monitoramento. Agora redige via nova config `monitoring.hidden_response_parameters` + o registro estático `Monitoring::$hiddenResponseParameters` (suporta dot-notation, ex.: `data.access_token`). Vazio = comportamento anterior (retrocompatível).

### ⚡ Performance
- **Alertas (Slack/Discord/Email) agora são enfileirados** (`SendMonitoringAlertJob`) em vez de enviados de forma **síncrona dentro da request**. Antes, um alerta (ex.: "requisição lenta") disparava 2 POST de webhook + e-mail no caminho da resposta — atrasando justamente a request já lenta. O I/O externo saiu do request-path; ganhou também `Http::timeout(10)`.
- **`Loggly::resolveCaller()` mais barato e confiável**: passou a ler a origem (classe/função) direto dos frames da pilha em vez de derivar a classe pelo caminho do arquivo. Removeu o parsing de path e o loop de matching; além de mais rápido, agora resolve corretamente logs originados em **package/vendor** (antes qualquer log fora de `app/` virava `anonymous`, perdendo classe e função).

### 🐛 Corrigido
- `Loggly::writeToFile()` (fallback de log): o `catch` chamava `Log::info(...)` — que reentra no sistema de eventos (`MessageLogged → ExceptionWatcher`) justamente no caminho que deveria silenciar — e tinha um `;` duplo. Trocado por `error_log()` nativo (não dispara eventos Laravel).
- Removido o método morto `MonitoringQueryService::getRetentionCandidateIds()` (nunca era chamado e continha um `yield` inócuo dentro do callback do `chunk()`).
- **Chave de conexão inconsistente entre migrations**: `create_monitorings_table` e `add_resolved_fields` liam `config('monitoring.drivers.db_connection')` (chave inexistente → conexão default), enquanto `add_indexes` e `add_trgm` liam a chave correta `monitoring.drivers.database.connection`. Num setup com conexão de monitoring dedicada (≠ default), a tabela e os campos `resolved_at`/`resolved_by` iriam para o banco errado — quebrando `resolveEvent()`/`getUnresolvedExceptions()`. As 4 migrations agora usam a mesma chave.

### 🗄️ Migrations
- `add_trgm_search_index`: criação da extensão via `Tpetry\PostgresqlEnhanced\Support\Facades\Schema::createExtensionIfNotExists('pg_trgm')` (mais legível/idempotente). Adicionado `SET maintenance_work_mem = '256MB'` na sessão antes do build do GIN para acelerar a criação em tabelas grandes (session-scoped, ajustável, com fallback se não houver permissão). Índices seguem `CONCURRENTLY` (não travam escrita).

### 📝 Nota de dependência
- A captura de device/GeoIP por request (via `IncomingEntry` → `RiseTools\Device::info()`) depende do package RiseTools. A chamada externa de GeoIP (ip-api) passou a ter **timeout + cache por IP** no RiseTools — mantendo o custo fora do caminho da request. Garanta o RiseTools atualizado.

---

## [3.0.0] — 2026-04-28

### ✨ Adicionado

#### 1. Sistema de Relatórios Automáticos — `monitoring:report`

Gera relatórios periódicos (diário, semanal, mensal) com métricas e estatísticas completas.

**Arquivos:**
- `src/Console/Commands/MonitoringReportCommand.php`
- `src/Services/Reporting/ReportService.php`
- `resources/views/reports/report.blade.php`

**Funcionamento:**
- Relatório HTML bonito com cards, tabelas e métricas de performance
- Envio automático via email, Slack ou Discord
- Agendamento automático via configuração
- Salvar relatório em arquivo (`--save`)

**Exemplos de uso:**
```bash
# Gerar relatório diário
php artisan monitoring:report daily

# Gerar e enviar automaticamente
php artisan monitoring:report daily --send

# Enviar para canais específicos
php artisan monitoring:report weekly --send --channels=email,slack

# Preview no console
php artisan monitoring:report monthly --preview

# Salvar HTML no storage
php artisan monitoring:report daily --save
```

**Configuração no `.env`:**
```env
MONITORING_REPORTS_AUTO_SCHEDULE=true
MONITORING_REPORT_EMAIL_TO=admin@empresa.com,dev@empresa.com
MONITORING_REPORT_EMAIL_FROM=monitoring@empresa.com
```

---

#### 2. Relatórios 100% Customizáveis — `ReportHandlerInterface`

Sistema completo para substituir canais de envio ou implementar notificações próprias.

**Arquivos:**
- `src/Contracts/ReportHandlerInterface.php`
- `src/Events/ReportGenerated.php`

**Métodos disponíveis:**
```php
// Registrar handler customizado
ReportService::registerHandler('meu_email', new MeuEmailHandler());

// Desabilitar notificações padrão (100% autônomo)
ReportService::disableDefaultNotifications();
```

**Exemplo de Handler:**
```php
class MeuEmailHandler implements ReportHandlerInterface
{
    public function send(array $report, string $html, array $config = []): bool
    {
        // Sua lógica personalizada
        return \Mail::send('minha-view', compact('report'), ...);
    }

    public function getSupportedChannels(): array
    {
        return ['email']; // Substitui só o email
    }
}
```

---

#### 3. Timeline por Tag — `getTimelineByTag()`

Rastreabilidade cronológica de eventos filtrados por tag, agrupados por `batch_id`.

**Arquivo:** `src/Repository/MonitoringRepository.php`

**Endpoint HTTP:**
```http
GET /monitoring/timeline/{tag}/{value}

# Exemplos:
GET /monitoring/timeline/pedido_id/123
GET /monitoring/timeline/user_id/uuid-aqui?period=24%20hours
```

**Uso programático:**
```php
$timeline = $repository->getTimelineByTag('pedido_id', '123', '24 hours');
// Retorna eventos agrupados por batch_id único
```

---

#### 4. Comando para Testar Watchers — `monitoring:test-watchers`

Testa todos os watchers disparando eventos de exemplo e verificando se registram corretamente.

**Arquivo:** `src/Console/Commands/MonitoringTestWatchersCommand.php`

**Funcionamento:**
- Dispara eventos de teste para cada watcher
- Verifica se os registros aparecem no banco
- Limpa registros de teste automaticamente

**Exemplos de uso:**
```bash
# Testar todos os watchers
php artisan monitoring:test-watchers

# Mostrar detalhes
php artisan monitoring:test-watchers --details

# Aguardar mais tempo entre testes
php artisan monitoring:test-watchers --wait=2

# Manter registros de teste
php artisan monitoring:test-watchers --no-cleanup
```

---

### 🔄 Alterado

#### `ReportService`
- Adicionado suporte a handlers customizados (`registerHandler()`)
- Adicionado método `disableDefaultNotifications()` para 100% autonomia
- Novo evento `ReportGenerated` disparado antes do envio
- Processamento de handlers customizados antes dos canais padrão

#### `MonitoringReportCommand`
- Fix: Cria diretório automaticamente ao salvar relatório (`--save`)

#### `MonitoringTestWatchersCommand`
- Fix: Syntax error (ponto e vírgula faltando na closure)

#### `CacheWatcher`
- Fix: Usando método correto `Monitoring::recordCache()` em vez de `Monitoring::record()`

#### `config/config.php`
- Novo bloco `reports` com configurações de agendamento e canais
- Suporte a `custom_handlers` para relatórios

#### `README.md`
- Documentação completa do sistema de relatórios
- Documentação do sistema customizável (handlers e eventos)
- Tabela comparativa: Alertas vs Relatórios

---

### 📁 Novos Arquivos

```
src/
├── Console/
│   └── Commands/
│       └── MonitoringReportCommand.php      # Gera relatórios
│       └── MonitoringTestWatchersCommand.php # Testa watchers
├── Contracts/
│   └── ReportHandlerInterface.php          # Interface para handlers
├── Events/
│   └── ReportGenerated.php                 # Evento de relatório
├── Services/
│   └── Reporting/
│       └── ReportService.php               # Serviço de relatórios
resources/
└── views/
    └── reports/
        └── report.blade.php                # Template HTML do relatório
```

---

## [2.1.2] — 2025-03-29
- Atualizado parametros para serem ignorados.

## [2.1.1] — 2025-03-18
- Corrigido nullable e migration

## [2.1.0] — 2025-03-17
- Corrigido migration

## [2.0.0] — 2025-03-14

### ✨ Adicionado

#### 1. Política de Retenção e Backup (90 dias) — `monitoring:retention`

Novo comando Artisan que gerencia o ciclo de vida dos logs no banco de dados.

**Arquivo:** `src/Console/Commands/MonitoringRetentionCommand.php`
**Serviço:** `src/Services/RetentionService.php`

**Funcionamento:**
- Processa os registros em lotes (`--chunk`, padrão 500) para evitar estouro de memória
- Exporta cada lote para o Storage configurado (JSON ou CSV) **antes** de removê-lo
- A remoção do banco só ocorre após a confirmação de escrita no arquivo
- Em caso de falha na exportação, o lote **não é deletado** (segurança dos dados)

**Exemplos de uso:**
```bash
# Execução padrão (90 dias, JSON, disco local)
php artisan monitoring:retention

# Personalizado
php artisan monitoring:retention --days=60 --format=csv --disk=s3

# Simulação sem alterações
php artisan monitoring:retention --dry-run

# Automático (sem confirmação interativa — ideal para scheduler)
php artisan monitoring:retention --force
```

**Agendamento automático via config:**
```php
// config/monitoring.php
'retention' => [
    'auto_schedule' => true,   // ativa o agendamento
    'days'          => 90,
    'format'        => 'json',
    'disk'          => 'local',
    'time'          => '02:00', // executa diariamente às 02h
    'chunk_size'    => 500,
],
```

**Variáveis de ambiente:**
```env
MONITORING_RETENTION_AUTO_SCHEDULE=true
MONITORING_RETENTION_DAYS=90
MONITORING_RETENTION_FORMAT=json
MONITORING_RETENTION_DISK=s3
MONITORING_RETENTION_TIME=02:00
MONITORING_RETENTION_CHUNK=500
```

---

#### 2. Rastreabilidade Avançada — Busca por Tags + Expansão por Batch ID

**Arquivo:** `src/Services/MonitoringQueryService.php`
**Método principal:** `getByTagsWithBatchExpansion(array $tags)`

**Funcionamento (3 passos):**
1. Localiza todos os logs cujas tags JSON contêm os pares `chave => valor` informados
2. Coleta os `batch_id` únicos desses logs
3. Retorna **todos os logs** que compartilham esses `batch_id` — mesmo os que não possuem a tag filtrada

Isso permite reconstruir o **fluxo completo** de uma requisição ou job a partir de um único critério (ex.: `user_id`).

**Endpoints HTTP:**

```http
# Busca por tags com expansão de batch
POST /monitoring/tags
Content-Type: application/json
{
  "tags": { "user_id": "550e8400-e29b-41d4-a716-446655440000" }
}

# Busca direta por usuário (atalho para o caso mais comum)
GET /monitoring/user/550e8400-e29b-41d4-a716-446655440000
```

**Uso programático:**
```php
// Via repositório
$events = $repository->getEventsByTags(['user_id' => $userId]);
$events = $repository->getEventsByUserId($userId);

// Via serviço diretamente
$queryService->getByTagsWithBatchExpansion(['user_id' => $userId]);
$queryService->getByUserId($userId);

// Tags múltiplas
$queryService->getByTagsWithBatchExpansion([
    'user_id' => $userId,
    'action'  => 'checkout',
]);
```

---

#### 3. Exportação de Relatórios — CSV e JSON

**Arquivo:** `src/Services/ExportService.php`
**Comando:** `src/Console/Commands/MonitoringExportCommand.php`

O CSV inclui BOM UTF-8 para compatibilidade nativa com Excel e Google Sheets.

**Colunas do relatório:**

| Coluna        | Descrição                                      |
|---------------|------------------------------------------------|
| ID            | UUID do evento                                 |
| Batch ID      | UUID do batch da requisição/job                |
| Tipo de Evento| Request HTTP / Exceção / Job / Comando / etc.  |
| Status HTTP   | Código de status da resposta (quando aplicável)|
| Método        | GET / POST / PUT / DELETE                      |
| URI           | Caminho da requisição ou descrição do job      |
| Usuário (ID)  | user_id extraído das tags ou do campo user     |
| Usuário (E-mail)| E-mail do usuário (quando disponível)        |
| Tags (JSON)   | JSON completo das tags                         |
| Data/Hora     | Timestamp de criação                           |

**Via HTTP:**
```http
POST /monitoring/export
Content-Type: application/json
{
  "format": "csv",
  "type": "request",
  "user_id": "550e8400-e29b-41d4-a716-446655440000",
  "from": "2025-01-01",
  "to": "2025-01-31",
  "expand_batch": true
}
```

**Via Artisan:**
```bash
# Exportar tudo para CSV (Storage local)
php artisan monitoring:export

# Filtros combinados
php artisan monitoring:export \
  --type=exception \
  --user-id=550e8400-e29b-41d4-a716-446655440000 \
  --from=2025-01-01 \
  --to=2025-01-31 \
  --format=csv \
  --output=s3

# Expansão de batch (inclui logs relacionados do mesmo batch)
php artisan monitoring:export --user-id=uuid --expand-batch

# Imprimir na saída padrão (útil para pipes)
php artisan monitoring:export --stdout
```

---

#### 4. Otimização de Consultas — `MonitoringQueryService`

**Arquivo:** `src/Services/MonitoringQueryService.php`

Toda a lógica de queries foi extraída do repositório para um serviço dedicado,
organizado como **Scopes reutilizáveis** (padrão análogo ao Eloquent).

**Scopes disponíveis:**

| Método                     | Descrição                                              |
|----------------------------|--------------------------------------------------------|
| `scopeType($q, $type)`     | Filtra por tipo de evento                              |
| `scopeBatch($q, $batchId)` | Filtra por batch_id (usa índice)                       |
| `scopeDateRange($q, $from, $to)` | Filtra por intervalo de datas (usa índice)       |
| `scopeTagKey($q, $key, $value)` | Filtra por par chave/valor no JSON `tags`        |
| `scopeTags($q, $tags)`     | Aplica múltiplos `scopeTagKey` em cadeia               |
| `scopeOlderThan($q, $days)`| Registros mais antigos que N dias (para retenção)      |
| `scopeLatestFirst($q)`     | Ordenação decrescente por `created_at`                 |

**Migration de índices** (`2025_01_01_000001_add_indexes_to_monitorings_table.php`):

- **PostgreSQL:** índice `GIN` na coluna `tags` + índice de expressão em `tags->>'user_id'`
- **MySQL/MariaDB:** coluna virtual gerada `tags_user_id` + índice na virtual
- **Ambos:** índice composto `(type, created_at)` para queries de retenção e filtro por tipo

---

### 🔄 Alterado

#### `MonitoringRepository`
- Refatorado para delegar queries ao `MonitoringQueryService`
- Mantém retrocompatibilidade total com a interface existente
- Novo método `getEventsByUserId(string $userId): Collection`

#### `MonitoringRepositoryInterface`
- Assinatura de `getEventsByTags()` corrigida: aceita `array $tags = []`
- Novo método `getEventsByUserId(string $userId): Collection`

#### `MonitoringRepositorySingle`
- Implementa os novos métodos da interface (retornam `collect()`)

#### `MonitoringController`
- Injeção de `ExportService` via construtor
- Novo endpoint `GET /monitoring/user/{userId}`
- Novo endpoint `POST /monitoring/export`
- Validação do campo `tags` (deve ser objeto JSON)

#### `Routes`
- Rotas novas registradas: `/user/{userId}` e `/export`
- Ordem corrigida: `/{id}` movido para o final do grupo (evita conflito com rotas nomeadas)

#### `MonitoringServiceProvider`
- Registro dos novos comandos Artisan
- Binding dos serviços `MonitoringQueryService`, `RetentionService`, `ExportService`
- Agendamento automático via `Schedule` (controlado por `monitoring.retention.auto_schedule`)
- Comandos do próprio package adicionados à lista `IGNORED_COMMANDS`

#### `config/config.php`
- Novo bloco `retention` com todas as opções configuráveis
- Suporte a variáveis de ambiente para cada opção de retenção

---

### 📁 Novos Arquivos

```
src/
├── Console/
│   └── Commands/
│       ├── MonitoringRetentionCommand.php   # Comando de retenção
│       └── MonitoringExportCommand.php      # Comando de exportação
├── Services/
│   ├── MonitoringQueryService.php           # Camada de queries (Scopes)
│   ├── RetentionService.php                 # Lógica de backup + remoção
│   └── ExportService.php                    # Geração de CSV / JSON
database/
└── migrations/
    └── 2025_01_01_000001_add_indexes_to_monitorings_table.php
```

---

### 🗄️ Estrutura dos Arquivos de Backup (Retenção)

```
storage/app/monitoring/retention/
└── 2025-01-01/
    ├── 20250101_020000_batch1.json
    ├── 20250101_020000_batch2.json
    └── ...
```

### 🗄️ Estrutura dos Arquivos de Exportação

```
storage/app/monitoring/exports/
├── monitoring_export_20250114_153000.csv
└── monitoring_export_20250114_153000.json
```

---

## [1.2.0] — Versão anterior

- Versão original com watchers, repositório e controller base.

---

## Guia de Migração: 1.x → 2.0

1. **Publicar a nova configuração:**
   ```bash
   php artisan vendor:publish --tag=config --provider="RiseTechApps\Monitoring\MonitoringServiceProvider" --force
   ```

2. **Executar as migrações** (adiciona índices de performance):
   ```bash
   php artisan migrate
   ```

3. **Habilitar retenção automática** (opcional):
   ```env
   MONITORING_RETENTION_AUTO_SCHEDULE=true
   MONITORING_RETENTION_DAYS=90
   ```

4. **Verificar** se a interface customizada implementa o novo método:
   ```php
   public function getEventsByUserId(string $userId): Collection;
   ```
   Se não implementar, adicione o método (pode retornar `collect()` como stub).
