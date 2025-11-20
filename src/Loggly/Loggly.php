<<<<<<< HEAD
<?php

namespace RiseTechApps\Monitoring\Loggly;

use DateTime;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use Throwable;

class Loggly
{
    /** Definindo níveis de log
     * @var array
     * */
    private array $logLevels = [
        "emergency", "alert", "critical", "error", "warning", "notice", "info", "model", "debug"
    ];

    /** Constantes para fácil referência de nível de log */
    const EMERGENCY = 0;
    const ALERT = 1;
    const CRITICAL = 2;
    const ERROR = 3;
    const WARNING = 4;
    const NOTICE = 5;
    const INFO = 6;
    const MODEL = 7;
    const DEBUG = 8;

    /** Nível de log padrão
     * @var string
     **/
    private string $level = "info";

    /** Propriedades adicionais do log
     * @var array
     **/
    private array $properties = [];

    /** Modelo ou ação realizada
     * @var ?string
     */
    private ?string $performed = null;

    /** ID do modelo, se aplicável
     * @var ?string
     * */
    private ?string $performedID = null;

    /** Informações de exceção
     * @var ?array
     * */
    private ?array $exception = null;

    /** Contexto adicional (ex: dados da sessão, request)
     * @var array
     * */
    private array $context = [];

    /** Tags para categorização do log
     * @var array
     * */
    private array $tags = [];

    /** Timestamp personalizado
     *
     * @var ?DateTime
     * */
    private ?DateTime $timestamp = null;
    /** Dados de request
     * @var ?array
     * */
    private ?array $request = null;

    /** Dados do response
     * @var ?array
     * */
    private ?array $response = null;

    /** Saída do log (ex: Loggly, arquivo, etc.)
     * @var string
     * */
    private string $output = 'loggly';

    /** Flag para encriptação de logs
     *
     * @var bool
     * */
    private bool $encryptLogs = false;

    /** Buffer de logs para envio em lote
     * @var array
     * */
    private array $logBuffer = [];

    /** Tamanho máximo do buffer antes de envio
     * @var int
     * */
    private int $bufferSize = 5;

    /**
     * Define o nível do log. O nível pode ser passado como índice ou string.
     * @param int|string $level
     * @return $this
     */
    public function level(int|string $level): Loggly
    {
        if (is_int($level) && isset($this->logLevels[$level])) {
            $this->level = $this->logLevels[$level];
        } elseif (is_string($level) && in_array($level, $this->logLevels)) {
            $this->level = $level;
        }
        return $this;
    }

    /**
     * Adiciona propriedades extras ao log.
     * @param array $properties
     * @return $this
     */
    public function withProperties(array $properties = []): Loggly
    {
        $this->properties = array_merge($this->properties, $properties);
        return $this;
    }

    /**
     * Registra o modelo ou objeto que está sendo modificado.
     * @param mixed $performed
     * @return $this
     */
    public function performedOn(mixed $performed): Loggly
    {
        if ($performed instanceof Model) {
            $this->performed = get_class($performed);
            $this->performedID = $performed->getKey();
        } else {
            $this->performed = is_object($performed) ? get_class($performed) : $performed;
        }
        return $this;
    }

