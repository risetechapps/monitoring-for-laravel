<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RiseTechApps\Monitoring\Http\Middleware\DisableMonitoringMiddleware;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryHttp;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryMysql;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryPgsql;
use RiseTechApps\Monitoring\Repository\MonitoringRepositorySingle;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\RiseTools\Features\Device\Device;

class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {

        $router = $this->app['router'];

        $router->aliasMiddleware('monitoring.disable', DisableMonitoringMiddleware::class);

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');


        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('monitoring.php'),
            ], 'config');
        }

        Monitoring::start($this->app);

        Event::listen(RequestHandled::class, function () {
            Monitoring::flushAll();
        });

        Log::extend('monitoring', function ($app, array $config) {

            // O caminho pode ser dinâmico ou fixo no storage/logs
            $path = storage_path('logs/monitoring.log');

            $logger = new Logger('monitoring');

            // O StreamHandler precisa do caminho e do nível de log
            $logger->pushHandler(new StreamHandler(
                $path,
                'debug'
            ));

            return $logger;
        });
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'monitoring');


        $channels = Config::get('logging.channels', []);

        $newChannelConfig = [
            'monitoring' => [
                'driver' => 'monitoring',
                'level' => "debug",
                'path' => Config::get('monitoring.drivers.single.path')
            ],
        ];

        Config::set('logging.channels', array_merge($channels, $newChannelConfig));

        $this->app->bind(MonitoringRepositoryInterface::class, function ($app) {
            $driver = config('monitoring.driver');
            $driversConfig = config('monitoring.drivers', []);

            return match ($driver) {
                'mysql' => new MonitoringRepositoryMysql($driversConfig['mysql']['connection']),
                'pgsql' => new MonitoringRepositoryPgsql($driversConfig['pgsql']['connection']),
                'http' => new MonitoringRepositoryHttp($driversConfig['http'] ?? []),
                'single' => new MonitoringRepositorySingle(),
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
    }
}
