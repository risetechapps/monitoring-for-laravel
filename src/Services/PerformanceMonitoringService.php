<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de performance agregadas numa janela deslizante.
 *
 * O estado vive numa única chave de cache lida e reescrita a cada requisição.
 * Por isso ele precisa ter tamanho FIXO — qualquer estrutura que cresça por
 * requisição é copiada, serializada e desserializada em todas as requisições
 * seguintes, e o custo por requisição passa a crescer com o volume acumulado.
 *
 * Duas regras mantêm o tamanho constante:
 *
 *  1. Latências ficam num reservatório de no máximo MAX_SAMPLES amostras
 *     (as mais recentes). Percentis são estimados sobre essa janela.
 *     Contagens, soma, mínimo e máximo são agregados exatos e não usam amostras.
 *
 *  2. A janela expira de fato. saveMetrics() preserva o TTL restante em vez de
 *     renová-lo: sob tráfego contínuo o TTL era reiniciado a cada escrita e a
 *     chave nunca expirava. Passado CACHE_DURATION a janela reinicia do zero.
 *
 * Com o cache em Redis (produção) nada disso vale: cada requisição roda um
 * script Lua que incrementa um hash da janela corrente — uma ida ao Redis,
 * atômica (sem requisição perdida) e de tamanho fixo. Percentis vêm de um
 * histograma por faixas (BUCKETS_MS) em vez de amostras. Os demais stores
 * (array, file, database — dev e testes) usam o caminho abaixo, que é
 * ler-modificar-gravar: sob concorrência algumas requisições se perdem.
 *
 * O store é resolvido FORA do prefixo de tenant (ver cache()): a métrica é do
 * serviço, não do tenant/filial/usuário que fez a requisição.
 */
class PerformanceMonitoringService
{
    /** Chave do cache para métricas */
    private const string METRICS_KEY = 'monitoring:performance_metrics';

    /** Duração da janela em minutos */
    private const int CACHE_DURATION = 5;

    /** Máximo de amostras de latência retidas (teto de memória da janela) */
    private const int MAX_SAMPLES = 500;

    /** Limites superiores (ms) das faixas do histograma de latência (Redis). */
    private const array BUCKETS_MS = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    /** Prefixo do hash da janela no Redis (+ início da janela em epoch). */
    private const string REDIS_KEY = 'monitoring:perf:';

    /**
     * Atualiza o hash da janela de uma vez: contadores, soma, min/max, faixa do
     * histograma, Apdex e memória, e garante o TTL. Atômico no Redis.
     */
    private const string RECORD_SCRIPT = <<<'LUA'
local k = KEYS[1]
local d = tonumber(ARGV[1])
redis.call('HINCRBY', k, 'total_requests', 1)
redis.call('HINCRBYFLOAT', k, 'duration_sum', ARGV[1])
local mn = redis.call('HGET', k, 'duration_min')
if (not mn) or d < tonumber(mn) then redis.call('HSET', k, 'duration_min', ARGV[1]) end
local mx = redis.call('HGET', k, 'duration_max')
if (not mx) or d > tonumber(mx) then redis.call('HSET', k, 'duration_max', ARGV[1]) end
if ARGV[2] ~= '' then redis.call('HINCRBY', k, ARGV[2], 1) end
redis.call('HINCRBY', k, ARGV[3], 1)
redis.call('HINCRBY', k, ARGV[4], 1)
if ARGV[5] ~= '' then
  local m = tonumber(ARGV[5])
  redis.call('HINCRBY', k, 'memory_peak_count', 1)
  redis.call('HINCRBYFLOAT', k, 'memory_peak_sum', ARGV[5])
  local mm = redis.call('HGET', k, 'memory_peak_max')
  if (not mm) or m > tonumber(mm) then redis.call('HSET', k, 'memory_peak_max', ARGV[5]) end
end
redis.call('HSETNX', k, 'started_at', ARGV[6])
redis.call('EXPIRE', k, ARGV[7])
return 1
LUA;

