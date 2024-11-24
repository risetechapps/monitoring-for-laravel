<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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
    }
}