    /**
     * Captura informações de exceção.
     * @param Throwable|string $exception
     * @return $this
     */
    public function exception(Throwable|string $exception): Loggly
    {
        if ($exception instanceof Throwable) {
            $this->exception = [
                'message' => $exception->getMessage(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
            ];
        } else {
            $this->exception = ['message' => $exception];
        }

        return $this;
    }

    /**
     * Adiciona contexto ao log.
     * @param array $context
     * @return $this
     */
    public function withContext(array $context): Loggly
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Adiciona tags ao log.
     * @param array $tags
     * @return $this
     */
    public function withTags(array $tags): Loggly
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    /**
     * Define um timestamp personalizado para o log.
     * @param DateTime $timestamp
     * @return $this
     */
    public function at(DateTime $timestamp): Loggly
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * Captura informações da requisição (request).
     * @param mixed $request
     * @return $this
     */
    public function withRequest(mixed $request): Loggly
    {
        $this->request = is_array($request) ? $request : (array)$request;
        return $this;
    }

    /**
     * Captura informações da resposta (response).
     * @param mixed $response
     * @return $this
     */
    public function withResponse(mixed $response): Loggly
    {
        $this->response = is_string($response) ? $response :
            json_decode(json_encode($response), true);
        return $this;
    }

    /**
     * Define o tipo de saída para o log (ex: 'loggly', 'file', 'sentry').
     * @param string $output
     * @return $this
     */
    public function to(string $output): Loggly
    {
        $this->output = $output;
        return $this;
    }

    /**
     * Ativa a encriptação dos logs, útil para logs sensíveis.
     * @return $this
     */
    public function encrypt(): Loggly
    {
        $this->encryptLogs = true;
        return $this;
    }

    /**
     * Define o nível de log dinamicamente a partir das configurações.
     * @return $this
     */
    public function setLevelFromConfig(): Loggly
    {
        $level = 'info';
        return $this->level($level);
    }

    /**
     * Registra a mensagem de log, utilizando as propriedades configuradas.
     * Envia o log para o destino apropriado (ex: Loggly, arquivo, etc.).
     * @param string $message
     */
    public function log(string $message): void
    {
        $this->withProperties($this->resolveCaller());

        $entry = IncomingEntry::make([
            'level' => $this->level,
            'message' => $this->encryptLogs ? encrypt($message) : $message,
            'properties' => $this->properties,
            'context' => $this->context,
            'tags' => $this->tags,
            'performed' => $this->performed,
            'performed_id' => $this->performedID,
            'exception' => $this->exception,
            'timestamp' => $this->timestamp ? $this->timestamp->format('Y-m-d H:i:s') : now(),
            'request' => $this->request,
            'response' => $this->response,
        ]);

        $this->logBuffer[] = $entry;
        if (count($this->logBuffer) >= $this->bufferSize) {
            $this->flushLogs();
        }

        $this->sendToOutput($entry);
    }

    /**
     * Envia os logs acumulados no buffer.
     */
    private function flushLogs(): void
    {
        foreach ($this->logBuffer as $entry) {
            $this->sendToOutput($entry);
        }
        $this->logBuffer = [];
    }

    /**
     * Lida com o envio do log para o destino apropriado (ex: Loggly, arquivo, Sentry, etc.).
     * @param IncomingEntry $entry
     */
    private function sendToOutput(IncomingEntry $entry): void
    {
        switch ($this->output) {
            case 'loggly':
                Monitoring::recordLoggly($entry);
                break;
            case 'file':
                file_put_contents(storage_path('logs/error-monitoring.log'), json_encode($entry) . PHP_EOL, FILE_APPEND);
                break;
            default:
                throw new InvalidArgumentException("Unsupported output: {$this->output}");
        }
    }

    private function resolveCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        $traceData = [
            'file' => 'unknown',
            'line' => 0,
            'function' =>  "{closure}",
            'class' => 'anonymous',
        ];

        if (count($trace) > 0) {
            $traceData['file'] = $trace[1]['file'];
            $traceData['line'] = $trace[1]['line'];
            $traceData['class'] = $this->fileToClass($traceData['file']);
        }

        if($traceData['class'] === "anonymous"){
            return $traceData;
        }

        foreach ($trace as $index => $frame) {

            if(array_key_exists('class', $frame)) {
                if($traceData['class'] === $frame['class']) {
                    $traceData['function'] = $frame['function'];
                    return  $traceData;
                }
            }
        }

        return $traceData;
    }

    public function fileToClass(string $filePath): ?string
    {
        $filePath = str_replace('/', '\\', $filePath);
        $pos = stripos($filePath, '\\app\\');
        if ($pos === false) {
            return "anonymous";
        }
        $relativePath = substr($filePath, $pos + 5);
        $relativePath = preg_replace('/\.php$/i', '', $relativePath);
        $class = str_replace('\\', '\\', $relativePath);
        return 'App\\' . $class;
    }


}
=======
<?php

