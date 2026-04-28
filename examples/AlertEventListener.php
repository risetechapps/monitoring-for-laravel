<?php

/**
 * Exemplo de Event Listener para Alertas do Monitoring
 *
 * Este exemplo demonstra como ouvir o evento AlertTriggered
 * para executar ações customizadas quando alertas são disparados.
 *
 * Passos para usar:
 * 1. Crie o listener em app/Listeners/SendPagerDutyNotification.php
 * 2. Registre o listener no EventServiceProvider
 * 3. Configure as variáveis de ambiente
 */

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Events\AlertTriggered;

/**
 * Listener que envia notificações para o PagerDuty quando
 * exceções críticas são detectadas.
 */
class SendPagerDutyNotification
{
    /**
     * Handle the event.
     *
     * @param AlertTriggered $event
     * @return void
     */
    public function handle(AlertTriggered $event): void
    {
        // Apenas processa exceções - ignorar outros tipos
        if ($event->type !== 'exception') {
            return;
        }

        $content = $event->entry->content;
        $exceptionClass = $content['class'] ?? 'UnknownException';

        // Ignorar exceções específicas que não são críticas
        $ignoredExceptions = [
            \Illuminate\Validation\ValidationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        ];

        foreach ($ignoredExceptions as $ignored) {
            if ($exceptionClass === $ignored || is_subclass_of($exceptionClass, $ignored)) {
                return;
            }
        }

        // Configurações do PagerDuty
        $routingKey = config('services.pagerduty.routing_key');
        $webhookUrl = config('services.pagerduty.webhook_url');

        if (empty($routingKey) || empty($webhookUrl)) {
            Log::warning('SendPagerDutyNotification: Configuração incompleta');
            return;
        }

        // Determina a severidade baseada na classe da exceção
        $severity = $this->determineSeverity($exceptionClass);

        // Monta o payload do PagerDuty
        $payload = [
            'routing_key' => $routingKey,
            'event_action' => 'trigger',
            'payload' => [
                'summary' => "[$exceptionClass] " . ($content['message'] ?? 'No message'),
                'severity' => $severity,
                'source' => $content['file'] ?? 'unknown',
                'component' => config('app.name', 'Laravel App'),
                'group' => 'monitoring-alerts',
                'class' => $exceptionClass,
                'custom_details' => [
                    'message' => $content['message'] ?? null,
                    'file' => $content['file'] ?? null,
                    'line' => $content['line'] ?? null,
                    'url' => $content['url'] ?? null,
                    'user' => $content['user'] ?? null,
                    'trace' => isset($content['trace']) ? substr($content['trace'], 0, 2000) : null,
                ],
            ],
            'links' => [],
        ];

        // Adiciona link para o painel de monitoramento se disponível
        if (!empty($content['id'])) {
            $payload['links'][] = [
                'href' => url("/monitoring/{$content['id']}"),
                'text' => 'Ver no Monitoring',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($webhookUrl, $payload);

            if (!$response->successful()) {
                Log::error('SendPagerDutyNotification: Falha ao enviar', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                return;
            }

            Log::info('SendPagerDutyNotification: Alerta enviado para PagerDuty', [
                'exception_class' => $exceptionClass,
                'dedup_key' => $response->json('dedup_key'),
            ]);

            // Opcional: Marca o evento como handled para pular notificações padrão
            // $event->markAsHandled();

        } catch (\Exception $e) {
            Log::error('SendPagerDutyNotification: Erro ao enviar notificação', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determina a severidade baseada na classe da exceção.
     *
     * @param string $exceptionClass
     * @return string
     */
    private function determineSeverity(string $exceptionClass): string
    {
        $criticalExceptions = [
            \PDOException::class,
            \RedisException::class,
            \OutOfMemoryException::class,
        ];

        $warningExceptions = [
            \RuntimeException::class,
            \InvalidArgumentException::class,
        ];

        foreach ($criticalExceptions as $critical) {
            if ($exceptionClass === $critical || is_subclass_of($exceptionClass, $critical)) {
                return 'critical';
            }
        }

        foreach ($warningExceptions as $warning) {
            if ($exceptionClass === $warning || is_subclass_of($exceptionClass, $warning)) {
                return 'warning';
            }
        }

        return 'error';
    }
}

/**
 * ServiceProvider de exemplo - Registro do Listener.
 *
 * Adicione ao EventServiceProvider (app/Providers/EventServiceProvider.php):
 *
 * protected $listen = [
 *     \RiseTechApps\Monitoring\Events\AlertTriggered::class => [
 *         \App\Listeners\SendPagerDutyNotification::class,
 *     ],
 * ];
 */

/**
 * Exemplo: Listener que grava em arquivo de log customizado.
 */

namespace App\Listeners;

use RiseTechApps\Monitoring\Events\AlertTriggered;

class LogAlertToCustomFile
{
    public function handle(AlertTriggered $event): void
    {
        $logPath = storage_path('logs/alerts-custom.log');

        $data = [
            'timestamp' => now()->toIso8601String(),
            'type' => $event->type,
            'entry_uuid' => $event->entry->uuid ?? null,
            'content' => $event->entry->content,
            'context' => $event->context,
        ];

        file_put_contents(
            $logPath,
            json_encode($data, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

/**
 * Exemplo: Listener que envia para Webhook customizado.
 */

namespace App\Listeners;

use Illuminate\Support\Facades\Http;
use RiseTechApps\Monitoring\Events\AlertTriggered;

class SendCustomWebhookNotification
{
    public function handle(AlertTriggered $event): void
    {
        $webhookUrl = config('services.custom_alerts.webhook_url');

        if (empty($webhookUrl)) {
            return;
        }

        Http::post($webhookUrl, [
            'event' => 'monitoring.alert',
            'data' => [
                'type' => $event->type,
                'message' => $event->formatMessage(),
                'timestamp' => $event->context['timestamp'] ?? now()->toIso8601String(),
                'app' => config('app.name'),
                'environment' => config('app.env'),
            ],
        ]);
    }
}

/**
 * Exemplo: Listener que filtra alertas por ambiente.
 */

namespace App\Listeners;

use RiseTechApps\Monitoring\Events\AlertTriggered;

class FilterAlertsByEnvironment
{
    public function handle(AlertTriggered $event): void
    {
        // Só envia alertas em produção
        if (config('app.env') !== 'production') {
            // Marca como handled para não processar notificações padrão
            $event->markAsHandled();
            return;
        }

        // Em staging/development, apenas loga em arquivo local
        if (in_array(config('app.env'), ['staging', 'development'])) {
            Log::channel('daily')->info('Alerta ignorado em ambiente não-produção', [
                'type' => $event->type,
                'message' => $event->formatMessage(),
            ]);

            $event->markAsHandled();
        }
    }
}

/**
 * Exemplo de configuração no config/services.php:
 *
 * 'pagerduty' => [
 *     'routing_key' => env('PAGERDUTY_ROUTING_KEY'),
 *     'webhook_url' => env('PAGERDUTY_WEBHOOK_URL', 'https://events.pagerduty.com/v2/enqueue'),
 * ],
 *
 * 'custom_alerts' => [
 *     'webhook_url' => env('CUSTOM_ALERTS_WEBHOOK_URL'),
 * ],
 */

/**
 * Exemplo de configuração no .env:
 *
 * PAGERDUTY_ROUTING_KEY=your-routing-key-here
 * PAGERDUTY_WEBHOOK_URL=https://events.pagerduty.com/v2/enqueue
 *
 * CUSTOM_ALERTS_WEBHOOK_URL=https://your-custom-service.com/webhooks/alerts
 */
