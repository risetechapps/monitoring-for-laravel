<?php

namespace RiseTechApps\Monitoring;

use Closure;
use Illuminate\Console\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * Buffer para armazenar temporariamente as entradas de log antes de gravá-las no banco de dados.
     * @var array
     */
    protected static array $buffer = [];

    /**
     * Tamanho do buffer. Quando o buffer atinge este tamanho, os dados são gravados no banco de dados.
     * @var int
     */
    protected static int $bufferSize = 5;

    /**
     * Interface para o repositório de armazenamento de logs.
     * @var MonitoringRepositoryInterface
     */
    protected static MonitoringRepositoryInterface $repository;

    /** Status do monitor se está ativo ou não
     * @var false
     */
    private static bool $enabled = true;

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
     */
    public static function start($app): void
    {
        $repository = $app->make(MonitoringRepositoryInterface::class);
        self::$repository = $repository;

        foreach (static::listWatchers() as $item) {
            $watcher = $app->make($item, [
                'options' => static::getOptions($item)
            ]);

            static::$watchers[] = get_class($watcher);
            $watcher->register($app);
        }
    }

    /**
     * Retorna a lista de watchers que serão registrados.
     *
     * @return array Lista de classes de watchers.
     */
    protected static function listWatchers(): array
    {
        return [
            Watchers\RequestWatcher::class,
            Watchers\EventWatcher::class,
            Watchers\ExceptionWatcher::class,
            Watchers\CommandWatcher::class,
            Watchers\GateWatcher::class,
            Watchers\JobWatcher::class,
            Watchers\QueueWatcher::class,
            Watchers\ScheduleWatcher::class,
            Watchers\NotificationWatcher::class,
        ];
    }

    /**
     * Retorna as opções específicas para um watcher.
     *
     * @param $event type de watcher.
     * @return array Opções para o watcher.
     */
    protected static function getOptions($event): array
    {
        $options = [
            Watchers\RequestWatcher::class => [
                'ignore_http_methods' => [
                    'options'
                ]
            ],
            Watchers\EventWatcher::class => [
                'ignore' => [
                    Watchers\RequestWatcher::class,
                    Watchers\EventWatcher::class
                ]
            ],
        ];

        return $options[$event] ?? [];
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
            }
        } catch (\Exception $exception) {
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
            DB::transaction(function () {
                $dataEntry = [];
                foreach (self::$buffer as $entry) {
                    $dataEntry[] = $entry->toArray();
                }
                self::$repository->create($dataEntry);
                self::$buffer = [];
            });

        } catch (\Exception $e) {

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
    public static function tag(Closure $callback)
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
}
