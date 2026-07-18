<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serviço centralizado de consultas ao banco de monitoramento.
 *
 * Encapsula toda a lógica de query, garantindo:
 * - Reutilização de builders (similar a Eloquent Scopes)
 * - Consultas otimizadas e preparadas para os índices existentes
 * - Ponto único de manutenção
 */
class MonitoringQueryService
{
    /**
     * Teto de linhas para as consultas sem paginação.
     *
     * A tabela `monitoring` cresce sem limite por natureza — um `select *` nela
     * não tem tamanho previsível. Toda leitura sem paginação usa este teto;
     * é preferível devolver as N mais recentes a estourar a memória do container.
     * Para volumes maiores, use `getEventsWithFilters()` (paginado) ou o export.
     */
    public const int MAX_ROWS = 1000;

    protected string $table = 'monitoring';

    public function __construct(protected string $connection)
    {
    }

    // ---------------------------------------------------------------
    // Builder base (equivalente ao newQuery() do Eloquent)
    // ---------------------------------------------------------------

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }

    // ---------------------------------------------------------------
    // Scopes reutilizáveis
    // ---------------------------------------------------------------

    /** Scope: filtra por tipo */
    public function scopeType(\Illuminate\Database\Query\Builder $query, string $type): \Illuminate\Database\Query\Builder
    {
        return $query->where('type', $type);
    }

    /** Scope: filtra por batch_id — usa o índice `monitoring_batch_id_idx` */
    public function scopeBatch(\Illuminate\Database\Query\Builder $query, string $batchId): \Illuminate\Database\Query\Builder
    {
        return $query->where('batch_id', $batchId);
    }

    /** Scope: filtra por intervalo de datas — usa o índice `monitoring_created_at_idx` */
    public function scopeDateRange(
        \Illuminate\Database\Query\Builder $query,
        Carbon                             $from,
        ?Carbon                            $to = null
    ): \Illuminate\Database\Query\Builder
    {
        $query->where('created_at', '>=', $from->toDateTimeString());

        if ($to) {
            $query->where('created_at', '<=', $to->toDateTimeString());
        }

        return $query;
    }

    /** Scope: filtra por um par chave => valor dentro da coluna JSON `tags` */
    public function scopeTagKey(
        \Illuminate\Database\Query\Builder $query,
        string                             $key,
        string                             $value
    ): \Illuminate\Database\Query\Builder
    {
        $driver = DB::connection($this->connection)->getDriverName();

        if ($driver === 'pgsql') {
            // Usa o índice de expressão criado na migration de otimização
            $query->whereRaw("tags->>? = ?", [$key, $value]);
        } else {
            // MySQL / MariaDB — aproveita a coluna virtual `tags_user_id` quando key = user_id
            if ($key === 'user_id') {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(tags, '$.user_id')) = ?", [$value]);
            } else {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(tags, ?)) = ?", ["\$.{$key}", $value]);
            }
        }

        return $query;
    }

    /** Scope: filtra por múltiplos pares chave => valor dentro do JSON `tags` */
    public function scopeTags(
        \Illuminate\Database\Query\Builder $query,
        array                              $tags
    ): \Illuminate\Database\Query\Builder
    {
        foreach ($tags as $key => $value) {
            $this->scopeTagKey($query, $key, (string)$value);
        }
        return $query;
    }

    /** Scope: registros mais antigos que N dias (para retenção) */
    public function scopeOlderThan(\Illuminate\Database\Query\Builder $query, int $days): \Illuminate\Database\Query\Builder
    {
        return $query->where('created_at', '<', Carbon::now()->subDays($days)->toDateTimeString());
    }

    /** Scope: ordenação padrão decrescente */
    public function scopeLatestFirst(\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
    {
        return $query->orderBy('created_at', 'DESC');
    }

    /** Scope: teto de linhas para consultas sem paginação (ver MAX_ROWS) */
    public function scopeCapped(\Illuminate\Database\Query\Builder $query, ?int $limit = null): \Illuminate\Database\Query\Builder
    {
        return $query->limit($limit ?? self::MAX_ROWS);
    }

    // ---------------------------------------------------------------
    // Métodos de consulta públicos
    // ---------------------------------------------------------------

    public function getAll(): Collection
    {
        return $this->scopeCapped($this->scopeLatestFirst($this->query()))->get();
    }

    public function findById(string $id): ?object
    {
        return $this->query()
            ->where('uuid', $id)
            ->orWhere('id', $id)
            ->first();
    }

    public function getByBatchId(string $batchId): Collection
    {
        return $this->scopeCapped(
            $this->scopeLatestFirst(
                $this->scopeBatch($this->query(), $batchId)
            )
        )->get();
    }

    public function getByType(string $type): Collection
    {
        return $this->scopeCapped(
            $this->scopeLatestFirst(
                $this->scopeType($this->query(), $type)
            )
        )->get();
    }

    /**
     * Busca por tags JSON com rastreabilidade recursiva por batch_id.
     *
     * 1. Encontra todos os logs cujas tags contêm os pares informados
     * 2. Coleta os batch_ids únicos desses logs
     * 3. Retorna TODOS os logs que compartilham esses batch_ids
     *    (reconstrói o fluxo completo da requisição / job)
     *
     * @param array<string, string> $tags ex.: ['user_id' => 'uuid-aqui']
     */
    public function getByTagsWithBatchExpansion(array $tags): Collection
    {
        if (empty($tags)) {
            return collect();
        }

        // Passo 1: batch_ids dos logs que satisfazem o filtro.
        // Só a coluna batch_id é lida — os registros completos vêm no passo 2,
        // e trazê-los aqui seria carregar o mesmo dado duas vezes.
        $batchIds = $this->scopeCapped(
            $this->scopeLatestFirst(
                $this->scopeTags($this->query(), $tags)
            )
        )->pluck('batch_id')->unique()->values()->toArray();

        if (empty($batchIds)) {
            return collect();
        }

        // Passo 2: expansão — todos os logs dos mesmos batches
        return $this->scopeCapped(
            $this->scopeLatestFirst(
                $this->query()->whereIn('batch_id', $batchIds)
            )
        )->get();
    }

    /**
     * Busca apenas por user_id nas tags, com expansão de batch.
     */
    public function getByUserId(string $userId): Collection
    {
        return $this->getByTagsWithBatchExpansion(['user_id' => $userId]);
    }

    /**
     * Retorna logs mais recentes que $days atrás.
     */
    public function getRecentDays(int $days): Collection
    {
        return $this->scopeCapped(
            $this->scopeLatestFirst(
                $this->scopeDateRange($this->query(), Carbon::now()->subDays($days))
            )
        )->get();
    }

    /**
     * Conta eventos por tipo num período, agregando no banco.
     *
     * Existe para o endpoint /monitoring/compare, que antes carregava todos os
     * eventos do período na memória só para chamar count() e groupBy() em PHP.
     *
     * @return array{total: int, by_type: array<string, int>}
     */
    public function countByTypeSince(int $days, ?string $type = null): array
    {
        $query = $this->scopeDateRange($this->query(), Carbon::now()->subDays($days));

        if ($type !== null) {
            $this->scopeType($query, $type);
        }

        $rows = $query->select('type', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('type')
            ->get();

        $byType = [];
        $total = 0;

        foreach ($rows as $row) {
            $count = (int)$row->aggregate;
            $byType[$row->type] = $count;
            $total += $count;
        }

        return ['total' => $total, 'by_type' => $byType];
    }

    /**
     * Retorna registros para backup em lote (chunked por data de criação).
     *
     * @param int $retentionDays
     * @param int $chunkSize
     * @param callable $callback Recebe Collection de stdClass
     */
    public function chunkForRetention(int $retentionDays, int $chunkSize, callable $callback): void
    {
        $this->scopeOlderThan($this->query(), $retentionDays)
            ->orderBy('created_at', 'ASC')
            ->chunk($chunkSize, $callback);
    }

    /**
     * Remove registros por IDs em lote.
     */
    public function deleteByIds(array $ids): int
    {
        return $this->query()->whereIn('id', $ids)->delete();
    }

    /**
     * Retorna registros para backup em lote por tipo específico.
     *
     * @param string $entryType Tipo de entrada (exception, request, etc.)
     * @param int $retentionDays Dias de retenção
     * @param int $chunkSize Tamanho do lote
     * @param bool $keepUnresolved Manter exceções não resolvidas
     * @param callable $callback Função de callback
     */
    public function chunkForRetentionByType(
        string   $entryType,
        int      $retentionDays,
        int      $chunkSize,
        bool     $keepUnresolved,
        callable $callback
    ): void
    {
        $query = $this->query()
            ->where('type', $entryType)
            ->where('created_at', '<', Carbon::now()->subDays($retentionDays)->toDateTimeString());

        // Para exceções, opcionalmente mantém não resolvidas
        if ($keepUnresolved && $entryType === 'exception') {
            $query->whereNotNull('resolved_at');
        }

        $query->orderBy('created_at', 'ASC')
            ->chunk($chunkSize, $callback);
    }

    /**
     * Busca por substring em content/tags, limitada a uma janela temporal.
     *
     * Duas decisões de performance:
     *
     *  1. A janela `created_at >= now - $days` é obrigatória. Sem ela, o LIKE
     *     `%termo%` varre a tabela inteira; com ela, o índice de created_at
     *     restringe o range antes do LIKE rodar.
     *
     *  2. Case-insensitive SEM LOWER() na coluna: LOWER(content) descartaria
     *     qualquer índice. No pgsql usamos ILIKE (indexável por pg_trgm, ver a
     *     migration de índices); no mysql o LIKE já é case-insensitive nas
     *     collations _ci padrão.
     *
     * Curingas do usuário (% e _) são escapados para não alterarem o padrão nem
     * transformarem a busca num scan mais amplo do que o pedido.
     *
     * @param int $days Janela de busca em dias (a partir de agora)
     * @param int $limit Máximo de linhas retornadas
     */
    public function search(string $term, ?string $type, int $days, int $limit): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        // Escapa os curingas de LIKE; '\' é o caractere de escape declarado abaixo.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = '%' . $escaped . '%';

        $driver = DB::connection($this->connection)->getDriverName();

        $query = $this->scopeDateRange($this->query(), Carbon::now()->subDays($days));

        if ($type !== null && $type !== '') {
            $this->scopeType($query, $type);
        }

        if ($driver === 'pgsql') {
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("content::text ILIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("tags::text ILIKE ? ESCAPE '\\'", [$pattern]);
            });
        } else {
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("content LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("tags LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        return $this->scopeLatestFirst($query)->limit($limit)->get();
    }

    /**
     * Query paginada para exportação (sem expansão de batch).
     */
    public function queryForExport(array $filters = []): \Illuminate\Database\Query\Builder
    {
        $q = $this->query();

        if (!empty($filters['type'])) {
            $this->scopeType($q, $filters['type']);
        }

        if (!empty($filters['user_id'])) {
            $this->scopeTagKey($q, 'user_id', $filters['user_id']);
        }

        if (!empty($filters['batch_id'])) {
            $this->scopeBatch($q, $filters['batch_id']);
        }

        if (!empty($filters['from'])) {
            $from = Carbon::parse($filters['from']);
            $to = !empty($filters['to']) ? Carbon::parse($filters['to']) : null;
            $this->scopeDateRange($q, $from, $to);
        }

        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            $this->scopeTags($q, $filters['tags']);
        }

        return $this->scopeLatestFirst($q);
    }
}
