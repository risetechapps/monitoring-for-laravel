<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Mail\Events\MessageSent;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\AbstractPart;

class MailWatcher extends Watcher
{

    /**
     * Registra o ouvinte para o evento de e-mail enviada.
     *
     * Este método configura um ouvinte para o evento `MessageSent`, que é acionado
     * quando uma e-mail é enviado.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        $app['events']->listen(MessageSent::class, [$this, 'recordMail']);
    }

    public function recordMail(MessageSent $event): void
    {

        try {

            if(!Monitoring::isEnabled()) return;

            $entry = IncomingEntry::make([
                'mailable' => $this->getMailable($event),
                'queued' => $this->getQueuedStatus($event),
                'from' => $this->formatAddresses($event->message->getFrom()),
                'replyTo' => $this->formatAddresses($event->message->getReplyTo()),
                'to' => $this->formatAddresses($event->message->getTo()),
                'cc' => $this->formatAddresses($event->message->getCc()),
                'bcc' => $this->formatAddresses($event->message->getBcc()),
                'subject' => $event->message->getSubject(),
                'html' => $event->message->getBody() instanceof AbstractPart ? ($event->message->getHtmlBody() ?? $event->message->getTextBody()) : $event->message->getBody(),
                'raw' => $event->message->toString(),
            ]);

            Monitoring::recordMail($entry);

        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    protected function getMailable($event): mixed
    {
        if (isset($event->data['__laravel_notification'])) {
            return $event->data['__laravel_notification'];
        }

        return '';
    }

    protected function getQueuedStatus($event): mixed
    {
        if (isset($event->data['__laravel_notification_queued'])) {
            return $event->data['__laravel_notification_queued'];
        }

        return false;
    }

    protected function formatAddresses(?array $addresses): ?array
    {
        if (is_null($addresses)) {
            return null;
        }

        return collect($addresses)->flatMap(function ($address, $key) {
            if ($address instanceof Address) {
                return [$address->getAddress() => $address->getName()];
            }

            return [$key => $address];
        })->all();
    }
}