    /**
     * Registra métricas de uma requisição.
     */
    public function recordRequestMetrics(array $data): void
    {
        if ($this->recordInRedis($data)) {
            return;
        }

        $metrics = $this->getCurrentMetrics();

        // Reinicia a janela quando ela já passou do tempo de vida.
        if ($this->windowExpired($metrics)) {
            $metrics = $this->emptyMetrics();
        }

        // Atualiza contadores
        $metrics['total_requests']++;
        $duration = (float) ($data['duration'] ?? 0);

        // Agregados exatos — independem da janela de amostras.
        $metrics['duration_sum'] += $duration;
        $metrics['duration_min'] = $metrics['duration_min'] === null
            ? $duration
            : min($metrics['duration_min'], $duration);
        $metrics['duration_max'] = $metrics['duration_max'] === null
            ? $duration
            : max($metrics['duration_max'], $duration);

        // Amostras para percentis — janela deslizante com teto rígido.
        $metrics['request_times'][] = $duration;
        if (count($metrics['request_times']) > self::MAX_SAMPLES) {
            $metrics['request_times'] = array_slice($metrics['request_times'], -self::MAX_SAMPLES);
        }

        // Conta erros
        if (($data['response_status'] ?? 200) >= 500) {
            $metrics['server_errors']++;
        } elseif (($data['response_status'] ?? 200) >= 400) {
            $metrics['client_errors']++;
        }

        // Memory tracking — só agregados; avg e max não precisam das amostras.
        if (config('monitoring.performance.track_memory_peaks', true)) {
            $currentMemory = memory_get_peak_usage(true) / 1024 / 1024; // MB
            $metrics['memory_peak_count']++;
            $metrics['memory_peak_sum'] += $currentMemory;
            $metrics['memory_peak_max'] = max($metrics['memory_peak_max'], $currentMemory);
        }

        // Apdex calculation
        $this->calculateApdex($metrics, $duration);

        $this->saveMetrics($metrics);
    }

