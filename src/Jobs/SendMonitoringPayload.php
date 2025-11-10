<?php

namespace RiseTechApps\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\Monitoring\Repository\MonitoringRepositoryHttp;

class SendMonitoringPayload implements ShouldQueue
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
        $repository = new MonitoringRepositoryHttp($this->config, true);

        $repository->create($this->payload);
    }
}
