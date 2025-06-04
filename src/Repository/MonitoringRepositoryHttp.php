<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

class MonitoringRepositoryHttp implements MonitoringRepositoryInterface
{
    protected string $url;
    protected string $token;

    public function __construct(string $token)
    {
        $this->url = "https://monitoring.app.br/api/logs";
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

    public function getAllEvents(): Collection
    {
        // TODO: Implement getAllEvents() method.
    }

    public function getEventById(string $id): Collection
    {
        // TODO: Implement getEventById() method.
    }

    public function getEventsByTypes(string $type): Collection
    {
        // TODO: Implement getEventsByTypes() method.
    }
}
