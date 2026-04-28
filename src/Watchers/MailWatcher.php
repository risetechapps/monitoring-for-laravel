<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Mail\Events\MessageSent;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\AbstractPart;

class MailWatcher extends Watcher
{
    public function register($app): void
    {
        $app['events']->listen(MessageSent::class, [$this, 'recordMail']);
    }

    public function recordMail(MessageSent $event): void
    {
        try {
            if (! Monitoring::isEnabled()) return;

            if ($this->shouldIgnore($event)) {
                return;
            }

            $html = $event->message->getBody() instanceof AbstractPart
                ? ($event->message->getHtmlBody() ?? $event->message->getTextBody())
                : $event->message->getBody();

            // Trunca o body do e-mail para no máximo 10KB
            if (is_string($html) && strlen($html) > 10240) {
                $html = substr($html, 0, 10240) . '... [truncated]';
            }

            $entry = IncomingEntry::make([
                'mailable' => $this->getMailable($event),
                'queued'   => $this->getQueuedStatus($event),
                'from'     => $this->formatAddresses($event->message->getFrom()),
                'replyTo'  => $this->formatAddresses($event->message->getReplyTo()),
                'to'       => $this->formatAddresses($event->message->getTo()),
                'cc'       => $this->formatAddresses($event->message->getCc()),
                'bcc'      => $this->formatAddresses($event->message->getBcc()),
                'subject'  => $event->message->getSubject(),
                'html'     => $html,
                // 'raw' removido — pode ser muito grande e duplica o html
            ]);

            Monitoring::recordMail($entry);
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Verifica se o e-mail deve ser ignorado.
     *
     * @param  MessageSent  $event O evento de e-mail enviado.
     * @return bool Retorna true se o e-mail deve ser ignorado.
     */
    private function shouldIgnore(MessageSent $event): bool
    {
        // Verifica classes Mailable específicas
        $ignoredMailables = $this->options['ignore_mailables'] ?? [];
        $mailable = $this->getMailable($event);
        if ($mailable) {
            foreach ($ignoredMailables as $ignoredClass) {
                if ($mailable === $ignoredClass || is_a($mailable, $ignoredClass, true)) {
                    return true;
                }
            }
        }

        // Verifica assuntos que contêm textos ignorados
        $ignoredSubjects = $this->options['ignore_subjects_containing'] ?? [];
        $subject = strtolower($event->message->getSubject() ?? '');
        foreach ($ignoredSubjects as $ignoredText) {
            if (str_contains($subject, strtolower($ignoredText))) {
                return true;
            }
        }

        // Verifica remetentes ignorados
        $ignoredFrom = $this->options['ignore_from_addresses'] ?? [];
        $from = $event->message->getFrom() ?? [];
        foreach ($from as $address) {
            if ($address instanceof Address) {
                if (in_array($address->getAddress(), $ignoredFrom)) {
                    return true;
                }
            }
        }

        // Verifica destinatários ignorados
        $ignoredTo = $this->options['ignore_to_addresses'] ?? [];
        $to = $event->message->getTo() ?? [];
        foreach ($to as $address) {
            if ($address instanceof Address) {
                if (in_array($address->getAddress(), $ignoredTo)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function getMailable($event): mixed
    {
        return $event->data['__laravel_notification'] ?? '';
    }

    protected function getQueuedStatus($event): mixed
    {
        return $event->data['__laravel_notification_queued'] ?? false;
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
