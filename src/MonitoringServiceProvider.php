<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RiseTechApps\Monitoring\Http\Middleware\DisableMonitoringMiddleware;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryDatabase;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryHttp;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryPgsql;
use RiseTechApps\Monitoring\Repository\MonitoringRepositorySingle;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\RiseTools\Features\Device\Device;

class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Comandos artisan internos que não devem inicializar o monitoramento.
     * Evita estouro de memória durante composer update/install e deploys.
     */
    private const IGNORED_COMMANDS = [
        'package:discover',
        'vendor:publish',
        'config:cache',
        'config:clear',
        'route:cache',
        'route:clear',
        'view:cache',
        'view:clear',
        'optimize',
        'optimize:clear',
        'event:cache',
        'event:clear',
        'storage:link',
        'migrate',
        'migrate:fresh',
        'migrate:rollback',
        'migrate:run',
        'migrate:status',
        'db:seed',
        'horizon:publish',
        'horizon:terminate',
        'telescope:publish',
    ];

    /**
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

            // Aborta o boot para comandos internos do framework
            if ($this->isIgnoredArtisanCommand()) {
                return;
            }
        }

        Monitoring::start($this->app);

        // terminating() dispara APÓS a resposta ser enviada ao cliente
        // Isso garante que o monitoramento nunca atrasa o response do usuário
        $this->app->terminating(function () {
            Monitoring::flushAll();
        });

        Log::extend('monitoring', function ($app, array $config) {
            $path   = storage_path('logs/monitoring.log');
            $logger = new Logger('monitoring');
            $logger->pushHandler(new StreamHandler($path, 'debug'));
            return $logger;
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'monitoring');

        $channels         = Config::get('logging.channels', []);
        $newChannelConfig = [
            'monitoring' => [
                'driver' => 'monitoring',
                'level'  => 'debug',
                'path'   => Config::get('monitoring.drivers.single.path'),
            ],
        ];
        Config::set('logging.channels', array_merge($channels, $newChannelConfig));

        $this->app->bind(MonitoringRepositoryInterface::class, function ($app) {
            $driver        = config('monitoring.driver');
            $driversConfig = config('monitoring.drivers', []);

            return match ($driver) {
                'database'  => new MonitoringRepositoryDatabase($driversConfig['database']['connection']),
                'http'   => new MonitoringRepositoryHttp($driversConfig['http'] ?? []),
                'single' => new MonitoringRepositorySingle(),
                default  => throw new \Exception("Driver {$driver} não é suportado."),
            };
        });

        $this->app->singleton('monitoring', function () {
            return new Monitoring(app(MonitoringRepositoryInterface::class));
        });

        $this->app->singleton(BatchIdService::class, function ($app) {
            return new BatchIdService();
        });

        // Singleton do Device garante que a instância seja reutilizada
        $this->app->singleton(Device::class, function ($app) {
            return new Device();
        });
    }

    private function isIgnoredArtisanCommand(): bool
    {
        $command = $_SERVER['argv'][1] ?? '';
        return in_array($command, self::IGNORED_COMMANDS, true);
    }
}
