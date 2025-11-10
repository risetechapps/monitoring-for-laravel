<?php

namespace RiseTechApps\Monitoring\Repository;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use Throwable;

class MonitoringRepositoryHttp implements MonitoringRepositoryInterface
{
    protected string $url;
    protected string $token;

    protected int $timeout;

    protected int $retryTimes;

    protected int $retrySleep;

    public function __construct(array|string $config)
    {
        if (is_array($config)) {
            $this->token = $config['token'] ?? '';
            $this->url = $config['endpoint'] ?? 'https://monitoring.app.br/api/logs';
            $this->timeout = (int) ($config['timeout'] ?? 10);
            $retry = $config['retry'] ?? [];
            $this->retryTimes = max(0, (int) ($retry['times'] ?? 0));
            $this->retrySleep = max(0, (int) ($retry['sleep'] ?? 0));
        } else {
            $this->token = $config;
            $this->url = 'https://monitoring.app.br/api/logs';
            $this->timeout = 10;
            $this->retryTimes = 0;
            $this->retrySleep = 0;
        }
    }

    public function create(array $data): void
    {
        $attempts = max(1, $this->retryTimes ?: 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->request()->post($this->url, $data);

                if ($response->status() === 202) {
                    return;
                }

                $context = [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $this->url,
                    'entries' => count($data),
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                ];

                if ($attempt < $attempts) {
                    Log::warning('Failed to send monitoring payload to HTTP endpoint, retrying', $context);
                    $this->sleepBetweenRetries();

                    continue;
                }

                Log::critical('Failed to send monitoring payload to HTTP endpoint after retries', $context);
            } catch (Throwable $exception) {
                $context = [
                    'endpoint' => $this->url,
                    'exception' => $exception,
                    'entries' => count($data),
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                ];

                if ($attempt < $attempts) {
                    Log::warning('Exception sending monitoring payload to HTTP endpoint, retrying', $context);
                    $this->sleepBetweenRetries();

                    continue;
                }

                Log::critical('Exception sending monitoring payload to HTTP endpoint after retries', $context);
            }

            return;
        }
    }

    public function getAllEvents(): Collection
    {
        try {
            $response = $this->request()->get($this->url);

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events from HTTP endpoint', [
                'endpoint' => $this->url,
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getEventById(string $id): Collection
    {
        try {
            $response = $this->request()->get($this->url . '/show/' . $id);

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring event from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'id' => $id,
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring event from HTTP endpoint', [
                'endpoint' => $this->url,
                'id' => $id,
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getEventsByTypes(string $type): Collection
    {
        try {
            $response = $this->request()->get($this->url . '/type/' . $type);

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'type' => $type,
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'type' => $type,
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    protected function request(): PendingRequest
    {
        $request = Http::withHeaders([
            'x-api-key' => $this->token,
        ])->timeout(max(1, $this->timeout));

        if ($this->retryTimes > 0) {
            $request = $request->retry($this->retryTimes, $this->retrySleep);
        }

        return $request;
    }

    protected function sleepBetweenRetries(): void
    {
        if ($this->retrySleep > 0) {
            usleep($this->retrySleep * 1000);
        }
    }
}
