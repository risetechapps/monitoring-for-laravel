<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

/**
 * Driver de repositório baseado em arquivo de log (single).
 * Utilizado quando MONITORING_DRIVER=single.
 */
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

    public function getEventsByTags(array $tags = []): Collection
    {
        return collect(EntryType::getTypes());
    }

    public function getEventsByUserId(string $userId): Collection
    {
        return collect();
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

    /**
     * Operação não suportada no driver de arquivo.
     */
    public function resolveEvent(string $id, ?string $resolvedBy = null): bool
    {
        // Driver de arquivo não suporta resolução
        return false;
    }

    /**
     * Operação não suportada no driver de arquivo.
     */
    public function resolveExceptionType(string $exceptionClass, ?string $resolvedBy = null): int
    {
        // Driver de arquivo não suporta resolução em massa
        return 0;
    }

    /**
     * Operação não suportada no driver de arquivo.
     */
    public function unresolveEvent(string $id): bool
    {
        // Driver de arquivo não suporta resolução
        return false;
    }

    /**
     * Operação não suportada no driver de arquivo.
     */
    public function getUnresolvedExceptions(): Collection
    {
        // Driver de arquivo não suporta consulta de exceções
        return collect();
    }

    /**
     * Operação não suportada no driver de arquivo.
     */
    public function getEventsWithFilters(array $filters): Collection
    {
        return collect();
    }

    /**
     * Operação não suportada no driver de arquivo.
     */
    public function paginateEvents(array $filters): array
    {
        return [
            'data' => [],
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'totalPages' => 0,
            'perPage' => 0,
            'current_page' => 1,
        ];
    }

    /**
     * Operação não suportada no driver de arquivo.
     */
    public function searchEvents(string $query, ?string $type = null, int $days = 30): Collection
    {
        return collect();
    }

    /**
     * Operação não suportada no driver de arquivo.
     *
     * Faltava: sem ela a classe não implementava a interface e qualquer uso de
     * MONITORING_DRIVER=single dava erro fatal ao carregar o repositório.
     */
    public function getTimelineByTag(string $tag, string $value, string $period = '24 hours'): Collection
    {
        return collect();
    }
}
