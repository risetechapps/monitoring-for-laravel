<<<<<<< HEAD
<?php

namespace RiseTechApps\Monitoring\Watchers;

use Exception;
use Illuminate\Console\Events\CommandFinished;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;

class CommandWatcher extends Watcher
{
    /**
     * Registra o ouvinte de eventos para o comando terminado.
     *
     * Este método registra um ouvinte para o evento `CommandFinished`, que
     * chama o método `recordCommand` quando o evento é disparado.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(CommandFinished::class, [$this, 'recordCommand']);
    }

    /**
     * Registra as informações do comando terminado.
     *
     * Este método cria uma entrada de monitoramento com base nos detalhes
     * do comando que foi concluído e a envia para o sistema de monitoramento.
     * Se houver uma exceção, ela será registrada em um arquivo de log.
     *
     * @param  CommandFinished  $event O evento que contém detalhes do comando terminado.
     * @return void
     * @throws Exception Se ocorrer um erro ao criar ou gravar a entrada.
     */
    public function recordCommand(CommandFinished $event): void
    {
        try {
            if(!Monitoring::isEnabled()) return;

            if ($this->shouldIgnore($event)) return;

            $entry = IncomingEntry::make([
                'command' => $event->command ?? $event->input->getArguments()['command'] ?? 'default',
                'exit_code' => $event->exitCode,
                'arguments' => $event->input->getArguments(),
                'options' => $event->input->getOptions(),
            ]);

            Monitoring::recordCommand($entry);
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Verifica se o comando deve ser ignorado.
     *
     * Este método verifica se o comando concluído está na lista de comandos
     * a serem ignorados. Se estiver, o método retorna verdadeiro.
     *
     * @param  CommandFinished  $event O evento do comando terminado.
     * @return bool Retorna verdadeiro se o comando deve ser ignorado, falso caso contrário.
     */
    private function shouldIgnore($event): bool
    {
        return in_array($event->command, array_merge($this->options['ignore'] ?? [], [
            'schedule:run',
            'schedule:finish',
            'package:discover',
        ]));
    }
}
=======
<?php

namespace RiseTechApps\Monitoring\Watchers;

use Exception;
use Illuminate\Console\Events\CommandFinished;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;

class CommandWatcher extends Watcher
{
    /**
     * Registra o ouvinte de eventos para o comando terminado.
     *
     * Este método registra um ouvinte para o evento `CommandFinished`, que
     * chama o método `recordCommand` quando o evento é disparado.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(CommandFinished::class, [$this, 'recordCommand']);
    }

    /**
     * Registra as informações do comando terminado.
     *
     * Este método cria uma entrada de monitoramento com base nos detalhes
     * do comando que foi concluído e a envia para o sistema de monitoramento.
     * Se houver uma exceção, ela será registrada em um arquivo de log.
     *
     * @param  CommandFinished  $event O evento que contém detalhes do comando terminado.
     * @return void
     * @throws Exception Se ocorrer um erro ao criar ou gravar a entrada.
     */
    public function recordCommand(CommandFinished $event): void
    {
        try {
            if(!Monitoring::isEnabled()) return;

            if ($this->shouldIgnore($event)) return;

            $entry = IncomingEntry::make([
                'command' => $event->command ?? $event->input->getArguments()['command'] ?? 'default',
                'exit_code' => $event->exitCode,
                'arguments' => $event->input->getArguments(),
                'options' => $event->input->getOptions(),
            ]);

            Monitoring::recordCommand($entry);
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Verifica se o comando deve ser ignorado.
     *
     * Este método verifica se o comando concluído está na lista de comandos
     * a serem ignorados. Se estiver, o método retorna verdadeiro.
     *
     * @param  CommandFinished  $event O evento do comando terminado.
     * @return bool Retorna verdadeiro se o comando deve ser ignorado, falso caso contrário.
     */
    private function shouldIgnore($event): bool
    {
        return in_array($event->command, array_merge($this->options['ignore'] ?? [], [
            'schedule:run',
            'schedule:finish',
            'package:discover',
        ]));
    }
}
>>>>>>> origin/main
