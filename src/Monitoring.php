<?php

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
 * Class Monitoring
 *
 * Classe principal. Gerencia buffer de entradas e despacha para o repositório.
 *
 * IMPORTANTE: esta classe NUNCA deve chamar Log::*, loggly() ou qualquer outro
 * canal que dispare eventos Laravel (MessageLogged, etc.), pois isso causa
 * recursão infinita → OOM via Predis/Redis.
 * Erros internos são escritos diretamente em arquivo com file_put_contents().
 */
class Monitoring
{
    use Record;

    protected static array $watchers = [];
    protected static array $tagUsing = [];

    public static array $hiddenResponseParameters = [];
    public static array $hiddenRequestParameters = [];

    protected static array $buffer = [];
    protected static int $bufferSize = 10;
    protected static MonitoringRepositoryInterface $repository;

    private static bool $enabled = false;

    /**
     * CIRCUIT BREAKER — impede que o próprio pacote dispare novos registros
     * enquanto já está processando uma entrada. Sem isso, qualquer erro interno
     * que chame Log::* dispara MessageLogged → ExceptionWatcher → record() → loop.
     */
    private static bool $isRecording = false;

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
     * Inicializa o sistema de monitoramento e registra os watchers.
     *
     * @throws BindingResolutionException
     */
    public static function start(Application $app): void
    {
        static::$enabled = (bool) config('monitoring.enabled');

        $repository = $app->make(MonitoringRepositoryInterface::class);
        self::$repository = $repository;

        $configuredBuffer = (int) config('monitoring.buffer_size', self::$bufferSize);
        self::$bufferSize = max(1, $configuredBuffer);

        static::$watchers = [];

        foreach (static::configuredWatchers() as $watcherClass => $options) {
            $watcher = $app->make($watcherClass, ['options' => $options]);
            static::$watchers[] = get_class($watcher);
            $watcher->register($app);
        }
    }

    protected static function configuredWatchers(): array
    {
        $defaults  = static::normalizeWatcherConfiguration(static::defaultWatchers());
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
                        'Laravel\Horizon\Events\*',
                        'Laravel\Telescope\Events\*',
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
     * O circuit breaker ($isRecording) garante que erros internos do pacote
     * não disparem novos registros → previne o loop infinito que causava OOM.
     */
    protected static function record(string $type, IncomingEntry $entry): void
    {
        // ── CIRCUIT BREAKER ──────────────────────────────────────────────────
        // Se já estamos dentro do record(), ignoramos silenciosamente.
        // Isso impede que Log::* chamado em um catch interno cause recursão.
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
            // NUNCA usa Log::* aqui — causaria recursão via MessageLogged.
            // Usa file_put_contents() direto para garantir saída segura.
            static::writeInternalError('record', $type, $e);
        } finally {
            self::$isRecording = false;
        }
    }

    /**
     * Esvazia o buffer e envia as entradas ao repositório.
     */
    protected static function flushBuffer(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        // Captura o buffer atual e limpa ANTES do envio para liberar memória.
        $entries = self::$buffer;
        self::$buffer = [];

        try {
            $dataEntry = [];
            foreach ($entries as $entry) {
                $dataEntry[] = $entry->toArray();
            }

            self::$repository->create($dataEntry);
        } catch (\Throwable $e) {
            // NUNCA usa Log::* aqui — causaria recursão via MessageLogged.
            static::writeInternalError('flushBuffer', 'batch', $e);
        } finally {
            // Força liberação do array (referências PHP)
            unset($entries);
        }
    }

    /**
     * Escreve erros internos diretamente em arquivo, sem passar pelo Laravel Log.
     * Isso é o único canal seguro para diagnosticar falhas dentro do pacote.
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
            // Silencia — não há canal mais seguro disponível.
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
