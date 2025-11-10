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
