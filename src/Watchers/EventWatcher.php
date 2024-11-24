<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use ReflectionFunction;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\ExtractProperties;
use RiseTechApps\Monitoring\Traits\FormatsClosure\FormatsClosure;

class EventWatcher extends Watcher
{
    use FormatsClosure;

    /**
     * Flag para determinar se eventos do framework devem ser ignorados.
     *
     * @var bool
     */
    protected static bool $EventsFrameworkIgnore = true;

    /**
     * Registra o ouvinte de eventos para todos os eventos.
     *
     * Este método registra um ouvinte que será chamado para todos os eventos
     * utilizando o método `recordEvent`.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen('*', [$this, 'recordEvent']);
    }

    /**
     * Registra as informações do evento.
     *
     * Este método cria uma entrada de monitoramento com base no nome e payload
     * do evento. Se houver uma exceção, ela será registrada em um arquivo de log.
     *
     * @param  string  $eventName O nome do evento.
     * @param  array   $payload   O payload do evento.
     * @return void
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada.
     */
    public function recordEvent($eventName, $payload): void
    {
        try {
            if(!Monitoring::isEnabled()) return;

            if ($this->shouldIgnore($eventName)) return;

            $formattedPayload = $this->extractPayload($eventName, $payload);

            $entry = IncomingEntry::make([
                'name' => $eventName,
                'payload' => empty($formattedPayload) ? null : $formattedPayload,
                'listeners' => $this->formatListeners($eventName),
                'broadcast' => class_exists($eventName) && in_array(ShouldBroadcast::class, (array)class_implements($eventName)),
            ]);

            Monitoring::recordEvent($entry);
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Extrai o payload do evento e o formata.
     *
     * Se o evento for uma classe e o payload contiver um objeto, as propriedades
     * do objeto são extraídas. Caso contrário, o payload é mapeado para um array.
     *
     * @param  string  $eventName O nome do evento.
     * @param  array   $payload   O payload do evento.
     * @return array O payload formatado.
     */
    protected function extractPayload($eventName, $payload): array
    {
        if (class_exists($eventName) && isset($payload[0]) && is_object($payload[0])) {
            return ExtractProperties::from($payload[0]);
        }

        return collect($payload)->map(function ($value) {
            return is_object($value) ? [
                'class' => get_class($value),
                'properties' => json_decode(json_encode($value), true),
            ] : $value;
        })->toArray();
    }

    /**
     * Formata a lista de ouvintes do evento.
     *
     * Obtém e formata os ouvintes registrados para o evento, incluindo
     * informações sobre se são filas ou não.
     *
     * @param  string  $eventName O nome do evento.
     * @return array A lista de ouvintes formatada.
     */
    protected function formatListeners($eventName): array
    {
        return collect(app('events')->getListeners($eventName))
            ->map(function ($listener) {
                $listener = (new ReflectionFunction($listener))
                    ->getStaticVariables()['listener'];

                if (is_string($listener)) {
                    return Str::contains($listener, '@') ? $listener : $listener . '@handle';
                } elseif (is_array($listener) && is_string($listener[0])) {
                    return $listener[0] . '@' . $listener[1];
                } elseif (is_array($listener) && is_object($listener[0])) {
                    return get_class($listener[0]) . '@' . $listener[1];
                }

                return $this->formatClosureListener($listener);
            })->reject(function ($listener) {
                return Str::contains($listener, 'RiseTechApps\\Monitoring');
            })->map(function ($listener) {
                if (Str::contains($listener, '@')) {
                    $queued = in_array(ShouldQueue::class, class_implements(explode('@', $listener)[0]));
                }

                return [
                    'name' => $listener,
                    'queued' => $queued ?? false,
                ];
            })->values()->toArray();
    }

    /**
     * Determina se o evento deve ser ignorado.
     *
     * Verifica se o evento está na lista de eventos a serem ignorados ou
     * se deve ser ignorado com base na configuração do framework.
     *
     * @param  string  $eventName O nome do evento.
     * @return bool Retorna verdadeiro se o evento deve ser ignorado, falso caso contrário.
     */
    protected function shouldIgnore($eventName): bool
    {
        return $this->eventIsIgnored($eventName) ||
            (static::$EventsFrameworkIgnore && $this->eventIsFiredByTheFramework($eventName));
    }

    /**
     * Verifica se o evento é disparado pelo framework.
     *
     * Compara o nome do evento com padrões de eventos do framework para determinar
     * se deve ser ignorado.
     *
     * @param  string  $eventName O nome do evento.
     * @return bool Retorna verdadeiro se o evento for disparado pelo framework, falso caso contrário.
     */
    protected function eventIsFiredByTheFramework($eventName): bool
    {
        return Str::is(
            ['Illuminate\*', 'Laravel\Octane\*', 'eloquent*', 'bootstrapped*', 'bootstrapping*', 'creating*', 'composing*'],
            $eventName
        );
    }

    /**
     * Verifica se o evento está na lista de eventos a serem ignorados.
     *
     * @param  string  $eventName O nome do evento.
     * @return bool Retorna verdadeiro se o evento estiver na lista de ignorados, falso caso contrário.
     */
    protected function eventIsIgnored($eventName): bool
    {
        return Str::is($this->options['ignore'] ?? [], $eventName);
    }
}
