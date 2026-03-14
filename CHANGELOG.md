# Changelog — Monitoring for Laravel

Todas as mudanças notáveis deste projeto estão documentadas neste arquivo.
O formato segue o padrão [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).

---
## [2.0.1] — 2025-03-15
- Implementado setType em IncomingEntry
- 
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
