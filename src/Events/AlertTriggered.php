<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Events;

use RiseTechApps\Monitoring\Entry\IncomingEntry;

/**
 * Evento disparado quando um alerta é acionado.
 *
 * Ouvindo este evento, você pode:
 * - Enviar notificações customizadas (Telegram, PagerDuty, etc.)
 * - Logar em sistemas externos
 * - Executar ações corretivas automáticas
 * - Modificar o comportamento padrão
 */
class AlertTriggered
{
    /**
     * Tipo do alerta.
     *
     * @var string
     */
    public string $type;

    /**
     * Entrada do monitoring que disparou o alerta.
     *
     * @var IncomingEntry
     */
    public IncomingEntry $entry;

    /**
     * Dados adicionais do contexto.
     *
     * @var array
     */
    public array $context;

    /**
     * Indica se o alerta foi processado (evita duplicidade).
     *
     * @var bool
     */
    public bool $handled = false;

    public function __construct(string $type, IncomingEntry $entry, array $context = [])
    {
        $this->type = $type;
        $this->entry = $entry;
        $this->context = $context;
    }

    /**
     * Marca o alerta como processado.
     */
    public function markAsHandled(): void
    {
        $this->handled = true;
    }

    /**
     * Formata uma mensagem padrão para o alerta.
     */
    public function formatMessage(): string
    {
        return match ($this->type) {
            'exception' => $this->formatExceptionMessage(),
            'slow_request' => $this->formatSlowRequestMessage(),
            'failed_job' => $this->formatFailedJobMessage(),
            'slow_query' => $this->formatSlowQueryMessage(),
            default => "Alerta: {$this->type}",
        };
    }

    private function formatExceptionMessage(): string
    {
        $content = $this->entry->content;
        return "🚨 Exceção: {$content['class']}\n" .
               "Mensagem: {$content['message']}\n" .
               "Arquivo: {$content['file']}:{$content['line']}";
    }

    private function formatSlowRequestMessage(): string
    {
        $content = $this->entry->content;
        return "⏱️ Requisição Lenta: {$content['method']} {$content['uri']}\n" .
               "Duração: {$content['duration']}ms";
    }

    private function formatFailedJobMessage(): string
    {
        $content = $this->entry->content;
        return "🔥 Job Falhou: {$content['displayName']}\n" .
               "Conexão: {$content['connection']} / {$content['queue']}";
    }

    private function formatSlowQueryMessage(): string
    {
        $content = $this->entry->content;
        return "🐢 Query Lenta: {$content['time_ms']}ms\n" .
               "SQL: " . substr($content['sql'], 0, 100) . "...";
    }
}
