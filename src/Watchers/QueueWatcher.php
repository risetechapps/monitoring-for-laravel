<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

class QueueWatcher extends Watcher
{
    /**
     * Registra os ouvintes para os eventos de fila.
     *
     * Este método configura ouvintes para os eventos `WorkerStopping` e `Looping` da fila.
     * O ouvinte para o evento `WorkerStopping` cria um arquivo que indica que a fila está parando.
     * O ouvinte para o evento `Looping` verifica se a fila está parando e exclui o arquivo de parada, se necessário.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        // Ouvinte para o evento WorkerStopping
        Queue::stopping(function (WorkerStopping $event) {
            if (is_file(storage_path('framework/queue_stopping'))) {
                return 0;
            } else {
                file_put_contents(storage_path('framework/queue_stopping'), Carbon::now()->timestamp);
            }
            return true;
        });

        // Ouvinte para o evento Looping
        Queue::looping(function (Looping $event) {
            try {
                if (is_file(storage_path('framework/queue_stopping'))) {
                    $file = file_get_contents(storage_path('framework/queue_stopping'));

                    $time = Carbon::createFromTimestamp($file)->toDateTimeString();

                    File::delete(storage_path('framework/queue_stopping'));
                }
            } catch (\Exception $exception) {
                loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
            }
            return true;
        });
    }
}
