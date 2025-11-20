<?php

namespace RiseTechApps\Monitoring\Repository\Contracts;

use Illuminate\Support\Collection;

interface MonitoringRepositoryInterface
{
    public function create(array $data): void;

    public function getAllEvents(): Collection;

    public function getEventById(string $id): Collection;

    public function getEventsByTypes(string $type): Collection;

    public function getEventsByTags(array $tags): Collection;
}
