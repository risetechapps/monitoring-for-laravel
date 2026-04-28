<?php

/**
 * Exemplo de Handler Customizado para Alertas do Monitoring
 *
 * Este exemplo demonstra como criar um handler para enviar alertas
 * via Telegram quando eventos críticos são detectados.
 *
 * Passos para usar:
 * 1. Copie este arquivo para app/Monitoring/Handlers/TelegramAlertHandler.php
 * 2. Registre o handler em um ServiceProvider
 * 3. Configure as variáveis de ambiente
 */

declare(strict_types=1);

namespace App\Monitoring\Handlers;

use Illuminate\Support\Facades\Http;
use RiseTechApps\Monitoring\Contracts\AlertHandlerInterface;
use RiseTechApps\Monitoring\Entry\IncomingEntry;

/**
 * Handler de alertas para Telegram.
 *
 * Envia notificações de alertas críticas (exceções, jobs falhos,
 * queries lentas, requisições lentas) via mensagens do Telegram.
 */
class TelegramAlertHandler implements AlertHandlerInterface
{
    /**
     * Envia a notificação de alerta para o Telegram.
     *
     * @param string $type Tipo do alerta (exception, slow_request, etc.)
     * @param IncomingEntry $entry Entrada do monitoring que disparou o alerta
     * @param array $config Configuração específica do handler
     * @return bool True se o alerta foi enviado com sucesso
     */
    public function send(string $type, IncomingEntry $entry, array $config = []): bool
    {
        $botToken = $config['bot_token'] ?? config('services.telegram.bot_token');
        $chatId = $config['chat_id'] ?? config('services.telegram.chat_id');

        if (empty($botToken) || empty($chatId)) {
            \Log::warning('TelegramAlertHandler: bot_token ou chat_id não configurados');
            return false;
        }

        $message = $this->formatMessage($type, $entry);

        try {
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if (!$response->successful()) {
                \Log::error('TelegramAlertHandler: Falha ao enviar mensagem', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            \Log::error('TelegramAlertHandler: Exceção ao enviar mensagem', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verifica se o handler está configurado e pode enviar notificações.
     *
     * @param array $config Configuração do handler
     * @return bool
     */
    public function isConfigured(array $config = []): bool
    {
        $botToken = $config['bot_token'] ?? config('services.telegram.bot_token');
        $chatId = $config['chat_id'] ?? config('services.telegram.chat_id');

        return !empty($botToken) && !empty($chatId);
    }

    /**
     * Retorna o nome identificador do handler.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'telegram';
    }

    /**
     * Formata a mensagem de alerta para o Telegram.
     *
     * @param string $type
     * @param IncomingEntry $entry
     * @return string
     */
    private function formatMessage(string $type, IncomingEntry $entry): string
    {
        $content = $entry->content;
        $appName = config('app.name', 'Laravel App');
        $env = config('app.env', 'production');

        $header = "<b>🚨 {$appName}</b> [{$env}]\n";
        $header .= str_repeat('─', 30) . "\n\n";

        $body = match ($type) {
            'exception' => $this->formatExceptionMessage($content),
            'request' => $this->formatSlowRequestMessage($content),
            'job' => $this->formatFailedJobMessage($content),
            'query' => $this->formatSlowQueryMessage($content),
            default => "<b>Alerta:</b> {$type}\n" .
                "<pre>" . json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>",
        };

        $footer = "\n" . str_repeat('─', 30) . "\n";
        $footer .= "<i>Horário: " . now()->format('d/m/Y H:i:s') . "</i>";

        return $header . $body . $footer;
    }

    /**
     * Formata mensagem de exceção.
     *
     * @param array $content
     * @return string
     */
    private function formatExceptionMessage(array $content): string
    {
        $message = "<b>❌ Exceção em Produção</b>\n\n";
        $message .= "<b>Classe:</b> <code>" . ($content['class'] ?? 'N/A') . "</code>\n";
        $message .= "<b>Mensagem:</b> " . $this->escapeHtml($content['message'] ?? 'N/A') . "\n";
        $message .= "<b>Arquivo:</b> <code>" . ($content['file'] ?? 'N/A') . ":" . ($content['line'] ?? 'N/A') . "</code>\n";

        if (!empty($content['url'])) {
            $message .= "<b>URL:</b> " . $content['url'] . "\n";
        }

        return $message;
    }

    /**
     * Formata mensagem de requisição lenta.
     *
     * @param array $content
     * @return string
     */
    private function formatSlowRequestMessage(array $content): string
    {
        $duration = $content['duration'] ?? 'N/A';
        $threshold = config('monitoring.alerts.thresholds.slow_request_ms', 5000);

        $message = "<b>⏱️ Requisição Lenta</b>\n\n";
        $message .= "<b>URI:</b> <code>" . ($content['method'] ?? 'GET') . " " . ($content['uri'] ?? 'N/A') . "</code>\n";
        $message .= "<b>Duração:</b> {$duration}ms (threshold: {$threshold}ms)\n";
        $message .= "<b>Controller:</b> <code>" . ($content['controller_action'] ?? 'N/A') . "</code>\n";

        if (!empty($content['user'])) {
            $message .= "<b>Usuário:</b> " . ($content['user']['email'] ?? $content['user']['id'] ?? 'N/A') . "\n";
        }

        return $message;
    }

    /**
     * Formata mensagem de job falho.
     *
     * @param array $content
     * @return string
     */
    private function formatFailedJobMessage(array $content): string
    {
        $message = "<b>🔥 Job Falhou</b>\n\n";
        $message .= "<b>Job:</b> <code>" . ($content['displayName'] ?? $content['name'] ?? 'N/A') . "</code>\n";
        $message .= "<b>Conexão:</b> " . ($content['connection'] ?? 'N/A') . "\n";
        $message .= "<b>Fila:</b> " . ($content['queue'] ?? 'default') . "\n";

        if (!empty($content['exception'])) {
            $exception = $content['exception'];
            $message .= "\n<b>Erro:</b> " . $this->escapeHtml($exception['message'] ?? 'N/A') . "\n";
            $message .= "<b>Arquivo:</b> <code>" . ($exception['file'] ?? 'N/A') . ":" . ($exception['line'] ?? 'N/A') . "</code>\n";
        }

        return $message;
    }

    /**
     * Formata mensagem de query lenta.
     *
     * @param array $content
     * @return string
     */
    private function formatSlowQueryMessage(array $content): string
    {
        $time = $content['time_ms'] ?? 'N/A';
        $threshold = config('monitoring.alerts.thresholds.slow_query_threshold_ms', 100);

        $sql = $content['sql'] ?? 'N/A';
        $sql = strlen($sql) > 200 ? substr($sql, 0, 200) . '...' : $sql;

        $message = "<b>🐢 Query Lenta</b>\n\n";
        $message .= "<b>Tempo:</b> {$time}ms (threshold: {$threshold}ms)\n";
        $message .= "<b>Conexão:</b> " . ($content['connection'] ?? 'N/A') . "\n";
        $message .= "<b>SQL:</b> <pre>{$sql}</pre>\n";

        if (!empty($content['caller_file'])) {
            $message .= "<b>Origem:</b> <code>" . $content['caller_file'] . ":" . ($content['caller_line'] ?? 'N/A') . "</code>\n";
        }

        return $message;
    }

    /**
     * Escapa caracteres especiais do HTML para o Telegram.
     *
     * @param string $text
     * @return string
     */
    private function escapeHtml(string $text): string
    {
        return str_replace(
            ['&', '<', '>', '"'],
            ['&amp;', '&lt;', '&gt;', '&quot;'],
            $text
        );
    }
}

/**
 * ServiceProvider de exemplo para registrar o handler.
 *
 * Crie este arquivo em app/Providers/MonitoringServiceProvider.php
 * e registre-o em config/app.php na seção 'providers'.
 */

/*
<?php

namespace App\Providers;

use App\Monitoring\Handlers\TelegramAlertHandler;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\Monitoring\Services\Alerts\AlertService;

class MonitoringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Registra o handler de alertas do Telegram
        AlertService::registerHandler('telegram', new TelegramAlertHandler());

        // Você pode registrar múltiplos handlers
        // AlertService::registerHandler('pagerduty', new PagerDutyAlertHandler());
        // AlertService::registerHandler('teams', new MicrosoftTeamsAlertHandler());

        // Para desabilitar notificações padrão e usar apenas customizadas:
        // AlertService::disableDefaultNotifications();
    }
}
*/

/**
 * Exemplo de configuração no .env:
 *
 * MONITORING_ALERTS_ENABLED=true
 * MONITORING_ALERTS_EMAIL_ENABLED=false
 *
 * # Configurações do Telegram
 * TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrSTUvwxyz
 * TELEGRAM_CHAT_ID=-1001234567890
 *
 * Para obter o chat_id, use o endpoint:
 * https://api.telegram.org/bot{TOKEN}/getUpdates
 * Após enviar uma mensagem no grupo/canal, procure por "chat": {"id": ...}
 */

/**
 * Exemplo de configuração no config/services.php:
 *
 * 'telegram' => [
 *     'bot_token' => env('TELEGRAM_BOT_TOKEN'),
 *     'chat_id'   => env('TELEGRAM_CHAT_ID'),
 * ],
 */
