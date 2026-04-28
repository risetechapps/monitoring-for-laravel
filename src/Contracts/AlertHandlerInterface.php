<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Contracts;

use RiseTechApps\Monitoring\Entry\IncomingEntry;

/**
 * Interface para handlers de alerta customizados.
 *
 * Implemente esta interface para criar notificações em canais
 * não suportados nativamente (Telegram, PagerDuty, Webhook custom, etc.)
 */
interface AlertHandlerInterface
{
    /**
     * Envia a notificação de alerta.
     *
     * @param string $type Tipo do alerta (exception, slow_request, etc.)
     * @param IncomingEntry $entry Entrada do monitoring que disparou o alerta
     * @param array $config Configuração específica do handler
     * @return bool True se o alerta foi enviado com sucesso
     */
    public function send(string $type, IncomingEntry $entry, array $config = []): bool;

    /**
     * Verifica se o handler está configurado e pode enviar notificações.
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
}
