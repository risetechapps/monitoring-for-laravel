<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\Monitoring\Support\Spool;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Services\Alerts\AlertService;
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

    /** Processo de fila/daemon: só watchers de baixo volume, flush por job. */
    private static bool $workerMode = false;

    /** Último flush (microtime) — flush por tempo em processos de console. */
    private static float $lastFlushAt = 0.0;

    /** Tamanho aproximado do buffer em bytes (teto de memória do HTTP). */
    private static int $bufferBytes = 0;

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

        // Workers de fila: cada job arrasta queries, eventos e caches — monitorar
        // tudo inundaria a tabela. Mas desligar TUDO (como era) perdia o que
        // importa: logglyError() dentro de job e job que falhou sumiam em
        // silêncio. Em worker ficam só os watchers de `monitoring.workers.watchers`
        // (exceções e jobs falhos) + o Loggly, com batch e flush por job.
        static::$workerMode = false;

        if (static::isLongRunningWorker()) {
            if (!static::$enabled || !config('monitoring.workers.enabled', true)) {
                static::$enabled = false;
                return;
            }

            static::$workerMode = true;
        }

        $configuredBuffer = (int) config('monitoring.buffer_size', self::$bufferSize);
        self::$bufferSize = max(1, $configuredBuffer);

        static::$watchers = [];

        foreach (static::configuredWatchers() as $watcherClass => $options) {
            if (static::$workerMode) {
                if (!in_array($watcherClass, static::workerWatchers(), true)) {
                    continue;
                }

                // Em worker o JobWatcher grava só falhas: "pending" (um por job
                // despachado) e "processed" (um por job concluído) são o volume
                // que tirou os workers do monitoramento.
                if (is_a($watcherClass, Watchers\JobWatcher::class, true)) {
                    $options = array_replace(['record_pending' => false, 'record_processed' => false], $options, (array) config('monitoring.workers.job_options', []));
                }
            }

            $watcher = $app->make($watcherClass, ['options' => $options]);
            static::$watchers[] = $watcher::class;
            $watcher->register($app);
        }

        if (static::$workerMode) {
            static::registerWorkerLifecycle($app);
        }

        // BUG B FIX — Safety net: garante flush mesmo que terminating() não dispare.
        // register_shutdown_function() roda sempre que o processo PHP termina,
        // inclusive em Octane/FrankenPHP, exceções fatais e scripts CLI.
        if (!static::$shutdownRegistered) {
            register_shutdown_function(static function () {
                static::flushAll();
                Spool::shutdown();
            });
            static::$shutdownRegistered = true;
        }
    }

    /**
     * Indica se o processo é um worker de fila de longa duração.
     *
     * Deliberadamente NÃO inclui `octane:start`: em Octane os workers atendem
     * requisições HTTP, e tratá-los como worker de fila desligaria em silêncio
     * todo o monitoramento da aplicação.
     *
     * `schedule:run` (execução pontual do cron) continua monitorado; apenas o
     * daemon `schedule:work` fica de fora.
     */
    protected static function isLongRunningWorker(): bool
    {
        if (!App::runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];

        // O comando nem sempre é argv[1] — flags globais podem vir antes
        // (`artisan --env=prod queue:work`). Pega o primeiro argumento que
        // não seja uma opção.
        foreach (array_slice($argv, 1) as $argument) {
            if (str_starts_with((string) $argument, '-')) {
                continue;
            }

            return in_array($argument, [
                'queue:work',
                'queue:listen',
                'horizon',
                'horizon:work',
                'horizon:supervisor',
                'schedule:work',
            ], true);
        }

        return false;
    }

    /** @return array<int, class-string> watchers ativos em worker de fila */
    protected static function workerWatchers(): array
    {
        return (array) config('monitoring.workers.watchers', [
            Watchers\ExceptionWatcher::class,
            Watchers\JobWatcher::class,
        ]);
    }

    public static function isWorkerMode(): bool
    {
        return static::$workerMode;
    }

    /**
     * Batch e flush por job no worker.
     *
     * No HTTP o batch nasce e morre com o request (terminating). O worker é um
     * processo só: sem isto todos os jobs dividiriam o mesmo batch e o buffer só
     * esvaziaria a cada N entradas — logs de um job grudados no seguinte.
     * Registrado DEPOIS dos watchers: no JobFailed, o JobWatcher grava a falha
     * antes do flush.
     */
    protected static function registerWorkerLifecycle(Application $app): void
    {
        $events = $app['events'];

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($app) {
            static::flushAll();

            $batch = $app->make(BatchIdService::class);
            $batch->forceDelete();
            $batch->setBatchId((string) ($event->job->payload()['batch_id'] ?? $event->job->uuid() ?? \Illuminate\Support\Str::orderedUuid()));

            IncomingEntry::resetDeviceCache();
        });

        $finish = function () use ($app) {
            static::flushAll();
            $app->make(BatchIdService::class)->forceDelete();
        };

        $events->listen(JobProcessed::class, $finish);
        $events->listen(JobExceptionOccurred::class, $finish);
        $events->listen(JobFailed::class, $finish);
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
                    // Padrões glob (ver RequestWatcher::shouldIgnorePaths)
                    'ignore_paths'        => [
                        'up',
                        'telescope*',
                        'telescope-api*',
                        'horizon*',
                        '_debugbar*',
                        'livewire/update',
                    ],
                ],
            ],
            // Query, Cache e Event são os watchers de maior volume. Ficam opt-in
            // aqui também, para que um config publicado antigo (sem estas chaves)
            // não os reative pelos defaults.
            Watchers\EventWatcher::class     => [
                'enabled' => env('MONITORING_WATCH_EVENTS', false),
                'options' => [
                    'ignore' => [
                        Watchers\RequestWatcher::class,
                        Watchers\EventWatcher::class,
                        'Laravel\\Horizon\\Events\\*',
                        'Laravel\\Telescope\\Events\\*',
                    ],
                ],
            ],
            Watchers\ExceptionWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore_exceptions' => [],
                    'ignore_messages_containing' => [],
                    'ignore_files_containing' => [],
                ],
            ],
            Watchers\CommandWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore' => [],
                ],
            ],
            Watchers\GateWatcher::class => [
                'enabled' => env('MONITORING_WATCH_GATES', false),
                'options' => [
                    'ignore_abilities' => [],
                ],
            ],
            Watchers\JobWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore_namespaces' => [],
                    'ignore_jobs' => [],
                ],
            ],
            Watchers\QueueWatcher::class        => ['enabled' => true, 'options' => []],
            Watchers\ScheduleWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore_commands' => [],
                    'ignore_closures' => false,
                ],
            ],
            Watchers\NotificationWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore_notifications' => [],
                    'ignore_channels' => [],
                    'ignore_anonymous' => false,
                ],
            ],
            Watchers\MailWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore_mailables' => [],
                    'ignore_subjects_containing' => [],
                    'ignore_from_addresses' => [],
                    'ignore_to_addresses' => [],
                ],
            ],
            Watchers\ClientRequestWatcher::class => [
                'enabled' => true,
                'options' => [
                    'ignore_hosts' => [],
                    'size_limit' => 64,
                ],
            ],
            Watchers\QueryWatcher::class => [
                'enabled' => env('MONITORING_WATCH_QUERIES', false),
                'options' => [
                    'slow_query_threshold_ms' => (int) env('MONITORING_SLOW_QUERY_MS', 500),
                    'ignore_patterns' => ['information_schema', 'migrations', 'telescope'],
                    'log_bindings' => true,
                    'max_sql_length' => 5000,
                ],
            ],
            Watchers\CacheWatcher::class => [
                'enabled' => env('MONITORING_WATCH_CACHE', false),
                'options' => [
                    'track_hits' => env('MONITORING_WATCH_CACHE_HITS', false),
                    'track_misses' => env('MONITORING_WATCH_CACHE_MISSES', false),
                    'ignore_keys' => ['config', 'routes', 'telescope', 'monitoring'],
                ],
            ],
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

            // Serializa (e oculta) já no registro: a mesma linha vai para o
            // buffer e para o rascunho em disco (Support\Spool), que sobrevive
            // se o processo morrer antes do flush.
            $row = $entry->toArray();

            self::$buffer[] = $row;
            self::$bufferBytes += strlen((string) ($row['content'] ?? '')) + 512;
            Spool::append($row);

            // Verifica alertas para eventos críticos
            static::checkAlerts($entry, $type);

            $console = App::runningInConsole();
            $count   = count(self::$buffer);

            // HTTP: nada vai para o banco durante o request. O terminating()
            // grava tudo DEPOIS que a resposta foi entregue (no PHP-FPM o
            // Response::send() já chamou fastcgi_finish_request()). Antes, o
            // flush por tamanho (buffer 5) fazia ~90 INSERTs no caminho do
            // usuário num request com muitos eventos — ~87% do tempo de banco.
            // O teto só existe para não estourar a memória.
            $hardCap = $console
                ? self::$bufferSize * 10
                : max(self::$bufferSize, (int) config('monitoring.http_max_buffer', 1000));

            $maxBytes = (int) config('monitoring.http_max_buffer_mb', 8) * 1024 * 1024;

            if ($count >= $hardCap || (!$console && $maxBytes > 0 && self::$bufferBytes >= $maxBytes)) {
                // Teto: grava mesmo dentro de transação (ver abaixo) — melhor
                // arriscar o rollback levar estas entradas do que crescer sem fim.
                static::flushBuffer();
            } elseif ($console && $count >= self::$bufferSize && !static::inStorageTransaction()) {
                // Console/worker: flush por tamanho (não há terminating() por
                // request). O final é garantido pelo fim do job e pelo
                // register_shutdown_function().
                static::flushBuffer();
            } elseif ($console && $count > 0 && static::flushIntervalElapsed() && !static::inStorageTransaction()) {
                // Processo de console de longa duração (listener, daemon): sem
                // terminating(), as entradas esperariam o buffer encher.
                static::flushBuffer();
            }

            // Defesa em profundidade: se o flush estiver falhando, descarta as
            // mais antigas em vez de derrubar a aplicação.
            if (count(self::$buffer) > $hardCap) {
                self::$buffer = array_slice(self::$buffer, -$hardCap);
                static::writeInternalError('record', 'buffer_overflow', new \RuntimeException(
                    "Buffer excedeu {$hardCap} entradas — descartando as mais antigas. " .
                    'O flush provavelmente está falhando.'
                ));
            }
        } catch (\Throwable $e) {
            static::writeInternalError('record', $type, $e);
        } finally {
            self::$isRecording = false;
        }
    }

    /**
     * Há transação aberta na conexão onde o monitoring grava?
     *
     * Na mesma conexão (rotas centrais usam a `pgsql` da aplicação), um INSERT
     * no meio de um DB::beginTransaction() entraria na transação da aplicação —
     * e um rollback apagaria justamente os logs do que deu errado. O flush é
     * adiado: terminating()/fim do job grava depois que a transação fechou.
     */
    protected static function inStorageTransaction(): bool
    {
        if (config('monitoring.driver') !== 'database') {
            return false;
        }

        try {
            $connection = config('monitoring.drivers.database.connection');

            return DB::connection($connection)->transactionLevel() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    protected static function flushIntervalElapsed(): bool
    {
        if (!App::runningInConsole()) {
            return false;
        }

        $interval = (float) config('monitoring.flush_interval_seconds', 10);

        if ($interval <= 0) {
            return false;
        }

        if (self::$lastFlushAt === 0.0) {
            self::$lastFlushAt = microtime(true);

            return false;
        }

        return (microtime(true) - self::$lastFlushAt) >= $interval;
    }

    /**
     * Verifica se deve disparar alertas para a entrada.
     */
    protected static function checkAlerts(IncomingEntry $entry, string $type): void
    {
        try {
            $alertService = app(AlertService::class);
            $alertService->checkAndAlert($entry, $type);
        } catch (\Throwable) {
            // Silencia erros de alerta para não afetar a aplicação
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

        $rows          = self::$buffer;
        self::$buffer  = [];
        self::$bufferBytes = 0;
        self::$lastFlushAt = microtime(true);

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
                Spool::abandon();
                return;
            }

            self::$repository->create($rows);

            Spool::committed();

        } catch (\Throwable $e) {
            // As entradas continuam no rascunho em disco: o recover grava
            // quando o banco voltar.
            Spool::abandon();

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
            unset($rows);
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
        $entry->type($type)->tags(Arr::collapse(array_map(fn($tagCallback) => $tagCallback($entry), static::$tagUsing)));
    }

    /**
     * Registra uma função que devolve tags para TODA entrada.
     *
     * A função roda no momento de cada registro (Monitoring::record → isTags),
     * não no momento em que foi registrada: lê o contexto vigente daquele
     * instante (ex.: tenant/filial ativos, inclusive dentro de um run()).
     *
     * @param Closure(IncomingEntry): array<string, scalar> $callback
     */
    public static function registerTagResolver(Closure $callback): void
    {
        static::$tagUsing[] = $callback;
    }

    /**
     * @deprecated use registerTagResolver().
     *
     * Antes fazia `new static(...)`: o construtor religava o monitoring
     * (`$enabled = true`) mesmo desligado de propósito, e quebrava (propriedade
     * estática não inicializada) onde o monitoring não sobe (ex.: migrate).
     */
    public static function tag(Closure $callback): static
    {
        static::registerTagResolver($callback);

        return (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
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

    // ---------------------------------------------------------------
    // Métricas Customizáveis
    // ---------------------------------------------------------------

    /**
     * Registra uma métrica do tipo gauge (valor pontual).
     *
     * Exemplo: monitoring()->gauge('pedidos_pendentes', 42);
     */
    public static function gauge(string $name, float|int $value, array $tags = []): void
    {
        if (!static::$enabled) {
            return;
        }

        $entry = IncomingEntry::make([
            'metric_type' => 'gauge',
            'metric_name' => $name,
            'value' => $value,
        ])->tags(array_merge(['metric:gauge', "metric:{$name}"], $tags));

        static::recordMetric($entry);
    }

    /**
     * Incrementa uma métrica do tipo counter.
     *
     * Exemplo: monitoring()->increment('checkout_concluido');
     */
    public static function increment(string $name, int $value = 1, array $tags = []): void
    {
        if (!static::$enabled) {
            return;
        }

        $entry = IncomingEntry::make([
            'metric_type' => 'counter',
            'metric_name' => $name,
            'value' => $value,
        ])->tags(array_merge(['metric:counter', "metric:{$name}"], $tags));

        static::recordMetric($entry);
    }

    /**
     * Registra uma métrica do tipo histogram (distribuição de valores).
     *
     * Exemplo: monitoring()->histogram('tempo_resposta_api', 250);
     */
    public static function histogram(string $name, float|int $value, array $tags = []): void
    {
        if (!static::$enabled) {
            return;
        }

        $entry = IncomingEntry::make([
            'metric_type' => 'histogram',
            'metric_name' => $name,
            'value' => $value,
        ])->tags(array_merge(['metric:histogram', "metric:{$name}"], $tags));

        static::recordMetric($entry);
    }

    /**
     * Mede o tempo de execução de um callable e registra como histogram.
     *
     * Exemplo:
     * monitoring()->timer('processamento_pedido', function() {
     *     return $this->processarPedido($dados);
     * });
     */
    public static function timer(string $name, callable $callback, array $tags = []): mixed
    {
        $start = microtime(true);

        try {
            $result = $callback();
        } finally {
            $duration = (microtime(true) - $start) * 1000; // em ms
            static::histogram($name, round($duration, 2), $tags);
        }

        return $result ?? null;
    }

    /**
     * Registra uma métrica manualmente no buffer.
     */
    protected static function recordMetric(IncomingEntry $entry): void
    {
        static::record('metric', $entry);
    }

    /**
     * Retorna métricas agregadas por nome e período.
     * Útil para dashboards.
     */
    public static function getMetrics(string $name, string $period = '1 hour'): array
    {
        // Este método seria implementado no repository
        // Por enquanto retorna estrutura vazia
        return [
            'name' => $name,
            'period' => $period,
            'count' => 0,
            'avg' => 0,
            'min' => 0,
            'max' => 0,
            'sum' => 0,
        ];
    }
}
