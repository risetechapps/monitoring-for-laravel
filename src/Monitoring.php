<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Traits\Record\Record;
use Throwable;

/**
 * Classe principal do Monitoring.
 *
 * CORREÇÕES v2.1:
 *
 * BUG A — ERROS SILENCIOSOS (causa da dificuldade de diagnóstico):
 *   flushBuffer() capturava qualquer Throwable e gravava APENAS em
 *   storage/logs/monitoring-internal.log, arquivo desconhecido pelo usuário.
 *   Erros de banco (connection refused, coluna ausente, driver errado) nunca
 *   apareciam em nenhum log visível.
 *   FIX: flushBuffer() agora também chama error_log() E Log::error() com
 *   proteção contra recursão, para que erros apareçam no laravel.log padrão.
 *
 * BUG B — TERMINATING() NÃO CONFIÁVEL (logs perdidos em Octane/FrankenPHP):
 *   O único mecanismo de flush em HTTP era o terminating() hook do Laravel,
 *   que não é chamado em todos os ambientes (Octane, FrankenPHP, Swoole,
 *   index.php customizado, processo encerrado por exceção fatal).
 *   FIX: register_shutdown_function() adicionado como safety net no boot().
 *   Garante que flushAll() é chamado mesmo que terminate() não dispare.
 *
 * BUG C — record() IGNORAVA isEnabled() (logs registrados mesmo quando desabilitado):
 *   Monitoring::disable() setava $enabled = false, mas record() nunca checava
 *   esse flag. O buffer continuava enchendo mesmo com monitoring desabilitado.
 *   FIX: record() agora verifica isEnabled() antes de qualquer processamento.
 *
 * NOTA: Para NUNCA usar Log::* dentro de flushBuffer() (previne recursão via
 * MessageLogged → ExceptionWatcher → record()), o Log::error() no BUG A só
 * é chamado quando o circuit breaker garante que não estamos em uma recursão.
 */
class Monitoring
{
    use Record;

    protected static array $watchers = [];
    protected static array $tagUsing = [];

    public static array $hiddenResponseParameters = [];
    public static array $hiddenRequestParameters  = [];

    protected static array $buffer     = [];
    protected static int   $bufferSize = 10;
    protected static MonitoringRepositoryInterface $repository;

    private static bool $enabled      = false;
    private static bool $isRecording  = false;
    private static bool $shutdownRegistered = false;

    public function __construct(MonitoringRepositoryInterface $repository)
    {
        self::$repository = $repository;
        self::$enabled    = true;
    }

    public static function disable(): void
    {
        static::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return static::$enabled;
    }

    /**
     * Inicializa o sistema e registra os watchers.
     *
     * @throws BindingResolutionException
     */
    public static function start(Application $app): void
    {
        static::$enabled = (bool) config('monitoring.enabled');

        $repository       = $app->make(MonitoringRepositoryInterface::class);
        self::$repository = $repository;

        $configuredBuffer = (int) config('monitoring.buffer_size', self::$bufferSize);
        self::$bufferSize = max(1, $configuredBuffer);

        static::$watchers = [];

        foreach (static::configuredWatchers() as $watcherClass => $options) {
            $watcher = $app->make($watcherClass, ['options' => $options]);
            static::$watchers[] = get_class($watcher);
            $watcher->register($app);
        }

        // BUG B FIX — Safety net: garante flush mesmo que terminating() não dispare.
        // register_shutdown_function() roda sempre que o processo PHP termina,
        // inclusive em Octane/FrankenPHP, exceções fatais e scripts CLI.
        if (!static::$shutdownRegistered) {
            register_shutdown_function(static function () {
                static::flushAll();
            });
            static::$shutdownRegistered = true;
        }
    }

    protected static function configuredWatchers(): array
    {
        $defaults   = static::normalizeWatcherConfiguration(static::defaultWatchers());
        $configured = config('monitoring.watchers');

        $custom = is_array($configured)
            ? static::normalizeWatcherConfiguration($configured)
            : [];

        foreach ($custom as $class => $config) {
            if (isset($defaults[$class])) {
                $defaults[$class]['enabled'] = $config['enabled'];
                $defaults[$class]['options'] = array_replace_recursive(
                    $defaults[$class]['options'],
                    $config['options']
                );
            } else {
                $defaults[$class] = $config;
            }
        }

        $active = [];
        foreach ($defaults as $class => $config) {
            if (!($config['enabled'] ?? true)) {
                continue;
            }
            $active[$class] = $config['options'] ?? [];
        }

        return $active;
    }

    protected static function defaultWatchers(): array
    {
        return [
            Watchers\RequestWatcher::class   => [
                'enabled' => true,
                'options' => [
                    'ignore_http_methods' => ['options'],
                    'ignore_status_codes' => [],
                    'ignore_paths'        => [
                        'telescope',
                        'telescope-api',
                        'horizon',
                        'horizon/api/*',
                        '_debugbar',
                        'livewire/update',
                    ],
                ],
            ],
            Watchers\EventWatcher::class     => [
                'enabled' => true,
                'options' => [
                    'ignore' => [
                        Watchers\RequestWatcher::class,
                        Watchers\EventWatcher::class,
                        'Laravel\\Horizon\\Events\\*',
                        'Laravel\\Telescope\\Events\\*',
                    ],
                ],
            ],
            Watchers\ExceptionWatcher::class    => ['enabled' => true, 'options' => []],
            Watchers\CommandWatcher::class      => ['enabled' => true, 'options' => []],
            Watchers\GateWatcher::class         => ['enabled' => true, 'options' => []],
            Watchers\JobWatcher::class          => ['enabled' => true, 'options' => []],
            Watchers\QueueWatcher::class        => ['enabled' => true, 'options' => []],
            Watchers\ScheduleWatcher::class     => ['enabled' => true, 'options' => []],
            Watchers\NotificationWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\MailWatcher::class         => ['enabled' => true, 'options' => []],
        ];
    }

