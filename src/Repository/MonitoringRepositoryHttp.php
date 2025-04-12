<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringRepositoryHttp implements MonitoringRepositoryInterface
{
    protected string $url;
    protected string $token;

    public function __construct(string $url, string $token)
    {
        $this->url = $url;
        $this->token = $token;
    }

    public function create(array $data): void
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->token
        ])->post($this->url, $data);

        if ($response->status() !== 202) {
            Log::critical('error register log', $data);
        }
    }
}
