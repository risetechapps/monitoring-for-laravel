<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envia as notificações de alerta (Slack/Discord/Email) FORA da request.
 *
 * Antes, o AlertService disparava os 2 POST de webhook + o e-mail de forma
 * SÍNCRONA dentro da request que gerou o alerta — atrasando o envio da resposta
 * ao usuário e piorando justamente as requisições já lentas. Agora o AlertService
 * apenas enfileira; o I/O externo acontece no worker.
 */
class SendMonitoringAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Não re-tentar: falha de entrega de alerta não deve gerar tempestade de jobs. */
    public int $tries = 1;

    public function __construct(
        public readonly string $message,
        public readonly string $subject,
    ) {}

    public function handle(): void
    {
        $this->sendSlack();
        $this->sendDiscord();
        $this->sendEmail();
    }

    private function sendSlack(): void
    {
        $webhook = config('monitoring.alerts.slack_webhook');

        if (!$webhook) {
            return;
        }

        try {
            Http::timeout(10)->post($webhook, [
                'text'       => $this->message,
                'username'   => 'Monitoring Alerts',
                'icon_emoji' => ':warning:',
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar alerta Slack', ['error' => $e->getMessage()]);
        }
    }

    private function sendDiscord(): void
    {
        $webhook = config('monitoring.alerts.discord_webhook');

        if (!$webhook) {
            return;
        }

        try {
            Http::timeout(10)->post($webhook, [
                'content'  => $this->message,
                'username' => 'Monitoring Alerts',
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar alerta Discord', ['error' => $e->getMessage()]);
        }
    }

    private function sendEmail(): void
    {
        $config = config('monitoring.alerts.email', []);

        if (!($config['enabled'] ?? false) || empty($config['to'])) {
            return;
        }

        try {
            Mail::raw($this->message, function ($mail) use ($config) {
                $mail->to($config['to'])
                    ->from($config['from'])
                    ->subject("[MONITORING] {$this->subject}");
            });
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar alerta Email', ['error' => $e->getMessage()]);
        }
    }
}
