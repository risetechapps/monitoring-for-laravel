<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PerformanceMonitoringService
{
    /** Chave do cache para métricas */
    private const METRICS_KEY = 'monitoring:performance_metrics';

    /** Duração do cache em minutos */
    private const CACHE_DURATION = 5;

    /**
     * Registra métricas de uma requisição.
     */
    public function recordRequestMetrics(array $data): void
    {
        $metrics = $this->getCurrentMetrics();

        // Atualiza contadores
        $metrics['total_requests']++;
        $duration = (float) ($data['duration'] ?? 0);
        $metrics['request_times'][] = $duration;

        // Conta erros
        if (($data['response_status'] ?? 200) >= 500) {
            $metrics['server_errors']++;
        } elseif (($data['response_status'] ?? 200) >= 400) {
            $metrics['client_errors']++;
        }

        // Memory tracking
        if (config('monitoring.performance.track_memory_peaks', true)) {
            $currentMemory = memory_get_peak_usage(true) / 1024 / 1024; // MB
            $metrics['memory_peaks'][] = $currentMemory;
        }

        // Apdex calculation
        $this->calculateApdex($metrics, $duration);

        $this->saveMetrics($metrics);
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
        return Cache::get(self::METRICS_KEY, [
            'total_requests' => 0,
            'server_errors' => 0,
            'client_errors' => 0,
            'request_times' => [],
            'memory_peaks' => [],
            'apdex_satisfied' => 0,
            'apdex_tolerating' => 0,
            'apdex_frustrated' => 0,
            'started_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Salva métricas no cache.
     */
    private function saveMetrics(array $metrics): void
    {
        Cache::put(self::METRICS_KEY, $metrics, now()->addMinutes(self::CACHE_DURATION));
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

        $total = count($requestTimes);
        $totalRequests = $metrics['total_requests'];

        return [
            'apdex_score' => $this->calculateApdexScore($metrics),
            'throughput_per_minute' => $this->calculateThroughput($metrics),
            'error_rate_percent' => round((($metrics['server_errors'] + $metrics['client_errors']) / $totalRequests) * 100, 2),
            'server_error_rate' => round(($metrics['server_errors'] / $totalRequests) * 100, 2),
            'latency' => [
                'p50' => $this->calculatePercentile($requestTimes, 50),
                'p95' => $this->calculatePercentile($requestTimes, 95),
                'p99' => $this->calculatePercentile($requestTimes, 99),
                'avg' => round(array_sum($requestTimes) / $total, 2),
                'min' => min($requestTimes),
                'max' => max($requestTimes),
            ],
            'memory' => [
                'peak_avg_mb' => !empty($metrics['memory_peaks']) ? round(array_sum($metrics['memory_peaks']) / count($metrics['memory_peaks']), 2) : 0,
                'peak_max_mb' => !empty($metrics['memory_peaks']) ? max($metrics['memory_peaks']) : 0,
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
        $lower = floor($index);
        $upper = ceil($index);
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
