<?php

namespace RiseTechApps\Monitoring;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Traits\Record\Record;
use Throwable;

/**
 * Class Monitoring
 *
 * A classe principal para monitoramento de eventos e logs.
 * Gerencia o registro de logs, utiliza buffering para otimizar o desempenho e grava os logs no banco de dados.
 *
 * @package RiseTechApps\Monitoring
 */
class Monitoring
{
    use Record;

    /**
     * Lista de watchers que serão registrados para monitoramento.
     * @var array
     */
    protected static array $watchers = [];

    /**
     * Lista de callbacks para tags que serão aplicadas a cada entrada.
     * @var array
     */
    protected static array $tagUsing = [];

    /**
     * Lista de parametros para serem ocultados dos response.
     * @var array
     */
    public static array $hiddenResponseParameters = [];

    /**
     * Lista de parametros para serem ocultados dos headers.
     * @var array
     */
    public static array $hiddenRequestParameters = [];

    /**
     * Buffer para armazenar temporariamente as entradas de log antes de gravá-las no banco de dados.
     * @var array
     */
    protected static array $buffer = [];

    /**
     * Tamanho do buffer. Quando o buffer atinge este tamanho, os dados são gravados no banco de dados.
     * @var int
     */
    protected static int $bufferSize = 10;

    /**
     * Interface para o repositório de armazenamento de logs.
     * @var MonitoringRepositoryInterface
     */
    protected static MonitoringRepositoryInterface $repository;

    /** Status do monitor se está ativo ou não
     * @var false
     */
    private static bool $enabled = false;

    /**
     * Monitoring constructor.
     *
     * @param MonitoringRepositoryInterface $repository Interface para o repositório de armazenamento de logs.
     */
    public function __construct(MonitoringRepositoryInterface $repository)
    {
        self::$repository = $repository;
        self::$enabled = true;
    }

    /**
     * Desabilita o Monitoring
     */
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
     * @param $app Application Laravel, utilizada para resolução de dependências.
     *
     * @throws BindingResolutionException
     */
    public static function start(Application $app): void
    {

        static::$enabled = (bool)config('monitoring.enabled');

        $repository = $app->make(MonitoringRepositoryInterface::class);
        self::$repository = $repository;

        $configuredBuffer = (int) config('monitoring.buffer_size', self::$bufferSize);
        self::$bufferSize = max(1, $configuredBuffer);

        static::$watchers = [];

        foreach (static::configuredWatchers() as $watcherClass => $options) {
            $watcher = $app->make($watcherClass, [
                'options' => $options,
            ]);

            static::$watchers[] = get_class($watcher);
            $watcher->register($app);
        }
    }

    protected static function configuredWatchers(): array
    {
        $defaults = static::normalizeWatcherConfiguration(static::defaultWatchers());
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
            Watchers\RequestWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore_http_methods' => [
                        'options',
                    ],
                    'ignore_status_codes' => [],
                    'ignore_paths' => [
                        'telescope',
                        'telescope-api',
                    ],
                ],
            ],
            Watchers\EventWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore' => [
                        Watchers\RequestWatcher::class,
                        Watchers\EventWatcher::class,
                    ],
                ],
            ],
            Watchers\ExceptionWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\CommandWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\GateWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\JobWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\QueueWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\ScheduleWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\NotificationWatcher::class => ['enabled' => true, 'options' => []],
            Watchers\MailWatcher::class => ['enabled' => true, 'options' => []],
        ];
    }

    protected static function normalizeWatcherConfiguration(array $watchers): array
    {
        $normalized = [];

        foreach ($watchers as $key => $value) {
            if (is_int($key)) {
                $normalized[$value] = [
                    'enabled' => true,
                    'options' => [],
                ];
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = [
                    'enabled' => $value,
                    'options' => [],
                ];
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
     * Registra uma entrada de log e gerencia o buffer.
     *
     * @param string $type Tipo de log (ex: 'info', 'error').
     * @param IncomingEntry $entry Entrada de log a ser registrada.
     * @throws \Exception Em caso de falha ao gravar a entrada.
     */
    protected static function record(string $type, IncomingEntry $entry): void
    {
        try {
            static::isAuth($entry);
            static::isTags($entry, $type);

            self::$buffer[] = $entry;

            if (count(self::$buffer) >= self::$bufferSize) {
                static::flushBuffer();
            } else if (App::runningInConsole()) {
                static::flushBuffer();
            }
        } catch (\Exception $exception) {
            Log::error('Failed to buffer monitoring entry', [
                'type' => $type,
                'exception' => $exception,
                'entry' => method_exists($entry, 'toArray') ? $entry->toArray() : null,
            ]);
        }
    }

    /**
     * Esvazia o buffer e grava as entradas no banco de dados.
     */
    protected static function flushBuffer(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        try {
                $dataEntry = [];
                foreach (self::$buffer as $entry) {
                    $dataEntry[] = $entry->toArray();
                }
                self::$repository->create($dataEntry);
                self::$buffer = [];

        } catch (\Exception $e) {
            Log::critical('Failed to persist monitoring entries', [
                'exception' => $e,
                'entries' => array_map(function ($entry) {
                    return method_exists($entry, 'toArray') ? $entry->toArray() : $entry;
                }, self::$buffer),
            ]);
        }
    }

    /**
     * Adiciona informações de autenticação à entrada de log.
     *
     * @param IncomingEntry $entry Entrada de log a ser modificada.
     */
    protected static function isAuth(IncomingEntry $entry): void
    {
        try {
            if (Auth::hasResolvedGuards() && Auth::hasUser()) {
                $entry->user(Auth::user());
            }
        } catch (Throwable $e) {
            // Registra exceção em caso de falha ao adicionar informações de autenticação.
        }
    }

    /**
     * Aplica tags à entrada de log com base no tipo.
     *
     * @param IncomingEntry $entry Entrada de log a ser modificada.
     * @param string $type Tipo de log.
     */
    protected static function isTags(IncomingEntry $entry, string $type): void
    {
        $entry->type($type)->tags(Arr::collapse(array_map(function ($tagCallback) use ($entry) {
            return $tagCallback($entry);
        }, static::$tagUsing)));
    }

    /**
     * Adiciona um callback de tag para ser aplicado a cada entrada de log.
     *
     * @param Closure $callback Função de callback para gerar tags.
     * @return static
     */
    public static function tag(Closure $callback): static
    {
        static::$tagUsing[] = $callback;
        return new static(self::$repository);
    }

    /**
     * Garante que o buffer seja esvaziado quando a aplicação é finalizada.
     */
    public static function flushAll(): void
    {
        if (!empty(self::$buffer)) {
            self::flushBuffer();
        }
    }
    /**
     * Registra as rotas do package.
     */
    public static function routes($options = []): void
    {
        Routes::register($options);
    }
}
