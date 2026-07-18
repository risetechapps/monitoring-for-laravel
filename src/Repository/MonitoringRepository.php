<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Services\MonitoringQueryService;

/**
 * Repositório base de monitoramento.
 *
 * A lógica de queries foi extraída para MonitoringQueryService (v2.0).
 * Este repositório é responsável apenas pela formatação e orquestração
 * dos dados retornados ao controlador.
 */
class MonitoringRepository implements MonitoringRepositoryInterface
{
    /** Itens por página quando o cliente não informa */
    protected const DEFAULT_PER_PAGE = 50;

    /** Teto de itens por página — per_page é entrada do usuário */
    protected const MAX_PER_PAGE = 200;

    /**
     * Teto para consultas sem paginação (getAllEvents, getEventsByTypes, ...).
     *
     * A tabela `monitoring` cresce sem limite por natureza, então nenhuma leitura
     * pode carregar "tudo": sem teto, um único GET derruba o container.
     */
    protected const MAX_ROWS = 1000;

    /** Janela padrão da busca, em dias, quando o cliente não informa */
    protected const SEARCH_DEFAULT_DAYS = 30;

    /** Máximo de resultados da busca */
    protected const SEARCH_LIMIT = 100;

    protected string $table = 'monitoring';
    protected string $connection;
    protected MonitoringQueryService $queryService;

    protected array $jsonColumns = ['content', 'tags', 'user', 'device'];

    public function __construct(?string $connection = null)
    {
        $this->connection = $connection ?? config('monitoring.drivers.database.connection', config('database.default'));
        $this->queryService = new MonitoringQueryService($this->connection);
    }

    // ---------------------------------------------------------------
    // Escrita
    // ---------------------------------------------------------------

    public function create(array $data): void
    {
        $data = array_map(function (array $entry): array {
            $entry['id'] = self::generateUuid();
            return $entry;
        }, $data);

        $data = $this->encodeJsonColumns($data);

        DB::connection($this->connection)
            ->table($this->table)
            ->insert($data);
    }

    // ---------------------------------------------------------------
    // Leitura
    // ---------------------------------------------------------------

    public function getAllEvents(): Collection
    {
        return $this->queryService->getAll()
            ->map(fn($event) => $this->formatEvent($event));
    }

    public function getEventById(string $id): Collection
    {
        $event = $this->queryService->findById($id);

        if (!$event) {
            return collect();
        }

        $related = $this->queryService->getByBatchId($event->batch_id)
            ->reject(fn($row) => $row->id === $event->id)
            ->values();

        return collect($this->formatEvent($event, $related));
    }

    public function getEventsByTypes(string $type): Collection
    {
        $events = $this->queryService->getByType($type);

        if ($events->isEmpty()) {
            return collect();
        }

        $batchIds = $events->pluck('batch_id')->unique()->values()->toArray();

        // Teto na expansão de related. Cada evento primário traz os irmãos do
        // mesmo batch; sem limite, batches grandes multiplicam as linhas e o
        // resultado cresce muito além dos eventos primários (já limitados).
        // Em conjuntos grandes alguns related são truncados — aceitável num
        // listagem de dashboard, ao contrário de estourar a memória.
        $related = DB::connection($this->connection)
            ->table($this->table)
            ->whereIn('batch_id', $batchIds)
            ->orderBy('created_at', 'DESC')
            ->limit(self::MAX_ROWS)
            ->get()
            ->groupBy('batch_id');

        return $events->map(function (object $event) use ($related): array {
            $relatedEvents = $related->get($event->batch_id, collect())
                ->reject(fn($row) => $row->id === $event->id)
                ->values();

            return $this->formatEvent($event, $relatedEvents);
        });
    }

    /**
     * Busca por tags JSON com rastreabilidade recursiva por batch_id.
     *
     * @param array<string, string> $tags ex.: ['user_id' => 'uuid-aqui']
     */
    public function getEventsByTags(array $tags = []): Collection
    {
        if (empty($tags)) {
            return collect(EntryType::getTypes());
        }

        $rows = $this->queryService->getByTagsWithBatchExpansion($tags);

        return $rows->map(fn($event) => $this->formatEvent($event));
    }

    /**
     * Busca logs por user_id nas tags com expansão completa de batch.
     */
    public function getEventsByUserId(string $userId): Collection
    {
        return $this->queryService->getByUserId($userId)
            ->map(fn($event) => $this->formatEvent($event));
    }

    public function getByBatch(string $id): Collection
    {
        return $this->queryService->getByBatchId($id);
    }

    public function getLast24Hours(): Collection
    {
        return $this->queryService->getRecentDays(1);
    }

    public function getLast7Days(): Collection
    {
        return $this->queryService->getRecentDays(7);
    }

    public function getLast15Days(): Collection
    {
        return $this->queryService->getRecentDays(15);
    }

    public function getLast30Days(): Collection
    {
        return $this->queryService->getRecentDays(30);
    }

    public function getLast60Days(): Collection
    {
        return $this->queryService->getRecentDays(60);
    }

