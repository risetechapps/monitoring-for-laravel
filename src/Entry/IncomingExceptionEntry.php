<?php

namespace RiseTechApps\Monitoring\Entry;

use Illuminate\Contracts\Debug\ExceptionHandler;

class IncomingExceptionEntry extends IncomingEntry
{
    /** Variavel para receber o exception
     *
     * @var mixed
     */
    public mixed $exception;

    public function __construct(mixed $exception, array $content)
    {
        $this->exception = $exception;

        parent::__construct($content);
    }

    public function isReportableException(): bool
    {
        $handler = app(ExceptionHandler::class);

        return method_exists($handler, 'shouldReport')
            ? $handler->shouldReport($this->exception) : true;
    }

    public function isException(): bool
    {
        return true;
    }
}
