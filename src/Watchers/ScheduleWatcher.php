<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;

class ScheduleWatcher extends Watcher
{
    /**
     * Registra um ouvinte para o evento CommandStarting.
     *
     * Este método configura um ouvinte para o evento CommandStarting, que é acionado quando um comando de console está prestes a começar.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(CommandStarting::class, [$this, 'recordCommand']);
    }

    /**
     * Registra as informações do comando agendado.
     *
     * Este método coleta detalhes sobre os eventos agendados e grava essas informações no sistema de monitoramento,
     * mas somente se o comando atual for 'schedule:run' ou 'schedule:finish'.
     *
     * @param CommandStarting $event O evento que contém detalhes sobre o comando que está começando.
     * @return void
     */
    public function recordCommand(CommandStarting $event): void
    {
        try {

            if(!Monitoring::isEnabled()) return;

            if ($event->command !== 'schedule:run' && $event->command !== 'schedule:finish') {
                return;
            }

            collect(app(Schedule::class)->events())->each(function ($event) {
                $event->then(function () use ($event) {
                    Monitoring::recordScheduledCommand(IncomingEntry::make([
                        'command' => $event instanceof CallbackEvent ? 'Closure' : $event->command,
                        'description' => $event->description,
                        'expression' => $event->expression,
                        'timezone' => $event->timezone,
                        'user' => $event->user,
                        'output' => $this->getEventOutput($event),
                    ]));
                });
            });
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Obtém a saída do evento agendado, se disponível.
     *
     * Este método verifica se há um arquivo de saída especificado para o evento agendado e retorna o conteúdo do arquivo.
     *
     * @param Event $event O evento agendado.
     * @return string A saída do evento, ou uma string vazia se não houver saída ou o arquivo não existir.
     */
    protected function getEventOutput(Event $event)
    {
        if (!$event->output ||
            $event->output === $event->getDefaultOutput() ||
            $event->shouldAppendOutput ||
            !file_exists($event->output)) {
            return '';
        }

        return trim(file_get_contents($event->output));
    }
}
