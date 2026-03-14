<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Loggly;

use DateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use Throwable;

/**
 * Fluent API para registrar logs customizados no Monitoring.
 *
 * CORREÇÕES APLICADAS (v2.1):
 *
 * BUG #1 — CAUSA RAIZ (logs nunca gravados em HTTP):
 *   A classe não era registrada como singleton no ServiceProvider.
 *   app(Loggly::class) criava uma nova instância a cada chamada helper,
 *   com $logBuffer sempre vazio. O flushLogs() nunca era acionado em
 *   contexto HTTP (não-console). A instância era descartada pelo GC
 *   levando todos os logs com ela.
 *   FIX: MonitoringServiceProvider registra Loggly::class como singleton.
 *
 * BUG #2 — bufferSize ignorava a config do package:
 *   $bufferSize = 5 estava hardcoded. A opção monitoring.buffer_size
 *   era lida pelo Monitoring mas ignorada pela Loggly.
 *   FIX: removido o buffer interno inteiramente (ver Bug #3).
 *
 * BUG #3 — Double-buffering redundante:
 *   Loggly bufferizava internamente e depois chamava Monitoring::recordLoggly(),
 *   que adiciona ao buffer estático do Monitoring. Dois buffers em série
 *   sem nenhum benefício — duplicava a complexidade e ocultava o Bug #1.
 *   FIX: Loggly::log() chama sendToOutput() DIRETAMENTE, sem buffer próprio.
 *   O Monitoring já gerencia o buffer com flushBuffer() + terminating() hook.
 *
 * BUG ANTERIOR (v2.0 — mantido corrigido):
 *   Double-send: sendToOutput() era chamado imediatamente EM log() E novamente
 *   dentro de flushLogs() quando o buffer enchia. Cada entrada era enviada 2×.
 *   Já estava corrigido; mantida a correção com a nova arquitetura.
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

    private string  $level       = 'info';
    private array   $properties  = [];
    private ?string $performed   = null;
    private ?string $performedID = null;
    private ?array  $exception   = null;
    private array   $context     = [];
    private array   $tags        = [];
    private ?DateTime $timestamp = null;
    private ?array  $request     = null;
    private ?array  $response    = null;
    private string  $output      = 'loggly';
    private bool    $encryptLogs = false;

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
            $rawTrace = $exception->getTrace();
            $trace    = array_slice(
                array_map(
                    fn ($frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])),
                    $rawTrace
                ),
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
     * CORREÇÃO v2.1: O buffer interno foi removido. Loggly delega
     * imediatamente para sendToOutput(), que encaminha ao Monitoring.
     * O Monitoring já possui buffer estático próprio (flushBuffer) e
     * é descarregado no hook terminating() — nenhum log se perde.
     *
     * A classe deve ser singleton (registrada no ServiceProvider) para
     * que o resetState() ao final deste método preserve o isolamento
     * correto entre chamadas encadeadas.
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
            'timestamp'    => $this->timestamp
                ? $this->timestamp->format('Y-m-d H:i:s')
                : now()->toDateTimeString(),
            'request'      => $this->request,
            'response'     => $this->response,
        ]);

        // Despacha diretamente — sem buffer duplo.
        // Monitoring::record() possui circuit breaker e buffer próprio.
        $this->sendToOutput($entry);

        // Reseta estado fluente para garantir isolamento entre chamadas no singleton.
        $this->resetState();
    }

    /**
     * Despacha a entrada ao canal configurado.
     */
    private function sendToOutput(IncomingEntry $entry): void
    {
        match ($this->output) {
            'loggly' => Monitoring::recordLoggly($entry),
            'file'   => $this->writeToFile($entry),
            default  => throw new InvalidArgumentException("Unsupported Loggly output: {$this->output}"),
        };
    }

    /**
     * Canal de fallback seguro — não passa pelo sistema de eventos do Laravel.
     * Usado pelos catch blocks dos Watchers para evitar recursão infinita.
     */
    private function writeToFile(IncomingEntry $entry): void
    {
        try {

            $entry->setType(EntryType::LOG);

            $line = json_encode(
                $entry->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            file_put_contents(
                storage_path('logs/error-monitoring.log'),
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $exception) { Log::info('LOGSERROR', [$exception->getMessage(), $exception->getFile(), $exception->getLine()]); ;
            // Último recurso — silencia para não criar loop.
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Sanitiza propriedades, truncando valores que excedem o limite.
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
     * Reseta o estado fluente após cada log().
     *
     * Como a classe é singleton, este reset garante que chamadas consecutivas
     * não vazem estado de uma chamada para a próxima. Exemplo correto:
     *
     *   logglyError()->exception($e)->log('msg1');  // level=error, exception=$e
     *   logglyInfo()->log('msg2');                   // level=info, sem exception ✓
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
