<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringRepositoryPgsql  implements MonitoringRepositoryInterface
{
    protected string $connection;
    protected string $table = 'monitorings';

    public function __construct(string $connection)
    {
        $this->connection = $connection;
    }

    public function create(array $data): void
    {
        $data = $this->convertNestedArraysToJson($data);
        DB::connection($this->connection)->table($this->table)->insert($data);
    }

    public function convertNestedArraysToJson(array $data): array
    {
        foreach ($data as $key => $value) {
            foreach ($value as $k => $v) {

                if (is_array($v)) {
                    $data[$key][$k] = json_encode($v);
                }
            }
        }

        return $data;
    }

    public function getAllEvents(): Collection
    {
        return DB::connection($this->connection)->table($this->table)->get()->groupBy('batch_id')->values();
    }

    public function getEventById(string $id): Collection
    {
        $event = DB::connection($this->connection)->table($this->table)->where('uuid', $id)->first();

        if (!$event) {
            return collect();
        }

        $relatedEvents = DB::connection($this->connection)->table($this->table)
            ->where('batch_id', $event->batch_id)
            ->where('id', '!=', $event->id)
            ->get();

        return collect([
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
            })->toArray()
        ]);
    }

    public function getEventsByTypes(string $type): Collection
    {
        $events = DB::connection($this->connection)->table($this->table)
            ->where('type', $type)
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        $batchIds = $events->pluck('batch_id')->unique();

        $eventsWithRelated = $events->map(function ($event) use ($batchIds) {
            $relatedEvents = DB::connection($this->connection)->table($this->table)
                ->where('batch_id', $event->batch_id)
                ->where('id', '!=', $event->id)
                ->get();

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
                })->toArray()
            ];
        });
        return $eventsWithRelated;
    }
}
