<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Collection;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

/**
 * Driver de repositório baseado em banco de dados relacional.
 * Herda toda a implementação de MonitoringRepository.
 */
class MonitoringRepositoryDatabase extends MonitoringRepository implements MonitoringRepositoryInterface
{
    // Toda a lógica está no MonitoringRepository base.
    // Esta classe existe para permitir que o ServiceProvider distinga
    // entre os drivers 'database' e 'single' via binding concreto.
}
