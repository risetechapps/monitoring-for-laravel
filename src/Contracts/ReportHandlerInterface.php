<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Contracts;

/**
 * Interface para handlers de relatório customizados.
 *
 * Implemente esta interface para criar notificações de relatório
 * em canais não suportados nativamente ou sobrescrever o comportamento padrão.
 */
interface ReportHandlerInterface
{
    /**
     * Envia o relatório.
     *
     * @param array $report Dados completos do relatório
     * @param string $html HTML renderizado do relatório (quando aplicável)
     * @param array $config Configuração específica do handler
     * @return bool True se o relatório foi enviado com sucesso
     */
    public function send(array $report, string $html, array $config = []): bool;

    /**
     * Verifica se o handler está configurado e pode enviar relatórios.
     *
     * @param array $config Configuração do handler
     * @return bool
     */
    public function isConfigured(array $config = []): bool;

    /**
     * Retorna o nome identificador do handler.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Retorna os canais suportados por este handler.
     *
     * @return array
     */
    public function getSupportedChannels(): array;
}
