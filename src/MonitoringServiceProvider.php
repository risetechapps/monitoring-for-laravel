<<<<<<< HEAD
<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\Monitoring\Http\Middleware\DisableMonitoringMiddleware;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryHttp;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryMysql;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryPgsql;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\RiseTools\Features\Device\Device;

class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
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
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'monitoring');

        $this->app->bind(MonitoringRepositoryInterface::class, function ($app) {
            $driver = config('monitoring.driver');
            $driversConfig = config('monitoring.drivers', []);

            return match ($driver) {
                'mysql' => new MonitoringRepositoryMysql($driversConfig['mysql']['connection']),
                'pgsql' => new MonitoringRepositoryPgsql($driversConfig['pgsql']['connection']),
                'http' => new MonitoringRepositoryHttp($driversConfig['http'] ?? []),
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
=======
<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\Monitoring\Http\Middleware\DisableMonitoringMiddleware;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryHttp;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryMysql;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryPgsql;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\RiseTools\Features\Device\Device;

class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {

        app('router')->aliasMiddleware('monitoring.disable', DisableMonitoringMiddleware::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');


        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('monitoring.php'),
            ], 'config');
        }

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
            $driver = config('monitoring.driver');
            $driversConfig = config('monitoring.drivers', []);

            return match ($driver) {
                'mysql' => new MonitoringRepositoryMysql($driversConfig['mysql']['connection'] ?? env('DB_CONNECTION', 'mysql')),
                'pgsql' => new MonitoringRepositoryPgsql($driversConfig['pgsql']['connection'] ?? env('DB_CONNECTION', 'pgsql')),
                'http' => new MonitoringRepositoryHttp($driversConfig['http'] ?? []),
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
>>>>>>> origin/main