    public function getLast90Days(): Collection
    {
        return $this->queryService->getRecentDays(90);
    }

    // ---------------------------------------------------------------
    // Resolução de Exceções
    // ---------------------------------------------------------------

    /**
     * Marca um evento como resolvido.
     */
    public function resolveEvent(string $id, ?string $resolvedBy = null): bool
    {
        $event = $this->queryService->findById($id);

        if (!$event) {
            return false;
        }

        $updated = DB::connection($this->connection)
            ->table($this->table)
            ->where('id', $event->id)
            ->update([
                'resolved_at' => now(),
                'resolved_by' => $resolvedBy,
            ]);

        return $updated > 0;
    }

    /**
     * Marca múltiplos eventos de exceção como resolvidos.
     */
    public function resolveExceptionType(string $exceptionClass, ?string $resolvedBy = null): int
    {
        return DB::connection($this->connection)
            ->table($this->table)
            ->where('type', EntryType::EXCEPTION)
            ->whereNull('resolved_at')
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(content, '$.class')) = ?",
                [$exceptionClass]
            )
            ->update([
                'resolved_at' => now(),
                'resolved_by' => $resolvedBy,
            ]);
    }

    /**
     * Remove o status de resolvido de um evento.
     */
    public function unresolveEvent(string $id): bool
    {
        $event = $this->queryService->findById($id);

        if (!$event) {
            return false;
        }

        $updated = DB::connection($this->connection)
            ->table($this->table)
            ->where('id', $event->id)
            ->update([
                'resolved_at' => null,
                'resolved_by' => null,
            ]);

        return $updated > 0;
    }

    /**
     * Lista exceções não resolvidas agrupadas por tipo.
     */
    public function getUnresolvedExceptions(): Collection
    {
        $exceptions = DB::connection($this->connection)
            ->table($this->table)
            ->where('type', EntryType::EXCEPTION)
            ->whereNull('resolved_at')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.class')) as exception_class, COUNT(*) as count, MAX(created_at) as last_occurrence")
            ->groupBy('exception_class')
            ->orderByDesc('count')
            ->get();

        return $exceptions->map(fn($row) => [
            'exception_class' => $row->exception_class,
            'count' => $row->count,
            'last_occurrence' => $row->last_occurrence,
        ]);
    }

    /**
     * Busca eventos com filtros avançados.
     */
    public function getEventsWithFilters(array $filters): Collection
    {
        $query = DB::connection($this->connection)
            ->table($this->table);

        // Filtro por tipo
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Filtro por data
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }

        // Filtro por não resolvidos
        if (!empty($filters['unresolved'])) {
            $query->whereNull('resolved_at');
        }

        // Ordenação
        $sort = $filters['sort'] ?? 'created_at';
        $order = $filters['order'] ?? 'desc';
        $query->orderBy($sort, $order);

        // Paginação — per_page vem da query string, então precisa de teto.
        // Sem ele, ?per_page=999999 carrega a tabela inteira na memória.
        $perPage = (int)($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $results = $query->paginate($perPage);

        return $results->getCollection()->map(fn($event) => $this->formatEvent($event));
    }

    /**
     * Busca por substring em content/tags dentro de uma janela temporal.
     *
     * A lógica de query (janela obrigatória, driver-aware, escape de curingas)
     * vive em MonitoringQueryService::search. A versão anterior fazia
     * LOWER(content) LIKE '%...%' sem recorte de data — full table scan e
     * índice descartado pelo LOWER() na coluna.
     */
    public function searchEvents(string $query, ?string $type = null, int $days = self::SEARCH_DEFAULT_DAYS): Collection
    {
        return $this->queryService
            ->search($query, $type, max(1, $days), self::SEARCH_LIMIT)
            ->map(fn($event) => $this->formatEvent($event));
    }

    /**
     * Retorna timeline cronológico de eventos por tag, agrupado por batch_id único.
     */
    public function getTimelineByTag(string $tag, string $value, string $period = '24 hours'): Collection
    {
        $since = now()->sub($this->parsePeriod($period));

        // Busca eventos que contêm a tag específica
        $events = DB::connection($this->connection)
            ->table($this->table)
            ->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(tags, '$.$tag')) = ?",
                [$value]
            )
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        // Agrupa por batch_id único (evita duplicação de timelines)
        $batchIds = $events->pluck('batch_id')->unique()->values()->toArray();

        // Para cada batch_id único, busca todos os eventos relacionados
        return collect($batchIds)->map(function (string $batchId) use ($since) {
            // Busca todos os eventos deste batch
            $batchEvents = DB::connection($this->connection)
                ->table($this->table)
                ->where('batch_id', $batchId)
                ->where('created_at', '>=', $since)
                ->orderBy('created_at', 'asc')
                ->get();

            // Encontra o evento principal (primeiro do batch)
            $firstEvent = $batchEvents->first();

            // Formata todos os eventos do batch em ordem cronológica
            $timeline = $batchEvents->map(fn($e) => $this->formatTimelineEvent($e));

            return [
                'batch_id' => $batchId,
                'started_at' => $firstEvent?->created_at,
                'timeline' => $timeline,
                'duration_ms' => $this->calculateDuration($batchEvents),
                'event_count' => $batchEvents->count(),
            ];
        })->values();
    }

    /**
     * Formata evento para timeline.
     */
    protected function formatTimelineEvent(object $event): array
    {
        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'batch_id' => $event->batch_id,
            'type' => $event->type,
            'type_label' => $this->getTypeLabel($event->type),
            'icon' => $this->getTypeIcon($event->type),
            'content' => $this->decodeIfJson($event->content),
            'tags' => $this->decodeIfJson($event->tags),
            'created_at' => $event->created_at,
            'time_from_start_ms' => null, // Calculado posteriormente
        ];
    }

    /**
     * Calcula a duração total do batch.
     */
    protected function calculateDuration(Collection $events): ?int
    {
        if ($events->count() < 2) {
            return null;
        }

        $first = $events->first();
        $last = $events->last();

        return Carbon::parse($last->created_at)
            ->diffInMilliseconds(Carbon::parse($first->created_at));
    }

    /**
     * Parse período string para DateInterval.
     */
    protected function parsePeriod(string $period): \DateInterval
    {
        $mapping = [
            '1 hour' => 'PT1H',
            '6 hours' => 'PT6H',
            '12 hours' => 'PT12H',
            '24 hours' => 'P1D',
            '1 day' => 'P1D',
            '7 days' => 'P7D',
            '1 week' => 'P7D',
            '30 days' => 'P30D',
            '1 month' => 'P30D',
            '90 days' => 'P90D',
            '3 months' => 'P90D',
        ];

        $interval = $mapping[$period] ?? 'P1D';

        return new \DateInterval($interval);
    }

    /**
     * Retorna label amigável para o tipo.
     */
    protected function getTypeLabel(string $type): string
    {
        return match ($type) {
            'request' => 'Requisição HTTP',
            'exception' => 'Exceção',
            'query' => 'Query SQL',
            'job' => 'Job',
            'cache' => 'Cache',
            'event' => 'Evento',
            'mail' => 'E-mail',
            'notification' => 'Notificação',
            'client_request' => 'Requisição Externa',
            'command' => 'Comando',
            'schedule' => 'Agendamento',
            'gate' => 'Autorização',
            'log' => 'Log',
            'model' => 'Model',
            'metric' => 'Métrica',
            default => $type,
        };
    }

    /**
     * Retorna ícone para o tipo.
     */
    protected function getTypeIcon(string $type): string
    {
        return match ($type) {
            'request' => '🌐',
            'exception' => '🚨',
            'query' => '🗄️',
            'job' => '⚙️',
            'cache' => '⚡',
            'event' => '📡',
            'mail' => '📧',
            'notification' => '🔔',
            'client_request' => '↗️',
            'command' => '⌨️',
            'schedule' => '⏰',
            'gate' => '🔒',
            'log' => '📝',
            'model' => '📦',
            'metric' => '📊',
            default => '📋',
        };
    }

    // ---------------------------------------------------------------
    // Formatação
    // ---------------------------------------------------------------

    protected function formatEvent(object $event, ?Collection $related = null): array
    {
        $related ??= collect();

        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'batch_id' => $event->batch_id,
            'type' => $event->type,
            'content' => $this->decodeIfJson($event->content),
            'tags' => $this->decodeIfJson($event->tags),
            'user' => $this->decodeIfJson($event->user ?? null),
            'device' => $this->decodeIfJson($event->device ?? null),
            'resolved_at' => $event->resolved_at ?? null,
            'resolved_by' => $event->resolved_by ?? null,
            'is_resolved' => !empty($event->resolved_at),
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
            'related_events' => $related->map(fn($r) => [
                'id' => $r->id,
                'uuid' => $r->uuid,
                'batch_id' => $r->batch_id,
                'type' => $r->type,
                'content' => $this->decodeIfJson($r->content),
                'tags' => $this->decodeIfJson($r->tags),
                'resolved_at' => $r->resolved_at ?? null,
                'resolved_by' => $r->resolved_by ?? null,
                'is_resolved' => !empty($r->resolved_at),
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ])->values(),
        ];
    }

    // ---------------------------------------------------------------
    // Utilitários
    // ---------------------------------------------------------------

    protected function encodeJsonColumns(array $data): array
    {
        foreach ($data as $i => $row) {
            foreach ($this->jsonColumns as $column) {
                if (isset($row[$column]) && (is_array($row[$column]) || is_object($row[$column]))) {
                    $data[$i][$column] = json_encode($row[$column]);
                }
            }
        }

        return $data;
    }

    protected function decodeIfJson(mixed $value): mixed
    {
        if (is_string($value) && $this->isJson($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    protected function isJson(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    protected static function generateUuid(): string
    {
        if (class_exists(\Symfony\Component\Uid\Uuid::class)
            && method_exists(\Symfony\Component\Uid\Uuid::class, 'v7')) {
            return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        }

        if (method_exists(Str::class, 'orderedUuid')) {
            return (string)Str::orderedUuid();
        }

        return (string)Str::uuid();
    }
}
