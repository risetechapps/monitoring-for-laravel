<<<<<<< HEAD
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

class ExceptionWatcher extends Watcher
{
    /**
     * Registra o ouvinte de eventos para o registro de exceções.
     *
     * Este método configura um ouvinte para o evento `MessageLogged`, que
     * será tratado pelo método `recordException`.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(MessageLogged::class, [$this, 'recordException']);
    }

    /**
     * Registra uma exceção quando um evento de log é emitido.
     *
     * Este método cria uma entrada de exceção com base nas informações do evento
     * de log. Se houver uma exceção durante o processamento, ela será registrada
     * em um arquivo de log.
     *
     * @param  MessageLogged  $event O evento de log que contém a exceção.
     * @return void
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada de exceção.
     */
    public function recordException(MessageLogged $event): void
    {
        try {

            if(!Monitoring::isEnabled()) return;

            if ($this->shouldIgnore($event)) {
                return;
            }

            $exception = $event->context['exception'];

            $trace = collect($exception->getTrace())->map(function ($item) {
                return Arr::only($item, ['file', 'line']);
            })->toArray();

            $entry = IncomingExceptionEntry::make($exception, [
                'class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'context' => transform(Arr::except($event->context, ['exception', 'monitoring']), function ($context) {
                    return !empty($context) ? $context : null;
                }),
                'trace' => $trace,
                'line_preview' => ExceptionContext::get($exception),
            ]);

            Monitoring::recordException($entry);

        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Extrai as tags associadas ao evento.
     *
     * Obtém as tags relacionadas à exceção e ao serviço de monitoramento a partir
     * do contexto do evento.
     *
     * @param  MessageLogged  $event O evento de log que contém a exceção.
     * @return array As tags extraídas.
     */
    protected function tags($event): array
    {
        return array_merge(
            ExtractTags::from($event->context['exception']),
            $event->context['monitoring-service'] ?? []
        );
    }

    /**
     * Determina se o evento deve ser ignorado.
     *
     * Verifica se o evento não contém uma exceção ou se a exceção não é uma
     * instância de `Throwable`.
     *
     * @param  MessageLogged  $event O evento de log.
     * @return bool Retorna verdadeiro se o evento deve ser ignorado, falso caso contrário.
     */
    private function shouldIgnore($event): bool
    {
        return !isset($event->context['exception']) ||
            !$event->context['exception'] instanceof Throwable;
    }
}
=======
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

class ExceptionWatcher extends Watcher
{
    /**
     * Registra o ouvinte de eventos para o registro de exceções.
     *
     * Este método configura um ouvinte para o evento `MessageLogged`, que
     * será tratado pelo método `recordException`.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(MessageLogged::class, [$this, 'recordException']);
    }

    /**
     * Registra uma exceção quando um evento de log é emitido.
     *
     * Este método cria uma entrada de exceção com base nas informações do evento
     * de log. Se houver uma exceção durante o processamento, ela será registrada
     * em um arquivo de log.
     *
     * @param  MessageLogged  $event O evento de log que contém a exceção.
     * @return void
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada de exceção.
     */
    public function recordException(MessageLogged $event): void
    {
        try {

            if(!Monitoring::isEnabled()) return;

            if ($this->shouldIgnore($event)) {
                return;
            }

            $exception = $event->context['exception'];

            $trace = collect($exception->getTrace())->map(function ($item) {
                return Arr::only($item, ['file', 'line']);
            })->toArray();

            $entry = IncomingExceptionEntry::make($exception, [
                'class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'context' => transform(Arr::except($event->context, ['exception', 'monitoring']), function ($context) {
                    return !empty($context) ? $context : null;
                }),
                'trace' => $trace,
                'line_preview' => ExceptionContext::get($exception),
            ]);

            Monitoring::recordException($entry);

        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Extrai as tags associadas ao evento.
     *
     * Obtém as tags relacionadas à exceção e ao serviço de monitoramento a partir
     * do contexto do evento.
     *
     * @param  MessageLogged  $event O evento de log que contém a exceção.
     * @return array As tags extraídas.
     */
    protected function tags($event): array
    {
        return array_merge(
            ExtractTags::from($event->context['exception']),
            $event->context['monitoring-service'] ?? []
        );
    }

    /**
     * Determina se o evento deve ser ignorado.
     *
     * Verifica se o evento não contém uma exceção ou se a exceção não é uma
     * instância de `Throwable`.
     *
     * @param  MessageLogged  $event O evento de log.
     * @return bool Retorna verdadeiro se o evento deve ser ignorado, falso caso contrário.
     */
    private function shouldIgnore($event): bool
    {
        return !isset($event->context['exception']) ||
            !$event->context['exception'] instanceof Throwable;
    }
}
>>>>>>> origin/main
