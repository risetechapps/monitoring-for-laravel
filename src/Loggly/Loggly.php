<?php

namespace RiseTechApps\Monitoring\Loggly;

use DateTime;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use Throwable;

/**
 * CORREÇÕES APLICADAS:
 *
 * 1. DOUBLE-SEND ELIMINADO
 *    A versão anterior chamava sendToOutput($entry) IMEDIATAMENTE após adicionar
 *    ao buffer, E também dentro de flushLogs() quando o buffer enchia.
 *    Resultado: cada entrada era enviada 2× (na 5ª entrada, o flush reenviava
 *    todas as 5 e depois a 5ª ainda era enviada mais uma vez).
 *    Fix: sendToOutput() é chamado APENAS dentro de flushLogs(). O log() só
 *    acumula no buffer e decide quando liberar.
 *
 * 2. TRACE DE EXCEÇÃO TRUNCADO
 *    A versão anterior armazenava $exception->getTrace() completo. Para aplicações
 *    com Predis, um trace pode ter 80-150 frames, cada um com args completos.
 *    Fix: trace limitado a MAX_TRACE_FRAMES (20) e getTrace() usa
 *    DEBUG_BACKTRACE_IGNORE_ARGS para não capturar argumentos dos frames.
 *
 * 3. BUFFER FLUSH IMEDIATO NO CONSOLE
 *    Em jobs/commands o processo termina sem passar pelo terminating() hook.
 *    Fix: flush imediato quando runningInConsole().
 */
class Loggly
{
    /** Número máximo de frames de stack trace armazenados */
    private const MAX_TRACE_FRAMES = 20;

    /** Tamanho máximo (bytes) de cada propriedade individual */
    private const MAX_PROPERTY_SIZE = 8192; // 8 KB

    private array $logLevels = [
        'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'model', 'debug',
    ];

    const EMERGENCY = 0;
    const ALERT     = 1;
    const CRITICAL  = 2;
    const ERROR     = 3;
    const WARNING   = 4;
    const NOTICE    = 5;
    const INFO      = 6;
    const MODEL     = 7;
    const DEBUG     = 8;

    private string   $level       = 'info';
    private array    $properties  = [];
    private ?string  $performed   = null;
    private ?string  $performedID = null;
    private ?array   $exception   = null;
    private array    $context     = [];
    private array    $tags        = [];
    private ?DateTime $timestamp  = null;
    private ?array   $request     = null;
    private ?array   $response    = null;
    private string   $output      = 'loggly';
    private bool     $encryptLogs = false;

    /** Buffer de entradas aguardando envio */
    private array $logBuffer  = [];
    private int   $bufferSize = 5;

    // ─── Fluent API ──────────────────────────────────────────────────────────

    public function level(int|string $level): static
    {
        if (is_int($level) && isset($this->logLevels[$level])) {
            $this->level = $this->logLevels[$level];
        } elseif (is_string($level) && in_array($level, $this->logLevels, true)) {
            $this->level = $level;
        }
        return $this;
    }

    public function withProperties(array $properties = []): static
    {
        $this->properties = array_merge($this->properties, $properties);
        return $this;
    }

    public function performedOn(mixed $performed): static
    {
        if ($performed instanceof Model) {
            $this->performed   = get_class($performed);
            $this->performedID = (string) $performed->getKey();
        } else {
            $this->performed = is_object($performed) ? get_class($performed) : (string) $performed;
        }
        return $this;
    }

    /**
     * Captura informações de exceção de forma enxuta.
     * O trace é truncado e os ARGUMENTOS são suprimidos para economizar memória.
     */
    public function exception(Throwable|string $exception): static
    {
        if ($exception instanceof Throwable) {
            // Usa getTrace() sem argumentos para reduzir o tamanho do payload.
            // array_slice() limita o número de frames.
            $rawTrace = $exception->getTrace();
            $trace = array_slice(
                array_map(fn($frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])), $rawTrace),
                0,
                self::MAX_TRACE_FRAMES
            );

