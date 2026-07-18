<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Events;

/**
 * Evento disparado quando um relatório é gerado e pronto para envio.
 *
 * Ouvindo este evento, você pode:
 * - Enviar relatórios customizados (Telegram, PagerDuty, etc.)
 * - Salvar em sistemas externos
 * - Modificar o comportamento padrão
 * - Usar suas próprias notificações
 */
class ReportGenerated
{
    /**
     * Indica se o relatório foi processado (evita duplicidade).
     *
     * @var bool
     */
    public bool $handled = false;

    /**
     * Indica se notificações padrão devem ser suprimidas.
     *
     * @var bool
     */
    public bool $suppressDefault = false;

    public function __construct(public array $report, public string $html, public array $channels = ['email']
    )
    {
    }

    /**
     * Marca o relatório como processado.
     */
    public function markAsHandled(): void
    {
        $this->handled = true;
    }

    /**
     * Suprime notificações padrão (usa apenas customizações).
     */
    public function suppressDefaultNotifications(): void
    {
        $this->suppressDefault = true;
    }

    /**
     * Obtém o período do relatório.
     */
    public function getPeriod(): string
    {
        return $this->report['period'] ?? 'unknown';
    }

    /**
     * Obtém o label do período.
     */
    public function getPeriodLabel(): string
    {
        return $this->report['period_label'] ?? 'Relatório';
    }

    /**
     * Obtém o resumo do relatório.
     */
    public function getSummary(): array
    {
        return $this->report['summary'] ?? [];
    }
}
