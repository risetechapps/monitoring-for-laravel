<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\ExtractTags;
use RiseTechApps\Monitoring\Services\FormatModel;
use Illuminate\Support\Str;

class NotificationWatcher extends Watcher
{
    /**
     * Registra o ouvinte para o evento de notificação enviada.
     *
     * Este método configura um ouvinte para o evento `NotificationSent`, que é acionado
     * quando uma notificação é enviada.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(NotificationSent::class, [$this, 'recordNotification']);
    }

    /**
     * Registra o evento de notificação enviada.
     *
     * Este método cria uma entrada para a notificação enviada e a registra no sistema de monitoramento.
     *
     * @param  NotificationSent  $event O evento de notificação enviada.
     * @return void
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada de monitoramento.
     */
    public function recordNotification(NotificationSent $event): void
    {
        try {

            if(!Monitoring::isEnabled()) return;

            $entry = IncomingEntry::make([
                'notification' => get_class($event->notification),
                'queued' => in_array(ShouldQueue::class, class_implements($event->notification)),
                'notifiable' => $this->formatNotifiable($event->notifiable),
                'channel' => $event->channel,
                'response' => $event->response,
                'uuid' => $event->notification->id
            ])->tags($this->tags($event));

            Monitoring::recordNotification($entry);
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Obtém tags para a notificação.
     *
     * Este método gera tags associadas à notificação com base no notifiable e na notificação.
     *
     * @param  NotificationSent  $event O evento de notificação enviada.
     * @return array As tags associadas à notificação.
     */
    private function tags(NotificationSent $event): array
    {
        return array_merge([
            $this->formatNotifiable($event->notifiable),
        ], ExtractTags::from($event->notification));
    }

    /**
     * Formata a entidade notifiable.
     *
     * Este método converte a entidade notifiable em uma representação de string.
     * Se o notifiable for um modelo Eloquent, ele usa um formato específico. Se for
     * um `AnonymousNotifiable`, ele formata as rotas. Caso contrário, retorna o nome da classe.
     *
     * @param  mixed  $notifiable A entidade notifiable.
     * @return string A representação formatada do notifiable.
     */
    private function formatNotifiable($notifiable): string
    {
        if ($notifiable instanceof Model) {
            return FormatModel::given($notifiable);
        } elseif ($notifiable instanceof AnonymousNotifiable) {
            $routes = array_map(function ($route) {
                return is_array($route) ? implode(',', $route) : $route;
            }, $notifiable->routes);

            return 'Anonymous:' . implode(',', $routes);
        }

        return get_class($notifiable);
    }
}
