<<<<<<< HEAD
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
    protected MonitoringRepositoryInterface $monitoringRepository;

    /**
     * Cria uma nova instância do controlador.
     *
     * @param MonitoringRepositoryInterface $monitoringRepository
     *
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

            return response()->jsonSuccess($events);
        } catch (\Exception $exception) {

            logglyError()->exception($exception)
                ->performedOn(self::class)
                ->withTags(['action' => 'index'])->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');

        }
    }

    /**
     * Mostra detalhes de um evento de monitoramento específico.
     *
     * @param Request $request
     *
     * @return JsonResponse
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
                ->withTags(['action' => 'show'])->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');
        }
    }

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
                ->withTags(['action' => 'type'])->log('Unable to load data at this time');

            return response()->jsonGone('Unable to load data at this time');
        }
    }

    public function tags(Request $request): JsonResponse
    {
        try {
            // força sempre array associativo
            $tags = $request->input('tags', []);

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

}
=======
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
            $event = $this->monitoringRepository->getEventsByTypes($request->type);

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
>>>>>>> origin/main