namespace RiseTechApps\Monitoring\Loggly;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;

class Loggly
{
    /** Definindo níveis de log
     * @var array
     * */
    private array $logLevels = [
        "emergency", "alert", "critical", "error", "warning", "notice", "info", "model", "debug"
    ];

    /** Constantes para fácil referência de nível de log */
    const EMERGENCY = 0;
    const ALERT = 1;
    const CRITICAL = 2;
    const ERROR = 3;
    const WARNING = 4;
    const NOTICE = 5;
    const INFO = 6;
    const MODEL = 7;
    const DEBUG = 8;

    /** Nível de log padrão
     * @var string
     **/
    private string $level = "info";

    /** Propriedades adicionais do log
     * @var array
     **/
    private array $properties = [];

    /** Modelo ou ação realizada
     * @var ?string
     */
    private ?string $performed = null;

    /** ID do modelo, se aplicável
     * @var ?string
     * */
    private ?string $performedID = null;

    /** Informações de exceção
     * @var ?array
     * */
    private ?array $exception = null;

    /** Contexto adicional (ex: dados da sessão, request)
     * @var array
     * */
    private array $context = [];

    /** Tags para categorização do log
     * @var array
     * */
    private array $tags = [];

    /** Timestamp personalizado
     * @var ?\DateTime
     * */
    private ?\DateTime $timestamp = null;
    /** Dados de request
     * @var ?array
     * */
    private ?array $request = null;

    /** Dados do response
     * @var ?array
     * */
    private ?array $response = null;

    /** Saída do log (ex: Loggly, arquivo, etc.)
     * @var string
     * */
    private string $output = 'loggly';

    /** Flag para encriptação de logs
     * @var ?string
     * */
    private bool $encryptLogs = false;

    /** Buffer de logs para envio em lote
     * @var array
     * */
    private array $logBuffer = [];

    /** Tamanho máximo do buffer antes de envio
     * @var int
     * */
    private int $bufferSize = 5;

    /**
     * Define o nível do log. O nível pode ser passado como índice ou string.
     * @param int|string $level
     * @return $this
     */
    public function level(int|string $level): Loggly
    {
        if (is_int($level) && isset($this->logLevels[$level])) {
            $this->level = $this->logLevels[$level];
        } elseif (is_string($level) && in_array($level, $this->logLevels)) {
            $this->level = $level;
        }
        return $this;
    }

    /**
     * Adiciona propriedades extras ao log.
     * @param array $properties
     * @return $this
     */
    public function withProperties(array $properties = []): Loggly
    {
        $this->properties = array_merge($this->properties, $properties);
        return $this;
    }

    /**
     * Registra o modelo ou objeto que está sendo modificado.
     * @param mixed $performed
     * @return $this
     */
    public function performedOn(mixed $performed): Loggly
    {
        if ($performed instanceof Model) {
            $this->performed = get_class($performed);
            $this->performedID = $performed->getKey();
        } else {
            $this->performed = is_object($performed) ? get_class($performed) : $performed;
        }
        return $this;
    }

