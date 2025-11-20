<<<<<<< HEAD
<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringRepository implements MonitoringRepositoryInterface
{
    protected string $table = 'monitoring';
    protected mixed $connection;

    /**
     * Campos que devem ser convertidos em JSON ao salvar e decodificados no retorno.
     */
    protected array $jsonColumns = [
        'content',
        'tags',
        'user',
        'device',
    ];

    public function __construct($connection = null)
    {
        $this->connection = $connection ?? config('monitoring.drivers.db_connection');
    }

    /**
     * Inserir múltiplos eventos no banco.
     */
    public function create(array $data): void
    {
        // adiciona uuid para cada entrada
        $data = array_map(function ($entry) {
            $entry['id'] = self::generateUuid();
            return $entry;
        }, $data);

        // converte apenas os campos que realmente são JSON
        $data = $this->encodeJsonColumns($data);

        DB::connection($this->connection)
            ->table($this->table)
            ->insert($data);
    }

    /**
     * Retorna todos os eventos (ordenados por mais recente)
     */
    public function getAllEvents(): Collection
    {
        return DB::connection($this->connection)
            ->table($this->table)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->map(fn($event) => $this->formatEvent($event));
    }

    /**
     * Retorna um evento pelo ID e todos os eventos relacionados ao mesmo batch.
     */
    public function getEventById(string $id): Collection
    {
        $event = DB::connection($this->connection)
            ->table($this->table)
            ->where('uuid', $id)
            ->orWhere('id', $id)
            ->first();

        if (!$event) {
            return collect();
        }

        $related = DB::connection($this->connection)
            ->table($this->table)
            ->where('batch_id', $event->batch_id)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->reject(fn($row) => $row->id === $event->id)
            ->values();

        return collect($this->formatEvent($event, $related));
    }

    /**
     * Busca eventos por tipo e retorna também relacionados por batch.
     */
    public function getEventsByTypes(string $type): Collection
    {
        $events = DB::connection($this->connection)
            ->table($this->table)
            ->where('type', $type)
            ->orderBy('created_at', 'DESC')
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        $batchIds = $events->pluck('batch_id')->unique()->values();

        $related = DB::connection($this->connection)
            ->table($this->table)
            ->whereIn('batch_id', $batchIds)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->groupBy('batch_id');

        return $events->map(function ($event) use ($related) {
            $relatedEvents = $related->get($event->batch_id, collect())
                ->reject(fn($row) => $row->id === $event->id)
                ->values();

            return $this->formatEvent($event, $relatedEvents);
        });
    }

    /**
     * Busca eventos por tags JSON.
     * Exemplo de entrada:
     * ["action" => "index"]
     */
    public function getEventsByTags(array $tags): Collection
    {
        $events = DB::connection($this->connection)
            ->table($this->table)
            ->whereJsonContains('tags', $tags)
            ->orderBy('created_at', 'DESC')
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        $batchIds = $events->pluck('batch_id')->unique()->values();

        $related = DB::connection($this->connection)
            ->table($this->table)
            ->whereIn('batch_id', $batchIds)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->groupBy('batch_id');

        return $events->map(function ($event) use ($related) {
            $relatedEvents = $related->get($event->batch_id, collect())
                ->reject(fn($row) => $row->id === $event->id)
                ->values();

            return $this->formatEvent($event, $relatedEvents);
        });
    }

    /**
     * Formata evento antes de retornar ao front.
     */
    protected function formatEvent(object $event, Collection $related = null): array
    {
        $related = $related ?: collect();

        return [
            'id'         => $event->id,
            'uuid'       => $event->uuid,
            'batch_id'   => $event->batch_id,
            'type'       => $event->type,
            'content'    => $this->decodeIfJson($event->content),
            'tags'       => $this->decodeIfJson($event->tags),
            'user'       => $this->decodeIfJson($event->user ?? null),
            'device'     => $this->decodeIfJson($event->device ?? null),
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
            'related_events' => $related->map(fn($r) => [
                'id'         => $r->id,
                'uuid'       => $r->uuid,
                'batch_id'   => $r->batch_id,
                'type'       => $r->type,
                'content'    => $this->decodeIfJson($r->content),
                'tags'       => $this->decodeIfJson($r->tags),
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ]),
        ];
    }

    /**
     * Codifica apenas colunas JSON para json_encode().
     */
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

    /**
     * Decodifica valores JSON se forem strings JSON válidas.
     */
    protected function decodeIfJson($value)
    {
        if (is_string($value) && $this->isJson($value)) {
            return json_decode($value, true);
        }
        return $value;
    }

    protected function isJson($value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Gerar UUID v7 ou fallback.
     */
    protected static function generateUuid(): string
    {
        if (class_exists(\Symfony\Component\Uid\Uuid::class) &&
            method_exists(\Symfony\Component\Uid\Uuid::class, 'v7')) {
            return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        }

        if (method_exists(Str::class, 'orderedUuid')) {
            return (string) Str::orderedUuid();
        }

        return (string) Str::uuid();
    }
}
=======
<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringRepository implements MonitoringRepositoryInterface
{
    protected string $table = 'monitorings';
    protected $connection;

    public function __construct($connection = null)
    {
        if (is_null($connection)) $connection = env('DB_CONNECTION', 'mysql');
        $this->connection = $connection;
    }

    public function create(array $data): void
    {
        DB::connection($this->connection)->table($this->table)->insert($data);
    }

    public function getAllEvents(): Collection
    {
        return DB::connection($this->connection)->table($this->table)->get();
    }

    public function getEventById(string $id): Collection
    {
        $event = DB::connection($this->connection)->table($this->table)->where('uuid', $id)->first();

        if (!$event) {
            return collect();
        }

        $relatedEvents = DB::connection($this->connection)->table($this->table)
            ->where('batch_id', $event->batch_id)
            ->get()
            ->groupBy('batch_id');

        $batchRelated = $relatedEvents->get($event->batch_id, collect())
            ->reject(fn($related) => $related->id === $event->id)
            ->values();

        return collect($this->formatEvent($event, $batchRelated));

    }

    public function getEventsByTypes(string $type): Collection
    {
        // Recupera os eventos principais do tipo especificado
        $events = DB::connection($this->connection)->table($this->table)
            ->where('type', $type)
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        // Coleta os batch_ids únicos dos eventos principais
        $batchIds = $events->pluck('batch_id')->unique()->values();

        $relatedByBatch = DB::connection($this->connection)->table($this->table)
            ->whereIn('batch_id', $batchIds)
            ->get()
            ->groupBy('batch_id');

        return $events->map(function ($event) use ($relatedByBatch) {
            $relatedEvents = $relatedByBatch->get($event->batch_id, collect())
                ->reject(fn($related) => $related->id === $event->id)
                ->values();

            return $this->formatEvent($event, $relatedEvents);
        });
    }

    protected function formatEvent(object $event, Collection $relatedEvents): array
    {
        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'batch_id' => $event->batch_id,
            'type' => $event->type,
            'content' => $event->content,
            'tags' => $event->tags,
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
            'related_events' => $relatedEvents->map(function ($relatedEvent) {
                return [
                    'id' => $relatedEvent->id,
                    'uuid' => $relatedEvent->uuid,
                    'batch_id' => $relatedEvent->batch_id,
                    'type' => $relatedEvent->type,
                    'content' => $relatedEvent->content,
                    'tags' => $relatedEvent->tags,
                    'created_at' => $relatedEvent->created_at,
                    'updated_at' => $relatedEvent->updated_at,
                ];
            })->toArray(),
        ];
    }
}
>>>>>>> origin/main
