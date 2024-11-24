<?php

namespace RiseTechApps\Monitoring\Repository\Contracts;

interface MonitoringRepositoryInterface
{
    public function create(array $data): void;
}
