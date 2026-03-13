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

    protected static bool $EventsFrameworkIgnore = true;

    /**
     * Namespaces de ferramentas de monitoramento/infra que NÃO são listeners
     * de negócio. Se um evento só tem listeners desses namespaces, ele já está
     * sendo coberto pela própria ferramenta — não precisamos duplicar.
     */
    private const INTERNAL_LISTENER_NAMESPACES = [
        'Laravel\\Telescope\\',
        'Laravel\\Horizon\\',
        'Illuminate\\Foundation\\Testing\\',
        'Barryvdh\\Debugbar\\',
        'Clockwork\\',
    ];

    public function register($app): void
    {
        $app['events']->listen('*', [$this, 'recordEvent']);
    }

    public function recordEvent($eventName, $payload): void
    {
        try {
            if (! Monitoring::isEnabled()) return;

            if ($this->shouldIgnore($eventName)) return;

            if ($this->hasOnlyInternalListeners($eventName)) return;

            $formattedPayload = $this->extractPayload($eventName, $payload);

            $entry = IncomingEntry::make([
                'name'      => $eventName,
                'payload'   => empty($formattedPayload) ? null : $formattedPayload,
                'listeners' => $this->formatListeners($eventName),
                'broadcast' => class_exists($eventName) && in_array(ShouldBroadcast::class, (array) class_implements($eventName)),
            ]);

            Monitoring::recordEvent($entry);
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    protected function extractPayload($eventName, $payload): array
    {
        if (class_exists($eventName) && isset($payload[0]) && is_object($payload[0])) {
            try {
                return ExtractProperties::from($payload[0]);
            } catch (\Throwable) {
                return ['_error' => 'Could not extract event payload'];
            }
        }

        return collect($payload)->map(function ($value) {
            if (! is_object($value)) {
                return $value;
            }

            // Serialização segura com limite de tamanho
            $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json && strlen($json) > 16384) {
                return ['class' => get_class($value), '_purged' => 'Object too large'];
            }

            return [
                'class'      => get_class($value),
                'properties' => json_decode($json ?: '{}', true),
            ];
        })->toArray();
    }

    protected function formatListeners($eventName): array
    {
        try {
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
                    $queued = false;
                    if (Str::contains($listener, '@')) {
                        $queued = in_array(ShouldQueue::class, class_implements(explode('@', $listener)[0]) ?: []);
                    }
                    return ['name' => $listener, 'queued' => $queued];
                })->values()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function shouldIgnore($eventName): bool
    {
        return $this->eventIsIgnored($eventName) ||
            (static::$EventsFrameworkIgnore && $this->eventIsFiredByTheFramework($eventName));
    }

    protected function eventIsFiredByTheFramework($eventName): bool
    {
        return Str::is(
            [
                'Illuminate\\*',
                'Laravel\\Octane\\*',
                'Laravel\\Horizon\\*',    // Todos os eventos internos do Horizon
                'Laravel\\Telescope\\*', // Todos os eventos internos do Telescope
                'eloquent*',
                'bootstrapped*',
                'bootstrapping*',
                'creating*',
                'composing*',

                'Illuminate\*',
                'Laravel\Octane\*',
                'Laravel\Telescope\*',
                'Laravel\Scout\Events\ModelsImported',
                'eloquent*',
                'bootstrapped*',
                'bootstrapping*',
                'creating*',
                'composing*',
            ],
            $eventName
        );
    }

    protected function eventIsIgnored($eventName): bool
    {
        return Str::is($this->options['ignore'] ?? [], $eventName);
    }

    /**
     * Retorna true se TODOS os listeners do evento pertencem a namespaces
     * de ferramentas internas (Telescope, Horizon, etc.).
     *
     * Isso evita capturar eventos de negócio que só o Telescope está ouvindo
     * — como InitializingTenancyEvent com apenas EventWatcher@recordEvent do Telescope.
     */
    protected function hasOnlyInternalListeners(string $eventName): bool
    {
        try {
            $rawListeners = app('events')->getListeners($eventName);

            if (empty($rawListeners)) {
                return false;
            }

            foreach ($rawListeners as $rawListener) {
                $resolved = null;

                try {
                    $resolved = (new ReflectionFunction($rawListener))
                        ->getStaticVariables()['listener'] ?? null;
                } catch (\Throwable) {
                    // Se não consegue inspecionar, considera listener de negócio.
                    return false;
                }

                $listenerClass = match (true) {
                    is_string($resolved)                              => explode('@', $resolved)[0],
                    is_array($resolved) && is_string($resolved[0])   => $resolved[0],
                    is_array($resolved) && is_object($resolved[0])   => get_class($resolved[0]),
                    default                                           => null,
                };

                if ($listenerClass === null) {
                    // Closure anônima — é listener de negócio.
                    return false;
                }

                $isInternal = false;
                foreach (self::INTERNAL_LISTENER_NAMESPACES as $ns) {
                    if (str_starts_with($listenerClass, $ns)) {
                        $isInternal = true;
                        break;
                    }
                }

                if (! $isInternal) {
                    // Ao menos um listener é de negócio — deve monitorar.
                    return false;
                }
            }

            // Todos os listeners são internos — pula o evento.
            return true;

        } catch (\Throwable) {
            return false;
        }
    }
}
