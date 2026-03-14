<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Repository\Contracts;

use Illuminate\Support\Collection;

interface MonitoringRepositoryInterface
{
    public function create(array $data): void;

    public function getAllEvents(): Collection;

    public function getEventById(string $id): Collection;

    public function getEventsByTypes(string $type): Collection;

    /** @param  array<string, string>  $tags */
    public function getEventsByTags(array $tags = []): Collection;

    public function getEventsByUserId(string $userId): Collection;

    public function getByBatch(string $id): Collection;

    public function getLast24Hours(): Collection;

    public function getLast7Days(): Collection;

    public function getLast15Days(): Collection;

    public function getLast30Days(): Collection;

    public function getLast60Days(): Collection;

    public function getLast90Days(): Collection;
}
