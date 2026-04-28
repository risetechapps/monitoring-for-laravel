<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services\Alerts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RiseTechApps\Monitoring\Contracts\AlertHandlerInterface;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Events\AlertTriggered;

class AlertService
{
    /** Handlers customizados registrados */
    private static array $customHandlers = [];

    /** Desabilita notificações padrão */
    private static bool $disableDefaultNotifications = false;

    /**
     * Registra um handler de alerta customizado.
     */
    public static function registerHandler(string $name, AlertHandlerInterface $handler): void
    {
        self::$customHandlers[$name] = $handler;
    }

    /**
     * Remove um handler registrado.
     */
    public static function unregisterHandler(string $name): void
    {
        unset(self::$customHandlers[$name]);
    }

    /**
     * Desabilita notificações padrão (Slack, Discord, Email).
     * Útil quando você quer usar apenas notificações customizadas.
     */
    public static function disableDefaultNotifications(): void
    {
        self::$disableDefaultNotifications = true;
    }

    /**
     * Habilita notificações padrão.
     */
    public static function enableDefaultNotifications(): void
    {
        self::$disableDefaultNotifications = false;
    }

    /**
     * Verifica se deve enviar alerta para uma entrada.
     */
    public function checkAndAlert(IncomingEntry $entry, string $type): void
    {
        if (!config('monitoring.alerts.enabled', false)) {
            return;
        }

        $thresholds = config('monitoring.alerts.thresholds', []);
        $cooldown = config('monitoring.alerts.cooldown_minutes', 5);

        $shouldAlert = match ($type) {
            'exception' => true,
            'request' => isset($entry->content['duration']) && $entry->content['duration'] >= ($thresholds['slow_request_ms'] ?? 5000),
            'job' => isset($entry->content['status']) && $entry->content['status'] === 'failed',
            'query' => isset($entry->content['time_ms']) && $entry->content['time_ms'] >= ($thresholds['slow_query_threshold_ms'] ?? 100),
            default => false,
        };

        if (!$shouldAlert) {
            return;
        }

        if (!$this->shouldAlert($type, $cooldown)) {
            return;
        }

        // Dispara evento para listeners externos
        $event = new AlertTriggered($type, $entry, [
            'thresholds' => $thresholds,
            'timestamp' => now(),
        ]);
        event($event);

        // Se o evento foi marcado como handled, não processa os handlers padrão
        if ($event->handled) {
            return;
        }

        // Processa handlers customizados primeiro
        $this->processCustomHandlers($type, $entry);

        // Processa notificações padrão (se não desabilitadas)
        if (!self::$disableDefaultNotifications) {
            $this->processDefaultNotifications($type, $entry, $thresholds);
        }
    }

    /**
     * Processa handlers customizados registrados.
     */
    private function processCustomHandlers(string $type, IncomingEntry $entry): void
    {
        $handlersConfig = config('monitoring.alerts.custom_handlers', []);

        foreach (self::$customHandlers as $name => $handler) {
            $config = $handlersConfig[$name] ?? [];

            if (!$handler->isConfigured($config)) {
                continue;
            }

            try {
                $handler->send($type, $entry, $config);
            } catch (\Exception $e) {
                \Log::error("Falha no handler de alerta customizado: {$name}", [
                    'error' => $e->getMessage(),
                    'type' => $type,
                ]);
            }
        }
    }

    /**
     * Processa notificações padrão.
     */
    private function processDefaultNotifications(string $type, IncomingEntry $entry, array $thresholds): void
    {
        match ($type) {
            'exception' => $this->alertException($entry, $thresholds),
            'request' => $this->alertSlowRequest($entry),
            'job' => $this->alertFailedJob($entry, $thresholds),
            'query' => $this->alertSlowQuery($entry, $thresholds),
            default => null,
        };
    }

    /**
     * Verifica se pode enviar alerta (cooldown).
     */
    private function shouldAlert(string $type, int $cooldownMinutes): bool
    {
        $key = "monitoring_alert_{$type}";
        $lastAlert = Cache::get($key);

        if ($lastAlert && now()->diffInMinutes($lastAlert) < $cooldownMinutes) {
            return false;
        }

        Cache::put($key, now(), now()->addMinutes($cooldownMinutes));
        return true;
    }

