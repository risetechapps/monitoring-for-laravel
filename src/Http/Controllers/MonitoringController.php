<?php

namespace RiseTechApps\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringController extends Controller
{
    /**
     * Instância do repositório de monitoramento.
     *
     * @var MonitoringRepositoryInterface
     */
    protected $monitoringRepository;

    /**
     * Cria uma nova instância do controlador.
     *
     * @param MonitoringRepositoryInterface $monitoringRepository
     * @return void
     */
    public function __construct(MonitoringRepositoryInterface $monitoringRepository)
    {
        $this->monitoringRepository = $monitoringRepository;
    }

    /**
     * Mostra uma lista dos eventos de monitoramento registrados.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {

            $events = $this->monitoringRepository->getAllEvents();

            return response()->json(['success' => true, 'data' => $events]);
        } catch (\Exception $exception) {
            loggly()->level('error')
                ->exception($exception)->withRequest($request)
                ->performedOn(self::class)->withTags(['action' => 'index'])->log($exception->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Mostra detalhes de um evento de monitoramento específico.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $event = $this->monitoringRepository->getEventById($request->id);

            if (!$event) {
                return response()->json(['success' => false, 'message' => 'Evento não encontrado'], 500);
            }

            return response()->json(['success' => true, 'data' => $event]);
        } catch (\Exception $exception) {
            loggly()->level('error')
                ->exception($exception)->withRequest($request)
                ->performedOn(self::class)->withTags(['action' => 'show'])->log($exception->getMessage());
            return response()->json(['success' => false], 500);
        }
    }

    public function types(Request $request): JsonResponse
    {

        try {
            $event = $this->monitoringRepository->getEventsByTypes($request->id);

            if (!$event) {
                return response()->json(['success' => false, 'message' => 'Evento não encontrado'], 500);
            }

            return response()->json(['success' => true, 'data' => $event]);
        } catch (\Exception $exception) {
            loggly()->level('error')
                ->exception($exception)->withRequest($request)
                ->performedOn(self::class)->withTags(['action' => 'type'])->log($exception->getMessage());
            return response()->json(['success' => false], 500);
        }
    }
}
