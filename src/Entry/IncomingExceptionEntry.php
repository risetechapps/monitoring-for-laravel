<?php

namespace RiseTechApps\Monitoring\Entry;

use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * CORREÇÃO DE MEMÓRIA:
 *
 * A versão anterior armazenava $this->exception (o objeto Throwable completo)
 * como propriedade da classe e o mantinha em memória por toda a vida da entrada
 * no buffer. Em cenários com Predis/Redis, a cadeia de exceções ($previous) pode
 * conter centenas de frames e referências a conexões abertas.
 *
 * Agora o Throwable é mantido apenas para as verificações de reportabilidade
 * (isReportableException / isException) e depois deve ser descartado. A extração
 * do trace e do contexto é feita no ExceptionWatcher antes da construção.
 */
class IncomingExceptionEntry extends IncomingEntry
{
    /**
     * Mantemos a referência APENAS para poder chamar shouldReport() no handler.
     * Deve ser anulada após o uso para liberar a cadeia de exceções da memória.
     *
     * @var \Throwable|null
     */
    private ?\Throwable $exceptionRef;

    public function __construct(\Throwable $exception, array $content)
    {
        $this->exceptionRef = $exception;
        parent::__construct($content);
    }

    /**
     * Verifica com o ExceptionHandler do Laravel se a exceção deve ser reportada.
     * Após a chamada, a referência ao objeto de exceção é liberada.
     */
    public function isReportableException(): bool
    {
        if ($this->exceptionRef === null) {
            return true;
        }

        $handler = app(ExceptionHandler::class);

        $shouldReport = method_exists($handler, 'shouldReport')
            ? $handler->shouldReport($this->exceptionRef)
            : true;

        // Libera a referência imediatamente após o uso.
        $this->exceptionRef = null;

        return $shouldReport;
    }

    public function isException(): bool
    {
        return true;
    }

    /**
     * Libera a referência à exceção explicitamente.
     * Chamável após a construção, caso isReportableException() não seja invocado.
     */
    public function releaseException(): void
    {
        $this->exceptionRef = null;
    }
}
