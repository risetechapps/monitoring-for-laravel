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

    /**
     * Marca um evento como resolvido.
     *
     * @param string $id UUID ou ID do evento
     * @param string|null $resolvedBy Identificador de quem resolveu (user_id ou nome)
     * @return bool True se o evento foi encontrado e atualizado
     */
    public function resolveEvent(string $id, ?string $resolvedBy = null): bool;

    /**
     * Marca múltiplos eventos como resolvidos (por tipo ou exceção similar).
     *
     * @param string $exceptionClass Classe da exceção a ser marcada como resolvida
     * @param string|null $resolvedBy Identificador de quem resolveu
     * @return int Número de eventos atualizados
     */
    public function resolveExceptionType(string $exceptionClass, ?string $resolvedBy = null): int;

    /**
     * Remove o status de resolvido de um evento.
     *
     * @param string $id UUID ou ID do evento
     * @return bool True se o evento foi encontrado e atualizado
     */
    public function unresolveEvent(string $id): bool;

    /**
     * Lista exceções não resolvidas (para dashboard de monitoring).
     *
     * @return Collection Lista de exceções agrupadas por tipo
     */
    public function getUnresolvedExceptions(): Collection;

    /**
     * Busca eventos com filtros avançados.
     *
     * @param array $filters Filtros de busca
     * @return Collection Eventos filtrados
     */
    public function getEventsWithFilters(array $filters): Collection;

    /**
     * Busca full-text nos eventos.
     *
     * @param string $query Termo de busca
     * @param string|null $type Tipo de evento (opcional)
     * @return Collection Resultados da busca
     */
    public function searchEvents(string $query, ?string $type = null): Collection;

    /**
     * Retorna timeline cronológico de eventos por tag.
     *
     * @param string $tag Nome da tag (ex: 'pedido_id')
     * @param string $value Valor da tag (ex: '123')
     * @param string $period Período de busca (ex: '24 hours', '7 days', '1 month')
     * @return Collection Eventos ordenados cronologicamente
     */
    public function getTimelineByTag(string $tag, string $value, string $period = '24 hours'): Collection;
}
