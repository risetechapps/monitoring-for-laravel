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
 * Nota: o ciclo ler-modificar-gravar não é atômico. Sob concorrência algumas
 * requisições se perdem — aceitável para métricas amostradas, e o motivo de
 * total_requests ser tratado como aproximação.
 */
class PerformanceMonitoringService
{
    /** Chave do cache para métricas */
    private const string METRICS_KEY = 'monitoring:performance_metrics';

    /** Duração da janela em minutos */
    private const int CACHE_DURATION = 5;

    /** Máximo de amostras de latência retidas (teto de memória da janela) */
    private const int MAX_SAMPLES = 500;

    /**
     * Registra métricas de uma requisição.
     */
    public function recordRequestMetrics(array $data): void
    {
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
        $metrics = Cache::get(self::METRICS_KEY);

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

        Cache::put(self::METRICS_KEY, $metrics, $expiresAt);
    }

    /**
     * Obtém estatísticas calculadas.
     */
    public function getStatistics(): array
    {
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
        Cache::forget(self::METRICS_KEY);
    }

    /**
     * Obtém informações do banco de dados.
     */
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
