<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RiseTechApps\Monitoring\Console\Commands\MonitoringDiagnoseCommand;
use RiseTechApps\Monitoring\Console\Commands\MonitoringExportCommand;
use RiseTechApps\Monitoring\Console\Commands\MonitoringRetentionCommand;
use RiseTechApps\Monitoring\Http\Middleware\DisableMonitoringMiddleware;
use RiseTechApps\Monitoring\Loggly\Loggly;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryDatabase;
use RiseTechApps\Monitoring\Repository\MonitoringRepositorySingle;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\Monitoring\Services\ExportService;
use RiseTechApps\Monitoring\Services\MonitoringQueryService;
use RiseTechApps\Monitoring\Services\RetentionService;
use RiseTechApps\RiseTools\Features\Device\Device;

class MonitoringServiceProvider extends ServiceProvider
{
    /**
     * Comandos internos do framework que não devem inicializar o monitoramento.
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
        // Os próprios comandos do package não precisam de monitoramento
        'monitoring:retention',
        'monitoring:export',
        'monitoring:diagnose',
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

            // Registra os comandos Artisan do package
            $this->commands([
                MonitoringRetentionCommand::class,
                MonitoringExportCommand::class,
                MonitoringDiagnoseCommand::class,
            ]);

            if ($this->isIgnoredArtisanCommand()) {
                return;
            }
        }

        // start() inicializa o repositório, watchers e o register_shutdown_function
        // como safety net (garante flush mesmo que terminating() não dispare).
        Monitoring::start($this->app);

        // Agendamento automático da retenção (configurável via config)
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('monitoring.retention.auto_schedule', false)) {
                $days   = config('monitoring.retention.days', 90);
                $format = config('monitoring.retention.format', 'json');
                $disk   = config('monitoring.retention.disk', 'local');

                $schedule->command(
                    "monitoring:retention --days={$days} --format={$format} --disk={$disk} --force"
                )
                ->dailyAt(config('monitoring.retention.time', '02:00'))
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/monitoring-retention.log'));
            }
        });

        // Resposta enviada ao cliente → flush assíncrono
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

        // Registra o canal de log customizado
        $channels         = Config::get('logging.channels', []);
        $newChannelConfig = [
            'monitoring' => [
                'driver' => 'monitoring',
                'level'  => 'debug',
                'path'   => Config::get('monitoring.drivers.single.path'),
            ],
        ];
        Config::set('logging.channels', array_merge($channels, $newChannelConfig));

        // Binding do repositório conforme driver configurado
        $this->app->bind(MonitoringRepositoryInterface::class, function ($app) {
            $driver        = config('monitoring.driver');
            $driversConfig = config('monitoring.drivers', []);

            return match ($driver) {
                'database' => new MonitoringRepositoryDatabase($driversConfig['database']['connection']),
                'single'   => new MonitoringRepositorySingle(),
                default    => throw new \Exception("Driver {$driver} não é suportado."),
            };
        });

        // MonitoringQueryService — necessário para os serviços de retenção e exportação
        $this->app->bind(MonitoringQueryService::class, function ($app) {
            $connection = config('monitoring.drivers.database.connection', config('database.default'));
            return new MonitoringQueryService($connection);
        });

        // RetentionService
        $this->app->bind(RetentionService::class, function ($app) {
            return new RetentionService($app->make(MonitoringQueryService::class));
        });

        // ExportService
        $this->app->bind(ExportService::class, function ($app) {
            return new ExportService($app->make(MonitoringQueryService::class));
        });

        $this->app->singleton('monitoring', function () {
            return new Monitoring(app(MonitoringRepositoryInterface::class));
        });

        $this->app->singleton(BatchIdService::class, function ($app) {
            return new BatchIdService();
        });

        /**
         * CORREÇÃO BUG #1 (v2.1): Loggly registrado como singleton.
         *
         * Sem este binding, app(Loggly::class) criava uma nova instância
         * descartável a cada chamada helper (logglyError, logglyInfo, etc.).
         * O $logBuffer interno nunca acumulava mais de 1 item e flushLogs()
         * jamais era acionado em contexto HTTP — todos os logs eram perdidos.
         *
         * Com o singleton:
         * - O estado fluente (level, tags, exception...) é isolado por resetState()
         *   ao final de cada log(), garantindo que chamadas consecutivas não vazem estado.
         * - O Monitoring já gerencia o buffer estático e o terminating() hook,
         *   portanto o buffer interno da Loggly foi removido (BUG #3).
         */
        $this->app->singleton(Loggly::class, function ($app) {
            return new Loggly();
        });

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
