<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Services\ExportService;

class MonitoringController extends Controller
{
    public function __construct(
        protected MonitoringRepositoryInterface $monitoringRepository,
        protected ExportService $exportService
    ) {}

    /**
     * Lista todos os eventos de monitoramento.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $events = $this->monitoringRepository->getAllEvents();

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
}
