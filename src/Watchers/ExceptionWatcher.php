<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Entry\IncomingExceptionEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\ExceptionContext;
use RiseTechApps\Monitoring\Services\ExtractTags;
use Throwable;

/**
 * CORREÇÕES APLICADAS:
 *
 * 1. CIRCUIT BREAKER LOCAL
 *    A versão anterior não tinha proteção contra ser chamado enquanto o próprio
 *    pacote já estava processando um erro. Agora um flag estático garante que
 *    recordException() é no-op quando chamado re-entrante.
 *
 * 2. TRACE TRUNCADO (MAX_TRACE_FRAMES)
 *    Traces de Predis/Redis podem ter 100+ frames. Limitamos a 30 frames e
 *    removemos os argumentos para reduzir drasticamente o uso de memória.
 *
 * 3. REFERÊNCIA À EXCEÇÃO LIBERADA
 *    IncomingExceptionEntry::releaseException() é chamado logo após a construção
 *    para que o objeto Throwable (com sua cadeia $previous) seja elegível para GC
 *    enquanto a entrada ainda está no buffer.
 *
 * 4. CONTEXTO DE ARQUIVO LIMITADO
 *    ExceptionContext::get() lê o arquivo fonte da exceção. Para arquivos de vendor
 *    grandes isso pode ser custoso. Encapsulamos em try/catch para não travar.
 */
class ExceptionWatcher extends Watcher
{
    /** Máximo de frames do stack trace a armazenar */
    private const MAX_TRACE_FRAMES = 30;

    /** Máximo de bytes para a mensagem de exceção */
    private const MAX_MESSAGE_LENGTH = 2000;

    /**
     * Circuit breaker estático — impede recursão caso recordException() seja
     * chamado a partir de código disparado pelo próprio processo de gravação.
     */
    private static bool $handling = false;

    public function register($app): void
    {
        $app['events']->listen(MessageLogged::class, [$this, 'recordException']);
    }

    public function recordException(MessageLogged $event): void
    {
        // ── Circuit breaker ───────────────────────────────────────────────────
        if (self::$handling) {
            return;
        }

        if (!Monitoring::isEnabled()) {
            return;
        }

        if ($this->shouldIgnore($event)) {
            return;
        }

        self::$handling = true;

        try {
            /** @var Throwable $exception */
            $exception = $event->context['exception'];

            // Trace truncado — apenas file/line/class/function, sem argumentos.
            // Limita a MAX_TRACE_FRAMES para evitar payloads enormes de Predis.
            $rawTrace = $exception->getTrace();
            $trace = array_slice(
                array_map(
                    fn($frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])),
                    $rawTrace
                ),
                0,
                self::MAX_TRACE_FRAMES
            );
            unset($rawTrace); // libera memória imediatamente

            // Contexto do arquivo (10 linhas ao redor da exceção).
            // Protegido contra falhas em arquivos de vendor inacessíveis.
            $linePreview = [];
            try {
                $linePreview = ExceptionContext::get($exception);
            } catch (\Throwable) {
                // Silencia — line preview é opcional.
            }

            $entry = IncomingExceptionEntry::make($exception, [
                'class'        => get_class($exception),
                'file'         => $exception->getFile(),
                'line'         => $exception->getLine(),
                'message'      => substr($exception->getMessage(), 0, self::MAX_MESSAGE_LENGTH),
                'context'      => transform(
                    Arr::except($event->context, ['exception', 'monitoring']),
                    fn($ctx) => !empty($ctx) ? $ctx : null
                ),
                'trace'        => $trace,
                'line_preview' => $linePreview,
            ]);

            // Libera a referência ao Throwable o quanto antes para que a cadeia
            // $previous e os frames da call stack possam ser coletados pelo GC.
            $entry->releaseException();

            Monitoring::recordException($entry);

        } catch (\Throwable $e) {
            // Canal seguro: file_put_contents direto, sem passar pelo Log facade.
            // Usar Log::* aqui causaria novo MessageLogged → recursão infinita.
            try {
                $line = sprintf(
                    "[%s] ExceptionWatcher::recordException FAILED: %s in %s:%d\n",
                    date('Y-m-d H:i:s'),
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
                // Silencia.
            }
        } finally {
            self::$handling = false;
        }
    }

    protected function tags(MessageLogged $event): array
    {
        return array_merge(
            ExtractTags::from($event->context['exception']),
            $event->context['monitoring-service'] ?? []
        );
    }

    private function shouldIgnore(MessageLogged $event): bool
    {
        // Verifica se há uma exceção válida
        if (!isset($event->context['exception'])
            || !($event->context['exception'] instanceof Throwable)) {
            return true;
        }

        /** @var Throwable $exception */
        $exception = $event->context['exception'];

        // Verifica se a classe da exceção está na lista de ignorados
        $ignoredExceptions = $this->options['ignore_exceptions'] ?? [];
        foreach ($ignoredExceptions as $ignoredClass) {
            if ($exception instanceof $ignoredClass) {
                return true;
            }
        }

        // Verifica se a mensagem contém textos que devem ser ignorados
        $ignoredMessages = $this->options['ignore_messages_containing'] ?? [];
        $message = strtolower($exception->getMessage());
        foreach ($ignoredMessages as $ignoredText) {
            if (str_contains($message, strtolower($ignoredText))) {
                return true;
            }
        }

        // Verifica se o arquivo da exceção contém caminhos que devem ser ignorados
        $ignoredFiles = $this->options['ignore_files_containing'] ?? [];
        $file = $exception->getFile();
        foreach ($ignoredFiles as $ignoredPath) {
            if (str_contains($file, $ignoredPath)) {
                return true;
            }
        }

        return false;
    }
}