    /**
     * Captura informações de exceção.
     * @param \Throwable|string $exception
     * @return $this
     */
    public function exception($exception): Loggly
    {
        if ($exception instanceof \Throwable) {
            $this->exception = [
                'message' => $exception->getMessage(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
            ];
        } elseif (is_string($exception)) {
            $this->exception = ['message' => $exception];
        }

        return $this;
    }

    /**
     * Adiciona contexto ao log.
     * @param array $context
     * @return $this
     */
    public function withContext(array $context): Loggly
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Adiciona tags ao log.
     * @param array $tags
     * @return $this
     */
    public function withTags(array $tags): Loggly
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    /**
     * Define um timestamp personalizado para o log.
     * @param \DateTime $timestamp
     * @return $this
     */
    public function at(\DateTime $timestamp): Loggly
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * Captura informações da requisição (request).
     * @param mixed $request
     * @return $this
     */
    public function withRequest(mixed $request): Loggly
    {
        $this->request = is_array($request) ? $request : (array)$request;
        return $this;
    }

    /**
     * Captura informações da resposta (response).
     * @param mixed $response
     * @return $this
     */
    public function withResponse(mixed $response): Loggly
    {
        $this->response = is_string($response) ? $response :
            json_decode(json_encode($response), true);
        return $this;
    }

    /**
     * Define o tipo de saída para o log (ex: 'loggly', 'file', 'sentry').
     * @param string $output
     * @return $this
     */
    public function to(string $output): Loggly
    {
        $this->output = $output;
        return $this;
    }

    /**
     * Ativa a encriptação dos logs, útil para logs sensíveis.
     * @return $this
     */
    public function encrypt(): Loggly
    {
        $this->encryptLogs = true;
        return $this;
    }

    /**
     * Define o nível de log dinamicamente a partir das configurações.
     * @return $this
     */
    public function setLevelFromConfig(): Loggly
    {
        $level = 'info';
        return $this->level($level);
    }

    /**
     * Registra a mensagem de log, utilizando as propriedades configuradas.
     * Envia o log para o destino apropriado (ex: Loggly, arquivo, etc.).
     * @param string $message
     */
    public function log(string $message): void
    {
        $this->withProperties($this->resolveCaller());

        $entry = IncomingEntry::make([
            'level' => $this->level,
            'message' => $this->encryptLogs ? encrypt($message) : $message,
            'properties' => $this->properties,
            'context' => $this->context,
            'tags' => $this->tags,
            'performed' => $this->performed,
            'performed_id' => $this->performedID,
            'exception' => $this->exception,
            'timestamp' => $this->timestamp ? $this->timestamp->format('Y-m-d H:i:s') : now(),
            'request' => $this->request,
            'response' => $this->response,
        ]);

        $this->logBuffer[] = $entry;
        if (count($this->logBuffer) >= $this->bufferSize) {
            $this->flushLogs();
        }

        $this->sendToOutput($entry);
    }

    /**
     * Envia os logs acumulados no buffer.
     */
    private function flushLogs(): void
    {
        foreach ($this->logBuffer as $entry) {
            $this->sendToOutput($entry);
        }
        $this->logBuffer = [];
    }

    /**
     * Lida com o envio do log para o destino apropriado (ex: Loggly, arquivo, Sentry, etc.).
     * @param IncomingEntry $entry
     */
    private function sendToOutput(IncomingEntry $entry): void
    {
        switch ($this->output) {
            case 'loggly':
                Monitoring::recordLoggly($entry);
                break;
            case 'file':
                file_put_contents(storage_path('logs/error-monitoring.log'), json_encode($entry) . PHP_EOL, FILE_APPEND);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported output: {$this->output}");
        }
    }

    private function resolveCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        $traceData = [
            'file' => 'unknown',
            'line' => 0,
            'function' =>  "{closure}",
            'class' => 'anonymous',
        ];

        if (count($trace) > 0) {
            $traceData['file'] = $trace[1]['file'];
            $traceData['line'] = $trace[1]['line'];
            $traceData['class'] = $this->fileToClass($traceData['file']);
        }

        if($traceData['class'] === "anonymous"){
            return $traceData;
        }

        foreach ($trace as $index => $frame) {

            if(array_key_exists('class', $frame)) {
                if($traceData['class'] === $frame['class']) {
                    $traceData['function'] = $frame['function'];
                    return  $traceData;
                }
            }
        }

        return $traceData;
    }

    public function fileToClass(string $filePath): ?string
    {
        $filePath = str_replace('/', '\\', $filePath);
        $pos = stripos($filePath, '\\app\\');
        if ($pos === false) {
            return "anonymous";
        }
        $relativePath = substr($filePath, $pos + 5);
        $relativePath = preg_replace('/\.php$/i', '', $relativePath);
        $class = str_replace('\\', '\\', $relativePath);
        return 'App\\' . $class;
    }


}
>>>>>>> origin/main