    /**
     * Envia alerta de exceção.
     */
    private function alertException(IncomingEntry $entry, array $thresholds): void
    {
        $content = $entry->content;
        $message = "🚨 *Exceção em Produção*\n\n";
        $message .= "*Classe:* {$content['class']}\n";
        $message .= "*Mensagem:* {$content['message']}\n";
        $message .= "*Arquivo:* {$content['file']}:{$content['line']}\n";
        $message .= "*Horário:* " . now()->format('Y-m-d H:i:s') . "\n";

        $this->sendToAllChannels($message, 'Exceção em Produção');
    }

    /**
     * Envia alerta de requisição lenta.
     */
    private function alertSlowRequest(IncomingEntry $entry): void
    {
        $content = $entry->content;
        $duration = $content['duration'] ?? 'N/A';

        $message = "⏱️ *Requisição Lenta Detectada*\n\n";
        $message .= "*URI:* {$content['uri']}\n";
        $message .= "*Método:* {$content['method']}\n";
        $message .= "*Duração:* {$duration}ms\n";
        $message .= "*Controller:* " . ($content['controller_action'] ?? 'N/A') . "\n";

        $this->sendToAllChannels($message, 'Requisição Lenta');
    }

    /**
     * Envia alerta de job falho.
     */
    private function alertFailedJob(IncomingEntry $entry, array $thresholds): void
    {
        $content = $entry->content;

        $message = "🔥 *Job Falhou*\n\n";
        $message .= "*Job:* {$content['displayName']}\n";
        $message .= "*Conexão:* {$content['connection']}\n";
        $message .= "*Fila:* {$content['queue']}\n";

        if (isset($content['exception'])) {
            $message .= "*Erro:* {$content['exception']['message']}\n";
        }

        $this->sendToAllChannels($message, 'Job Falhou');
    }

    /**
     * Envia alerta de query lenta.
     */
    private function alertSlowQuery(IncomingEntry $entry, array $thresholds): void
    {
        $content = $entry->content;

        $message = "🐢 *Query Lenta*\n\n";
        $message .= "*Tempo:* {$content['time_ms']}ms\n";
        $message .= "*Conexão:* {$content['connection']}\n";
        $message .= "*SQL:* " . substr($content['sql'], 0, 200) . "...\n";
        $message .= "*Arquivo:* " . ($content['caller_file'] ?? 'N/A') . ":" . ($content['caller_line'] ?? 'N/A') . "\n";

        $this->sendToAllChannels($message, 'Query Lenta');
    }

    /**
     * Envia mensagem para todos os canais configurados.
     */
    private function sendToAllChannels(string $message, string $subject): void
    {
        $this->sendSlack($message);
        $this->sendDiscord($message);
        $this->sendEmail($message, $subject);
    }

    /**
     * Envia para Slack via webhook.
     */
    private function sendSlack(string $message): void
    {
        $webhook = config('monitoring.alerts.slack_webhook');
        if (!$webhook) {
            return;
        }

        try {
            Http::post($webhook, [
                'text' => $message,
                'username' => 'Monitoring Alerts',
                'icon_emoji' => ':warning:',
            ]);
        } catch (\Exception $e) {
            \Log::error('Falha ao enviar alerta Slack', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Envia para Discord via webhook.
     */
    private function sendDiscord(string $message): void
    {
        $webhook = config('monitoring.alerts.discord_webhook');
        if (!$webhook) {
            return;
        }

        try {
            Http::post($webhook, [
                'content' => $message,
                'username' => 'Monitoring Alerts',
            ]);
        } catch (\Exception $e) {
            \Log::error('Falha ao enviar alerta Discord', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Envia email.
     */
    private function sendEmail(string $message, string $subject): void
    {
        $config = config('monitoring.alerts.email', []);
        if (!($config['enabled'] ?? false) || empty($config['to'])) {
            return;
        }

        try {
            Mail::raw($message, function ($mail) use ($config, $subject) {
                $mail->to($config['to'])
                    ->from($config['from'])
                    ->subject("[MONITORING] {$subject}");
            });
        } catch (\Exception $e) {
            \Log::error('Falha ao enviar alerta Email', ['error' => $e->getMessage()]);
        }
    }
}
