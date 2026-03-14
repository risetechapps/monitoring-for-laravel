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
    protected string $table = 'monitoring';
    protected string $connection;
    protected MonitoringQueryService $queryService;

    protected array $jsonColumns = ['content', 'tags', 'user', 'device'];

    public function __construct(string $connection = null)
    {
        $this->connection   = $connection ?? config('monitoring.drivers.database.connection', config('database.default'));
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
            ->map(fn ($event) => $this->formatEvent($event));
    }

    public function getEventById(string $id): Collection
    {
        $event = $this->queryService->findById($id);

        if (!$event) {
            return collect();
        }

        $related = $this->queryService->getByBatchId($event->batch_id)
            ->reject(fn ($row) => $row->id === $event->id)
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

        $related = DB::connection($this->connection)
            ->table($this->table)
            ->whereIn('batch_id', $batchIds)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->groupBy('batch_id');

        return $events->map(function (object $event) use ($related): array {
            $relatedEvents = $related->get($event->batch_id, collect())
                ->reject(fn ($row) => $row->id === $event->id)
                ->values();

            return $this->formatEvent($event, $relatedEvents);
        });
    }

    /**
     * Busca por tags JSON com rastreabilidade recursiva por batch_id.
     *
     * @param  array<string, string>  $tags  ex.: ['user_id' => 'uuid-aqui']
     */
    public function getEventsByTags(array $tags = []): Collection
    {
        if (empty($tags)) {
            return collect(EntryType::getTypes());
        }

        $rows = $this->queryService->getByTagsWithBatchExpansion($tags);

        return $rows->map(fn ($event) => $this->formatEvent($event));
    }

    /**
     * Busca logs por user_id nas tags com expansão completa de batch.
     */
    public function getEventsByUserId(string $userId): Collection
    {
        return $this->queryService->getByUserId($userId)
            ->map(fn ($event) => $this->formatEvent($event));
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
    // Formatação
    // ---------------------------------------------------------------

    protected function formatEvent(object $event, Collection $related = null): array
    {
        $related = $related ?? collect();

        return [
            'id'             => $event->id,
            'uuid'           => $event->uuid,
            'batch_id'       => $event->batch_id,
            'type'           => $event->type,
            'content'        => $this->decodeIfJson($event->content),
            'tags'           => $this->decodeIfJson($event->tags),
            'user'           => $this->decodeIfJson($event->user ?? null),
            'device'         => $this->decodeIfJson($event->device ?? null),
            'created_at'     => $event->created_at,
            'updated_at'     => $event->updated_at,
            'related_events' => $related->map(fn ($r) => [
                'id'         => $r->id,
                'uuid'       => $r->uuid,
                'batch_id'   => $r->batch_id,
                'type'       => $r->type,
                'content'    => $this->decodeIfJson($r->content),
                'tags'       => $this->decodeIfJson($r->tags),
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
            return (string) Str::orderedUuid();
        }

        return (string) Str::uuid();
    }
}