            $this->exception = [
                'message' => substr($exception->getMessage(), 0, 1000),
                'line'    => $exception->getLine(),
                'file'    => $exception->getFile(),
                'code'    => $exception->getCode(),
                'trace'   => $trace,
            ];
        } else {
            $this->exception = ['message' => substr((string) $exception, 0, 1000)];
        }

        return $this;
    }

    public function withContext(array $context): static
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function withTags(array $tags): static
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function at(DateTime $timestamp): static
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function withRequest(mixed $request): static
    {
        $this->request = is_array($request) ? $request : (array) $request;
        return $this;
    }

    public function withResponse(mixed $response): static
    {
        $this->response = is_string($response)
            ? $response
            : json_decode(json_encode($response, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        return $this;
    }

    public function to(string $output): static
    {
        $this->output = $output;
        return $this;
    }

    public function encrypt(): static
    {
        $this->encryptLogs = true;
        return $this;
    }

    public function setLevelFromConfig(): static
    {
        return $this->level('info');
    }

    // ─── Logging ─────────────────────────────────────────────────────────────

    /**
     * Registra a mensagem de log.
     *
     * CORREÇÃO: A versão anterior chamava sendToOutput() imediatamente E também
     * dentro de flushLogs(), enviando cada entry pelo menos 2×.
     * Agora o entry é apenas adicionado ao buffer; o envio real ocorre SOMENTE
     * dentro de flushLogs() / flushImmediately().
     */
    public function log(string $message): void
    {
        $this->withProperties($this->resolveCaller());

        $entry = IncomingEntry::make([
            'level'        => $this->level,
            'message'      => $this->encryptLogs ? encrypt($message) : $message,
            'properties'   => $this->sanitizeProperties($this->properties),
            'context'      => $this->context,
            'tags'         => $this->tags,
            'performed'    => $this->performed,
            'performed_id' => $this->performedID,
            'exception'    => $this->exception,
            'timestamp'    => $this->timestamp ? $this->timestamp->format('Y-m-d H:i:s') : now()->toDateTimeString(),
            'request'      => $this->request,
            'response'     => $this->response,
        ]);

        $this->logBuffer[] = $entry;

        // Flush quando buffer atinge o limite OU quando estamos no console
        // (jobs/commands não passam pelo terminating() hook do Laravel).
        if (count($this->logBuffer) >= $this->bufferSize || app()->runningInConsole()) {
            $this->flushLogs();
        }

        // Reseta estado fluente para a próxima chamada encadeada.
        $this->resetState();
    }

    /**
     * Envia todos os logs do buffer ao destino configurado.
     * CORRIGIDO: cada entry é enviado exatamente UMA vez.
     */
    private function flushLogs(): void
    {
        if (empty($this->logBuffer)) {
            return;
        }

        $batch = $this->logBuffer;
        $this->logBuffer = [];

        foreach ($batch as $entry) {
            $this->sendToOutput($entry);
        }

        unset($batch);
    }

    /**
     * Despacha para o canal correto.
     */
    private function sendToOutput(IncomingEntry $entry): void
    {
        switch ($this->output) {
            case 'loggly':
                Monitoring::recordLoggly($entry);
                break;

            case 'file':
                // Canal seguro — não passa pelo sistema de eventos do Laravel.
                // Usado pelos catch blocks dos Watchers para evitar recursão.
                try {
                    $line = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    file_put_contents(
                        storage_path('logs/error-monitoring.log'),
                        $line . PHP_EOL,
                        FILE_APPEND | LOCK_EX
                    );
                } catch (\Throwable) {
                    // Silencia — último recurso.
                }
                break;

            default:
                throw new InvalidArgumentException("Unsupported Loggly output: {$this->output}");
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Sanitiza propriedades, truncando valores muito grandes.
     */
    private function sanitizeProperties(array $props): array
    {
        $result = [];
        foreach ($props as $key => $value) {
            if (is_string($value) && strlen($value) > self::MAX_PROPERTY_SIZE) {
                $result[$key] = substr($value, 0, self::MAX_PROPERTY_SIZE) . '…[truncated]';
            } elseif (is_array($value) || is_object($value)) {
                $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($json && strlen($json) > self::MAX_PROPERTY_SIZE) {
                    $result[$key] = ['_purged' => 'Value too large (' . round(strlen($json) / 1024, 1) . 'KB)'];
                } else {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function resolveCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        $traceData = [
            'file'     => 'unknown',
            'line'     => 0,
            'function' => '{closure}',
            'class'    => 'anonymous',
        ];

        if (isset($trace[1])) {
            $traceData['file']  = $trace[1]['file'] ?? 'unknown';
            $traceData['line']  = $trace[1]['line'] ?? 0;
            $traceData['class'] = $this->fileToClass($traceData['file']);
        }

        if ($traceData['class'] === 'anonymous') {
            return $traceData;
        }

        foreach ($trace as $frame) {
            if (isset($frame['class']) && $traceData['class'] === $frame['class']) {
                $traceData['function'] = $frame['function'];
                return $traceData;
            }
        }

        return $traceData;
    }

    public function fileToClass(string $filePath): string
    {
        $filePath = str_replace('/', '\\', $filePath);
        $pos = stripos($filePath, '\\app\\');
        if ($pos === false) {
            return 'anonymous';
        }
        $relativePath = substr($filePath, $pos + 5);
        $relativePath = preg_replace('/\.php$/i', '', $relativePath);
        return 'App\\' . str_replace('/', '\\', $relativePath);
    }

    /**
     * Reseta o estado fluente após cada log() para garantir isolamento entre chamadas.
     */
    private function resetState(): void
    {
        $this->level       = 'info';
        $this->properties  = [];
        $this->performed   = null;
        $this->performedID = null;
        $this->exception   = null;
        $this->context     = [];
        $this->tags        = [];
        $this->timestamp   = null;
        $this->request     = null;
        $this->response    = null;
        $this->output      = 'loggly';
        $this->encryptLogs = false;
    }
}
