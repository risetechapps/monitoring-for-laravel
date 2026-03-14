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
    protected string $table = 'monitoring';
    protected string $connection;

    public function __construct(string $connection)
    {
        $this->connection = $connection;
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
        Carbon $from,
        Carbon $to = null
    ): \Illuminate\Database\Query\Builder {
        $query->where('created_at', '>=', $from->toDateTimeString());

        if ($to) {
            $query->where('created_at', '<=', $to->toDateTimeString());
        }

        return $query;
    }

    /** Scope: filtra por um par chave => valor dentro da coluna JSON `tags` */
    public function scopeTagKey(
        \Illuminate\Database\Query\Builder $query,
        string $key,
        string $value
    ): \Illuminate\Database\Query\Builder {
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
        array $tags
    ): \Illuminate\Database\Query\Builder {
        foreach ($tags as $key => $value) {
            $this->scopeTagKey($query, $key, (string) $value);
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

    // ---------------------------------------------------------------
    // Métodos de consulta públicos
    // ---------------------------------------------------------------

    public function getAll(): Collection
    {
        return $this->scopeLatestFirst($this->query())->get();
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
        return $this->scopeLatestFirst(
            $this->scopeBatch($this->query(), $batchId)
        )->get();
    }

    public function getByType(string $type): Collection
    {
        return $this->scopeLatestFirst(
            $this->scopeType($this->query(), $type)
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
     * @param  array<string, string>  $tags  ex.: ['user_id' => 'uuid-aqui']
     */
    public function getByTagsWithBatchExpansion(array $tags): Collection
    {
        if (empty($tags)) {
            return collect();
        }

        // Passo 1: logs que satisfazem o filtro de tags
        $matchedLogs = $this->scopeLatestFirst(
            $this->scopeTags($this->query(), $tags)
        )->get();

        if ($matchedLogs->isEmpty()) {
            return collect();
        }

        // Passo 2: batch_ids únicos dos logs encontrados
        $batchIds = $matchedLogs->pluck('batch_id')->unique()->values()->toArray();

        // Passo 3: expansão recursiva — todos os logs dos mesmos batches
        return $this->scopeLatestFirst(
            $this->query()->whereIn('batch_id', $batchIds)
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
        return $this->scopeLatestFirst(
            $this->scopeDateRange($this->query(), Carbon::now()->subDays($days))
        )->get();
    }

    /**
     * Retorna IDs dos registros elegíveis para retenção (mais antigos que $days).
     * Feito em chunked para não explodir a memória em tabelas grandes.
     */
    public function getRetentionCandidateIds(int $retentionDays, int $chunkSize = 1000): \Generator
    {
        $this->scopeOlderThan($this->query(), $retentionDays)
            ->orderBy('created_at', 'ASC')
            ->select(['id', 'created_at', 'type'])
            ->chunk($chunkSize, function (Collection $rows) use (&$ids) {
                yield $rows;
            });

        // Usa cursor para não carregar tudo na memória
        return $this->scopeOlderThan($this->query(), $retentionDays)
            ->orderBy('created_at', 'ASC')
            ->cursor();
    }

    /**
     * Retorna registros para backup em lote (chunked por data de criação).
     *
     * @param  int  $retentionDays
     * @param  int  $chunkSize
     * @param  callable  $callback  Recebe Collection de stdClass
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
            $to   = !empty($filters['to']) ? Carbon::parse($filters['to']) : null;
            $this->scopeDateRange($q, $from, $to);
        }

        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            $this->scopeTags($q, $filters['tags']);
        }

        return $this->scopeLatestFirst($q);
    }
}
