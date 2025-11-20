<?php

namespace RiseTechApps\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryHttp;

class SendMonitoringPayloadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected array $payload,
        protected array|string $config
    ) {
    }

    public function handle(): void
    {
        Monitoring::disable();

        $repository = new MonitoringRepositoryHttp($this->config, true);
        $repository->setIsJob();
        $repository->create($this->payload);
    }
}
