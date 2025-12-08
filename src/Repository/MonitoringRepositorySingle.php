<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringRepositorySingle implements MonitoringRepositoryInterface
{

    public function create(array $data): void
    {
        foreach ($data as $monitoring) {
            Log::channel('monitoring')->info('log monitoring created', $monitoring);
        }
    }

    public function getAllEvents(): Collection
    {
        return collect();
    }

    public function getEventById(string $id): Collection
    {
        return collect();
    }

    public function getEventsByTypes(string $type): Collection
    {
        return collect();
    }

    public function getEventsByTags(): Collection
    {
        return collect(EntryType::getTypes());
    }

    public function getByBatch(string $id): Collection
    {
        return collect();
    }

    public function getLast24Hours(): Collection
    {
        return collect();
    }

    public function getLast7Days(): Collection
    {
        return collect();
    }
    public function getLast15Days(): Collection
    {
        return collect();
    }

    public function getLast30Days(): Collection
    {
        return collect();
    }

    public function getLast60Days(): Collection
    {
        return collect();
    }

    public function getLast90Days(): Collection
    {
        return collect();
    }
}