    /**
     * Indica se a janela atual já ultrapassou seu tempo de vida.
     */
    private function windowExpired(array $metrics): bool
    {
        if (empty($metrics['started_at'])) {
            return false;
        }

        try {
            return \Carbon\Carbon::parse($metrics['started_at'])
                ->addMinutes(self::CACHE_DURATION)
                ->isPast();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Calcula Apdex score.
     */
    private function calculateApdex(array &$metrics, float|int $durationMs): void
    {
        $threshold = config('monitoring.performance.apdex.threshold', 500);
        $tolerable = config('monitoring.performance.apdex.tolerable', 2000);

        if ($durationMs <= $threshold) {
            $metrics['apdex_satisfied']++;
        } elseif ($durationMs <= $tolerable) {
            $metrics['apdex_tolerating']++;
        } else {
            $metrics['apdex_frustrated']++;
        }
    }

    /**
     * Obtém métricas atuais do cache.
     */
    public function getCurrentMetrics(): array
    {
        $metrics = $this->cache()->get(self::METRICS_KEY);

        if (!is_array($metrics)) {
            return $this->emptyMetrics();
        }

        // Tolera estado gravado por uma versão anterior do serviço.
        return array_replace($this->emptyMetrics(), $metrics);
    }

    /**
     * Estado inicial de uma janela.
     */
    private function emptyMetrics(): array
    {
        return [
            'total_requests' => 0,
            'server_errors' => 0,
            'client_errors' => 0,
            'request_times' => [],
            'duration_sum' => 0.0,
            'duration_min' => null,
            'duration_max' => null,
            'memory_peak_count' => 0,
            'memory_peak_sum' => 0.0,
            'memory_peak_max' => 0.0,
            'apdex_satisfied' => 0,
            'apdex_tolerating' => 0,
            'apdex_frustrated' => 0,
            'started_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Salva métricas no cache preservando o TTL restante da janela.
     *
     * Renovar o TTL a cada escrita fazia a chave nunca expirar enquanto
     * houvesse tráfego, e a janela deixava de ser uma janela.
     */
    private function saveMetrics(array $metrics): void
    {
        $expiresAt = now()->addMinutes(self::CACHE_DURATION);

        try {
            $windowEnd = \Carbon\Carbon::parse($metrics['started_at'])
                ->addMinutes(self::CACHE_DURATION);

            if ($windowEnd->isFuture()) {
                $expiresAt = $windowEnd;
            }
        } catch (\Throwable) {
            // Mantém o TTL padrão.
        }

        $this->cache()->put(self::METRICS_KEY, $metrics, $expiresAt);
    }

    /**
     * Obtém estatísticas calculadas.
     */
    public function getStatistics(): array
    {
        $redisStatistics = $this->statisticsFromRedis();

        if ($redisStatistics !== null) {
            return $redisStatistics;
        }

        $metrics = $this->getCurrentMetrics();

        if ($metrics['total_requests'] === 0) {
            return $this->getEmptyStatistics();
        }

        $requestTimes = $metrics['request_times'];
        sort($requestTimes);

        $totalRequests = $metrics['total_requests'];
        $memoryCount   = $metrics['memory_peak_count'];

        return [
            'apdex_score' => $this->calculateApdexScore($metrics),
            'throughput_per_minute' => $this->calculateThroughput($metrics),
            'error_rate_percent' => round((($metrics['server_errors'] + $metrics['client_errors']) / $totalRequests) * 100, 2),
            'server_error_rate' => round(($metrics['server_errors'] / $totalRequests) * 100, 2),
            'latency' => [
                // Percentis vêm da janela de amostras; avg/min/max são exatos.
                'p50' => $this->calculatePercentile($requestTimes, 50),
                'p95' => $this->calculatePercentile($requestTimes, 95),
                'p99' => $this->calculatePercentile($requestTimes, 99),
                'avg' => round($metrics['duration_sum'] / $totalRequests, 2),
                'min' => $metrics['duration_min'] ?? 0,
                'max' => $metrics['duration_max'] ?? 0,
                'sampled_requests' => count($requestTimes),
            ],
            'memory' => [
                'peak_avg_mb' => $memoryCount > 0 ? round($metrics['memory_peak_sum'] / $memoryCount, 2) : 0,
                'peak_max_mb' => $memoryCount > 0 ? round($metrics['memory_peak_max'], 2) : 0,
            ],
            'period' => [
                'started_at' => $metrics['started_at'],
                'ended_at' => now()->toDateTimeString(),
                'total_requests' => $totalRequests,
            ],
        ];
    }

    /**
     * Calcula score Apdex.
     */
    private function calculateApdexScore(array $metrics): float
    {
        $satisfied = $metrics['apdex_satisfied'];
        $tolerating = $metrics['apdex_tolerating'];
        $frustrated = $metrics['apdex_frustrated'];
        $total = $satisfied + $tolerating + $frustrated;

        if ($total === 0) {
            return 1.0;
        }

        return round(($satisfied + ($tolerating / 2)) / $total, 2);
    }

    /**
     * Calcula throughput (req/min).
     */
    private function calculateThroughput(array $metrics): float
    {
        $started = \Carbon\Carbon::parse($metrics['started_at']);
        $minutes = max(1, now()->diffInMinutes($started));

        return round($metrics['total_requests'] / $minutes, 2);
    }

    /**
     * Calcula percentil.
     */
    private function calculatePercentile(array $sortedValues, int $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0;
        }

        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        if ($upper >= $count) {
            return $sortedValues[$lower];
        }

        return round($sortedValues[$lower] * (1 - $weight) + $sortedValues[$upper] * $weight, 2);
    }

    /**
     * Estatísticas vazias.
     */
    private function getEmptyStatistics(): array
    {
        return [
            'apdex_score' => 1.0,
            'throughput_per_minute' => 0,
            'error_rate_percent' => 0,
            'server_error_rate' => 0,
            'latency' => [
                'p50' => 0,
                'p95' => 0,
                'p99' => 0,
                'avg' => 0,
                'min' => 0,
                'max' => 0,
                'sampled_requests' => 0,
            ],
            'memory' => [
                'peak_avg_mb' => 0,
                'peak_max_mb' => 0,
            ],
            'period' => [
                'started_at' => now()->toDateTimeString(),
                'ended_at' => now()->toDateTimeString(),
                'total_requests' => 0,
            ],
        ];
    }

    /**
     * Limpa métricas.
     */
    public function resetMetrics(): void
    {
        if ($redis = $this->redis()) {
            try {
                $redis[0]->del($this->windowKey($redis[1]));
            } catch (\Throwable) {
            }
        }

        $this->cache()->forget(self::METRICS_KEY);
    }

    /**
     * Obtém informações do banco de dados.
     */
    // ---------------------------------------------------------------
    // Caminho Redis (atômico)
    // ---------------------------------------------------------------

    /**
     * Store do cache das métricas, sem o prefixo de tenant.
     *
     * Num app com tenancy o Cache facade prefixa a chave com tenant/filial/
     * usuário: cada usuário tinha a "sua" janela e o /health lia uma que quase
     * nada continha. `resolve()` monta o store direto da config.
     */
    protected function cache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('monitoring.cache_store') ?: config('cache.default');

        try {
            return app('cache')->resolve($store);
        } catch (\Throwable) {
            return Cache::store();
        }
    }

    /** @return array{0: \Illuminate\Redis\Connections\Connection, 1: string}|null */
    private function redis(): ?array
    {
        try {
            $store = $this->cache()->getStore();

            if ($store instanceof \Illuminate\Cache\RedisStore) {
                return [$store->connection(), $store->getPrefix()];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function windowKey(string $prefix, ?int $windowStart = null): string
    {
        $size = self::CACHE_DURATION * 60;
        $windowStart ??= intdiv(time(), $size) * $size;

        return $prefix . self::REDIS_KEY . $windowStart;
    }

    private function recordInRedis(array $data): bool
    {
        $redis = $this->redis();

        if ($redis === null) {
            return false;
        }

        [$connection, $prefix] = $redis;

        $duration = max(0.0, (float) ($data['duration'] ?? 0));
        $status   = (int) ($data['response_status'] ?? 200);

        $statusField = $status >= 500 ? 'server_errors' : ($status >= 400 ? 'client_errors' : '');

        $threshold = (float) config('monitoring.performance.apdex.threshold', 500);
        $tolerable = (float) config('monitoring.performance.apdex.tolerable', 2000);
        $apdexField = $duration <= $threshold ? 'apdex_satisfied' : ($duration <= $tolerable ? 'apdex_tolerating' : 'apdex_frustrated');

        $bucket = count(self::BUCKETS_MS);
        foreach (self::BUCKETS_MS as $index => $limit) {
            if ($duration <= $limit) {
                $bucket = $index;
                break;
            }
        }

        $memory = config('monitoring.performance.track_memory_peaks', true)
            ? (string) round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            : '';

        try {
            // EVAL do Redis com o script FIXO acima; os valores vão como ARGV
            // (dados, nunca código).
            $connection->eval(
                self::RECORD_SCRIPT,
                1,
                $this->windowKey($prefix),
                (string) $duration,
                $statusField,
                $apdexField,
                'bucket_' . $bucket,
                $memory,
                now()->toDateTimeString(),
                (string) (self::CACHE_DURATION * 60 * 2),
            );

            return true;
        } catch (\Throwable) {
            // Redis fora: cai no caminho genérico (que também tolera falha).
            return false;
        }
    }

    /** Estatísticas da janela corrente no Redis; null = Redis indisponível. */
    private function statisticsFromRedis(): ?array
    {
        $redis = $this->redis();

        if ($redis === null) {
            return null;
        }

        try {
            $raw = $redis[0]->hgetall($this->windowKey($redis[1]));
        } catch (\Throwable) {
            return null;
        }

        $total = (int) ($raw['total_requests'] ?? 0);

        if ($total === 0) {
            return $this->getEmptyStatistics();
        }

        $metrics = [
            'total_requests'    => $total,
            'server_errors'     => (int) ($raw['server_errors'] ?? 0),
            'client_errors'     => (int) ($raw['client_errors'] ?? 0),
            'duration_sum'      => (float) ($raw['duration_sum'] ?? 0),
            'duration_min'      => (float) ($raw['duration_min'] ?? 0),
            'duration_max'      => (float) ($raw['duration_max'] ?? 0),
            'memory_peak_count' => (int) ($raw['memory_peak_count'] ?? 0),
            'memory_peak_sum'   => (float) ($raw['memory_peak_sum'] ?? 0),
            'memory_peak_max'   => (float) ($raw['memory_peak_max'] ?? 0),
            'apdex_satisfied'   => (int) ($raw['apdex_satisfied'] ?? 0),
            'apdex_tolerating'  => (int) ($raw['apdex_tolerating'] ?? 0),
            'apdex_frustrated'  => (int) ($raw['apdex_frustrated'] ?? 0),
            'started_at'        => $raw['started_at'] ?? now()->toDateTimeString(),
        ];

        $buckets = [];
        foreach (array_keys(self::BUCKETS_MS) as $index) {
            $buckets[$index] = (int) ($raw['bucket_' . $index] ?? 0);
        }
        $buckets[count(self::BUCKETS_MS)] = (int) ($raw['bucket_' . count(self::BUCKETS_MS)] ?? 0);

        $memoryCount = $metrics['memory_peak_count'];

        return [
            'apdex_score' => $this->calculateApdexScore($metrics),
            'throughput_per_minute' => $this->calculateThroughput($metrics),
            'error_rate_percent' => round((($metrics['server_errors'] + $metrics['client_errors']) / $total) * 100, 2),
            'server_error_rate' => round(($metrics['server_errors'] / $total) * 100, 2),
            'latency' => [
                // Limite superior da faixa do histograma onde cai o percentil.
                'p50' => $this->bucketPercentile($buckets, $total, 50, $metrics['duration_max']),
                'p95' => $this->bucketPercentile($buckets, $total, 95, $metrics['duration_max']),
                'p99' => $this->bucketPercentile($buckets, $total, 99, $metrics['duration_max']),
                'avg' => round($metrics['duration_sum'] / $total, 2),
                'min' => $metrics['duration_min'],
                'max' => $metrics['duration_max'],
                'sampled_requests' => $total,
            ],
            'memory' => [
                'peak_avg_mb' => $memoryCount > 0 ? round($metrics['memory_peak_sum'] / $memoryCount, 2) : 0,
                'peak_max_mb' => $memoryCount > 0 ? round($metrics['memory_peak_max'], 2) : 0,
            ],
            'period' => [
                'started_at' => $metrics['started_at'],
                'ended_at' => now()->toDateTimeString(),
                'total_requests' => $total,
            ],
        ];
    }

    private function bucketPercentile(array $buckets, int $total, int $percentile, float $max): float
    {
        $target = (int) ceil($total * $percentile / 100);
        $seen = 0;

        foreach ($buckets as $index => $count) {
            $seen += $count;

            if ($seen >= $target) {
                // Nunca acima do máximo real observado.
                return (float) min(self::BUCKETS_MS[$index] ?? $max, $max);
            }
        }

        return $max;
    }

    public function getDatabaseMetrics(): array
    {
        if (!config('monitoring.performance.track_db_connections', true)) {
            return [];
        }

        try {
            $connections = [];
            foreach (config('database.connections') as $name => $config) {
                $connections[$name] = [
                    'status' => $this->checkConnection($name),
                ];
            }

            return $connections;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Verifica se uma conexão está funcionando.
     */
    private function checkConnection(string $connection): string
    {
        try {
            DB::connection($connection)->getPdo();
            return 'connected';
        } catch (\Exception $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