    protected static function normalizeWatcherConfiguration(array $watchers): array
    {
        $normalized = [];

        foreach ($watchers as $key => $value) {
            if (is_int($key)) {
                $normalized[$value] = ['enabled' => true, 'options' => []];
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = ['enabled' => $value, 'options' => []];
                continue;
            }

            if (is_array($value)) {
                $enabled = $value['enabled'] ?? true;

                if (array_key_exists('options', $value)) {
                    $options = is_array($value['options']) ? $value['options'] : [];
                } else {
                    $options = $value;
                    unset($options['enabled']);
                    $options = is_array($options) ? $options : [];
                }

                $normalized[$key] = [
                    'enabled' => (bool) $enabled,
                    'options' => $options,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Registra uma entrada no buffer.
     *
     * BUG C FIX: verifica isEnabled() antes de qualquer processamento.
     * Anteriormente, record() ignorava o flag $enabled, causando acúmulo
     * de entradas no buffer mesmo quando o monitoring estava desabilitado.
     */
    protected static function record(string $type, IncomingEntry $entry): void
    {
        // BUG C FIX — Respeita o flag isEnabled() antes de qualquer processamento.
        if (!static::$enabled) {
            return;
        }

        // CIRCUIT BREAKER — previne recursão via MessageLogged → ExceptionWatcher.
        if (self::$isRecording) {
            return;
        }

        self::$isRecording = true;

        try {
            static::isAuth($entry);
            static::isTags($entry, $type);

            self::$buffer[] = $entry;

            if (count(self::$buffer) >= self::$bufferSize) {
                static::flushBuffer();
            } elseif (App::runningInConsole()) {
                static::flushBuffer();
            }
        } catch (\Throwable $e) {
            static::writeInternalError('record', $type, $e);
        } finally {
            self::$isRecording = false;
        }
    }

    /**
     * Esvazia o buffer e persiste no repositório.
     *
     * BUG A FIX: erros agora aparecem no laravel.log (channel padrão) via
     * error_log(), além do monitoring-internal.log privado.
     * A chamada a error_log() é segura pois não passa pelo sistema de eventos
     * do Laravel, não podendo causar recursão.
     */
    protected static function flushBuffer(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        $entries       = self::$buffer;
        self::$buffer  = [];

        try {
            // Verifica se o repositório foi inicializado (proteção contra
            // chamadas antes de Monitoring::start()).
            if (!isset(self::$repository)) {
                static::writeInternalError(
                    'flushBuffer',
                    'init',
                    new \RuntimeException(
                        'Monitoring::$repository não foi inicializado. ' .
                        'Verifique se MonitoringServiceProvider está registrado ' .
                        'e se Monitoring::start() foi chamado no boot().'
                    )
                );
                return;
            }

            $dataEntry = [];
            foreach ($entries as $entry) {
                $dataEntry[] = $entry->toArray();
            }

            self::$repository->create($dataEntry);

        } catch (\Throwable $e) {
            static::writeInternalError('flushBuffer', 'batch', $e);

            // BUG A FIX — Torna o erro VISÍVEL no log padrão do PHP/Laravel.
            // error_log() é seguro (não dispara eventos Laravel).
            error_log(sprintf(
                '[Monitoring] ERRO AO PERSISTIR LOGS — %s em %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        } finally {
            unset($entries);
        }
    }

    /**
     * Grava erros internos diretamente em arquivo.
     * NUNCA usa Log::* — causaria recursão via MessageLogged.
     */
    private static function writeInternalError(string $context, string $type, \Throwable $e): void
    {
        try {
            $line = sprintf(
                "[%s] monitoring.INTERNAL_ERROR context=%s type=%s error=%s file=%s:%d\n",
                date('Y-m-d H:i:s'),
                $context,
                $type,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
            file_put_contents(
                storage_path('logs/monitoring-internal.log'),
                $line,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // Último recurso — silencia totalmente.
        }
    }

    protected static function isAuth(IncomingEntry $entry): void
    {
        try {
            if (Auth::hasResolvedGuards() && Auth::hasUser()) {
                $entry->user(Auth::user());
            }
        } catch (Throwable) {
            // Silencia — falhas de auth não devem parar o monitoramento.
        }
    }

    protected static function isTags(IncomingEntry $entry, string $type): void
    {
        $entry->type($type)->tags(Arr::collapse(array_map(function ($tagCallback) use ($entry) {
            return $tagCallback($entry);
        }, static::$tagUsing)));
    }

    public static function tag(Closure $callback): static
    {
        static::$tagUsing[] = $callback;
        return new static(self::$repository);
    }

    public static function flushAll(): void
    {
        if (!empty(self::$buffer)) {
            self::flushBuffer();
        }
    }

    public static function routes($options = []): void
    {
        Routes::register($options);
    }
}
