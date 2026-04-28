<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Services\ExportService;
use RiseTechApps\Monitoring\Services\PerformanceMonitoringService;

class MonitoringController extends Controller
{
    public function __construct(
        protected MonitoringRepositoryInterface $monitoringRepository,
        protected ExportService $exportService
    ) {}

    /**
     * Lista todos os eventos de monitoramento com filtros avançados.
     *
     * Query params:
     * - type: filtra por tipo (exception, request, job, etc.)
     * - from: data inicial (Y-m-d)
     * - to: data final (Y-m-d)
     * - unresolved: apenas exceções não resolvidas (true/false)
     * - search: busca full-text na mensagem/conteúdo
     * - sort: campo para ordenação (created_at, type)
     * - order: asc ou desc
     * - per_page: itens por página (padrão: 50)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'type'       => $request->input('type'),
                'from'       => $request->input('from'),
                'to'         => $request->input('to'),
                'unresolved' => $request->boolean('unresolved'),
                'search'     => $request->input('search'),
                'sort'       => $request->input('sort', 'created_at'),
                'order'      => $request->input('order', 'desc'),
                'per_page'   => $request->input('per_page', 50),
            ];

            // Remove filtros vazios
            $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');

            // Se não houver filtros, retorna todos
            if (empty($filters)) {
                $events = $this->monitoringRepository->getAllEvents();
            } else {
                $events = $this->monitoringRepository->getEventsWithFilters($filters);
            }

            return response()->jsonSuccess($events);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'index'])
                ->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');
        }
    }

    /**
     * Exibe um evento pelo ID e seus relacionados por batch.
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $event = $this->monitoringRepository->getEventById($request->id);

            if (!$event) {
                return response()->jsonGone('Unable to load data at this time');
            }

            return response()->jsonSuccess($event);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'show'])
                ->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');
        }
    }

    /**
     * Filtra eventos por tipo.
     */
    public function types(Request $request): JsonResponse
    {
        try {
            $event = $this->monitoringRepository->getEventsByTypes($request->type);

            if (!$event) {
                return response()->jsonGone('Unable to load data at this time');
            }

            return response()->jsonSuccess($event);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'type'])
                ->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');
        }
    }

    /**
     * Busca por tags JSON com rastreabilidade por batch_id.
     *
     * Body JSON esperado:
     * {
     *   "tags": { "user_id": "uuid-do-usuario" }
     * }
     *
     * Com expand_batch=true, inclui automaticamente todos os logs
     * que compartilham o mesmo batch_id dos logs encontrados.
     */
    public function tags(Request $request): JsonResponse
    {
        try {
            $tags = $request->input('tags', []);

            if (!is_array($tags)) {
                return response()->json(['message' => 'O campo tags deve ser um objeto JSON.'], 422);
            }

            $events = $this->monitoringRepository->getEventsByTags($tags);

            if ($events->isEmpty()) {
                return response()->jsonGone('Unable to load data at this time');
            }

            return response()->jsonSuccess($events);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'tags'])
                ->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');
        }
    }

    /**
     * Busca todos os logs de um usuário específico (via user_id nas tags)
     * e expande automaticamente para todos os logs do mesmo batch.
     *
     * GET /monitoring/user/{userId}
     */
    public function byUser(Request $request): JsonResponse
    {
        try {
            $userId = $request->route('userId');

            if (empty($userId)) {
                return response()->json(['message' => 'O parâmetro userId é obrigatório.'], 422);
            }

            $events = $this->monitoringRepository->getEventsByUserId($userId);

            if ($events->isEmpty()) {
                return response()->jsonGone('Nenhum log encontrado para este usuário.');
            }

            return response()->jsonSuccess($events);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'byUser'])
                ->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');
        }
    }

    /**
     * Exporta logs filtrados para CSV ou JSON.
     *
     * POST /monitoring/export
     *
     * Body JSON:
     * {
     *   "format": "csv",              // csv | json (padrão: csv)
     *   "type": "request",            // opcional
     *   "user_id": "uuid-do-user",    // opcional
     *   "batch_id": "uuid-do-batch",  // opcional
     *   "from": "2025-01-01",         // opcional
     *   "to": "2025-01-31",           // opcional
     *   "expand_batch": true          // opcional — expande para o batch completo
     * }
     */
    public function export(Request $request): Response|JsonResponse
    {
        try {
            $format = strtolower($request->input('format', 'csv'));

            if (!in_array($format, ['csv', 'json'], true)) {
                return response()->json(['message' => "Formato inválido: {$format}. Use 'csv' ou 'json'."], 422);
            }

            $filters = array_filter([
                'type'         => $request->input('type'),
                'user_id'      => $request->input('user_id'),
                'batch_id'     => $request->input('batch_id'),
                'from'         => $request->input('from'),
                'to'           => $request->input('to'),
                'tags'         => $request->input('tags'),
                'expand_batch' => $request->boolean('expand_batch'),
            ]);

            $result = match ($format) {
                'json'  => $this->exportService->exportJson($filters),
                default => $this->exportService->exportCsv($filters),
            };

            if ($result['count'] === 0) {
                return response()->json(['message' => 'Nenhum registro encontrado com os filtros informados.'], 404);
            }

            return response($result['content'], 200, [
                'Content-Type'        => $result['mime'],
                'Content-Disposition' => "attachment; filename=\"{$result['filename']}\"",
                'X-Total-Records'     => $result['count'],
            ]);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'export'])
                ->log('Unable to export monitoring data');

            return response()->jsonGone('Unable to export monitoring data');
        }
    }

    /**
     * Endpoint de Health Check - verifica saúde da aplicação.
     *
     * GET /monitoring/health
     */
    public function health(Request $request, PerformanceMonitoringService $performanceService): JsonResponse
    {
        try {
            $checks = [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'queue' => $this->checkQueue(),
                'storage' => $this->checkStorage(),
            ];

            // Determina status geral
            $status = 'healthy';
            foreach ($checks as $check => $result) {
                if ($result['status'] === 'error') {
                    $status = 'unhealthy';
                    break;
                }
                if ($result['status'] === 'degraded' && $status === 'healthy') {
                    $status = 'degraded';
                }
            }

            // Adiciona métricas de performance
            $performanceMetrics = $performanceService->getStatistics();

            return response()->json([
                'status' => $status,
                'checks' => $checks,
                'performance' => [
                    'apdex_score' => $performanceMetrics['apdex_score'] ?? 1.0,
                    'throughput_per_minute' => $performanceMetrics['throughput_per_minute'] ?? 0,
                    'error_rate_percent' => $performanceMetrics['error_rate_percent'] ?? 0,
                ],
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'status' => 'unhealthy',
                'error' => 'Unable to determine health status',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }

    /**
     * Verifica conexão com banco de dados.
     */
    private function checkDatabase(): array
    {
        try {
            \DB::connection()->getPdo();
            return ['status' => 'ok', 'response_time_ms' => 0];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Verifica conexão com cache.
     */
    private function checkCache(): array
    {
        try {
            $key = 'monitoring:health_check:' . uniqid();
            \Cache::put($key, 'test', 10);
            $value = \Cache::get($key);
            \Cache::forget($key);

            if ($value === 'test') {
                return ['status' => 'ok', 'driver' => config('cache.default')];
            }

            return ['status' => 'degraded', 'message' => 'Cache value mismatch'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Verifica conexão com fila.
     */
    private function checkQueue(): array
    {
        try {
            $connection = config('queue.default');

            if ($connection === 'sync') {
                return ['status' => 'ok', 'driver' => 'sync'];
            }

            // Tenta obter informações da conexão
            $queue = app('queue');
            $size = method_exists($queue, 'size') ? $queue->size() : 'unknown';

            return [
                'status' => 'ok',
                'driver' => $connection,
                'queue_size' => $size,
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Verifica acesso ao storage.
     */
    private function checkStorage(): array
    {
        try {
            $file = 'monitoring-health-check-' . uniqid() . '.txt';
            $content = 'health-check-' . now()->timestamp;

            \Storage::disk('local')->put($file, $content);
            $read = \Storage::disk('local')->get($file);
            \Storage::disk('local')->delete($file);

            if ($read === $content) {
                return ['status' => 'ok', 'disk' => 'local'];
            }

            return ['status' => 'degraded', 'message' => 'Storage value mismatch'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Compara métricas entre dois períodos.
     *
     * GET /monitoring/compare
     *
     * Query params:
     * - period1: 'last_7_days', 'last_24_hours', etc.
     * - period2: 'previous_7_days', 'previous_24_hours', etc.
     * - type: tipo de evento (opcional)
     */
    public function compare(Request $request): JsonResponse
    {
        try {
            $period1 = $request->input('period1', 'last_7_days');
            $period2 = $request->input('period2', 'previous_7_days');
            $type = $request->input('type');

            $data1 = $this->getPeriodData($period1, $type);
            $data2 = $this->getPeriodData($period2, $type);

            $comparison = [
                'period1' => [
                    'name' => $period1,
                    'data' => $data1,
                ],
                'period2' => [
                    'name' => $period2,
                    'data' => $data2,
                ],
                'changes' => [
                    'total_diff' => $data1['total'] - $data2['total'],
                    'total_percent' => $data2['total'] > 0
                        ? round((($data1['total'] - $data2['total']) / $data2['total']) * 100, 2)
                        : 0,
                ],
            ];

            return response()->jsonSuccess($comparison);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'compare'])
                ->log('Unable to compare periods');

            return response()->jsonGone('Unable to compare periods');
        }
    }

    /**
     * Busca full-text nos eventos.
     *
     * GET /monitoring/search
     *
     * Query params:
     * - q: termo de busca
     * - type: filtrar por tipo (opcional)
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->input('q');

            if (empty($query)) {
                return response()->json(['message' => 'Parâmetro q é obrigatório'], 422);
            }

            $type = $request->input('type');
            $results = $this->monitoringRepository->searchEvents($query, $type);

            return response()->jsonSuccess($results);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'search'])
                ->log('Unable to search events');

            return response()->jsonGone('Unable to search events');
        }
    }

    /**
     * Obtém dados de um período específico.
     */
    private function getPeriodData(string $period, ?string $type = null): array
    {
        $events = match ($period) {
            'last_24_hours', '24h' => $this->monitoringRepository->getLast24Hours(),
            'last_7_days', '7d' => $this->monitoringRepository->getLast7Days(),
            'last_30_days', '30d' => $this->monitoringRepository->getLast30Days(),
            default => collect(),
        };

        if ($type) {
            $events = $events->where('type', $type);
        }

        return [
            'total' => $events->count(),
            'by_type' => $events->groupBy('type')->map(fn($group) => $group->count()),
        ];
    }

    /**
     * Marca um evento como resolvido.
     *
     * POST /monitoring/{id}/resolve
     *
     * Body JSON opcional:
     * {
     *   "resolved_by": "user@example.com"  // ou ID do usuário
     * }
     */
    public function resolve(Request $request): JsonResponse
    {
        try {
            $id = $request->route('id');
            $resolvedBy = $request->input('resolved_by');

            if (empty($id)) {
                return response()->json(['message' => 'O parâmetro id é obrigatório.'], 422);
            }

            $success = $this->monitoringRepository->resolveEvent($id, $resolvedBy);

            if (!$success) {
                return response()->json(['message' => 'Evento não encontrado ou já resolvido.'], 404);
            }

            return response()->json([
                'message' => 'Evento marcado como resolvido.',
                'id' => $id,
            ]);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'resolve'])
                ->log('Unable to resolve monitoring event');

            return response()->jsonGone('Unable to resolve monitoring event');
        }
    }

    /**
     * Remove o status de resolvido de um evento.
     *
     * POST /monitoring/{id}/unresolve
     */
    public function unresolve(Request $request): JsonResponse
    {
        try {
            $id = $request->route('id');

            if (empty($id)) {
                return response()->json(['message' => 'O parâmetro id é obrigatório.'], 422);
            }

            $success = $this->monitoringRepository->unresolveEvent($id);

            if (!$success) {
                return response()->json(['message' => 'Evento não encontrado ou não estava resolvido.'], 404);
            }

            return response()->json([
                'message' => 'Status de resolvido removido do evento.',
                'id' => $id,
            ]);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'unresolve'])
                ->log('Unable to unresolve monitoring event');

            return response()->jsonGone('Unable to unresolve monitoring event');
        }
    }

    /**
     * Marca todos os eventos de uma exceção como resolvidos.
     *
     * POST /monitoring/resolve-exception
     *
     * Body JSON:
     * {
     *   "exception_class": "App\\Exceptions\\MinhaException",
     *   "resolved_by": "user@example.com"
     * }
     */
    public function resolveExceptionType(Request $request): JsonResponse
    {
        try {
            $exceptionClass = $request->input('exception_class');
            $resolvedBy = $request->input('resolved_by');

            if (empty($exceptionClass)) {
                return response()->json(['message' => 'O campo exception_class é obrigatório.'], 422);
            }

            $count = $this->monitoringRepository->resolveExceptionType($exceptionClass, $resolvedBy);

            return response()->json([
                'message' => "{$count} exceções marcadas como resolvidas.",
                'exception_class' => $exceptionClass,
                'count' => $count,
            ]);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'resolveExceptionType'])
                ->log('Unable to resolve exception type');

            return response()->jsonGone('Unable to resolve exception type');
        }
    }

    /**
     * Lista todas as exceções não resolvidas agrupadas por tipo.
     *
     * GET /monitoring/unresolved-exceptions
     */
    public function unresolvedExceptions(Request $request): JsonResponse
    {
        try {
            $exceptions = $this->monitoringRepository->getUnresolvedExceptions();

            return response()->jsonSuccess($exceptions);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'unresolvedExceptions'])
                ->log('Unable to load unresolved exceptions');

            return response()->jsonGone('Unable to load unresolved exceptions');
        }
    }

    /**
     * Retorna timeline cronológico de eventos por tag.
     *
     * GET /monitoring/timeline/{tag}/{value}
     *
     * Exemplos:
     * - GET /monitoring/timeline/pedido_id/123
     * - GET /monitoring/timeline/user_id/uuid-aqui?period=7%20days
     *
     * Query params opcionais:
     * - period: período de busca (default: '24 hours', opções: '1 hour', '6 hours', '12 hours', '24 hours', '7 days', '30 days', '90 days')
     *
     * Retorna eventos agrupados por batch_id em ordem cronológica,
     * mostrando o fluxo completo de uma operação.
     */
    public function timeline(Request $request): JsonResponse
    {
        try {
            $tag = $request->route('tag');
            $value = $request->route('value');
            $period = $request->input('period', '24 hours');

            if (empty($tag) || empty($value)) {
                return response()->json([
                    'message' => 'Os parâmetros tag e value são obrigatórios.',
                ], 422);
            }

            $timeline = $this->monitoringRepository->getTimelineByTag($tag, $value, $period);

            if ($timeline->isEmpty()) {
                return response()->json([
                    'message' => 'Nenhum evento encontrado para esta tag no período especificado.',
                    'tag' => $tag,
                    'value' => $value,
                    'period' => $period,
                ], 404);
            }

            return response()->jsonSuccess([
                'tag' => $tag,
                'value' => $value,
                'period' => $period,
                'total_batches' => $timeline->count(),
                'timeline' => $timeline,
            ]);
        } catch (\Exception $exception) {
            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'timeline'])
                ->log('Unable to load timeline');

            return response()->jsonGone('Unable to load timeline');
        }
    }
}
