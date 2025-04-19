<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\Monitoring\Features\Device\Device;
use RiseTechApps\Monitoring\Http\Middleware\DisableMonitoringMiddleware;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Repository\MonitoringRepository;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryHttp;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryMysql;
use RiseTechApps\Monitoring\Services\BatchIdService;

class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {

        app('router')->aliasMiddleware('monitoring.disable', DisableMonitoringMiddleware::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Monitoring::start($this->app);

        Event::listen(RequestHandled::class, function () {
            Monitoring::flushAll();
        });

    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'monitoring');

        $this->app->bind(MonitoringRepositoryInterface::class, function ($app) {
            // Passe a conexão desejada aqui
            $driver = config('monitoring.driver');
            $driversConfig = config("monitoring.drivers");

            return match ($driver) {
                'mysql' => new MonitoringRepositoryMysql($driversConfig['mysql']['connection']),
                'http' => new MonitoringRepositoryHttp($driversConfig['http']['url'], $driversConfig['http']['token']),
                default => throw new \Exception("Driver {$driver} não é suportado.")
            };
        });

        $this->app->singleton('monitoring', function () {
            return new Monitoring(app(MonitoringRepositoryInterface::class));
        });

        $this->app->singleton(BatchIdService::class, function ($app) {
            return new BatchIdService();
        });

        $this->app->singleton(Device::class, function ($app) {
            return new Device();
        });

        $this->registerMacros();
    }

    protected function registerMacros(): void
    {

        if(!ResponseFactory::hasMacro('jsonSuccess')){
            ResponseFactory::macro('jsonSuccess', function ($data = []) {
                $response = ['success' => true];
                if (!empty($data)) $response['data'] = $data;
                return response()->json($response);
            });
        }

        if(!ResponseFactory::hasMacro('jsonError')){
            ResponseFactory::macro('jsonError', function ($data = null) {
                $response = ['success' => false];
                if (!is_null($data)) $response['message'] = $data;
                return response()->json($response, 412);
            });
        }

        if(!ResponseFactory::hasMacro('jsonGone')) {
            ResponseFactory::macro('jsonGone', function ($data = null) {
                $response = ['success' => false];
                if (!is_null($data)) $response['message'] = $data;
                return response()->json($response, 410);
            });
        }

        if(!ResponseFactory::hasMacro('jsonNotValidated')) {
            ResponseFactory::macro('jsonNotValidated', function ($message = null, $error = null) {
                $response = ['success' => false];
                if (!is_null($message)) $response['message'] = $message;

                return response()->json($response, 422);
            });
        }
    }
}
