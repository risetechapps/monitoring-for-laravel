<?php

namespace RiseTechApps\Monitoring\Repository;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Jobs\SendMonitoringPayloadJob;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use Throwable;

class MonitoringRepositoryHttp implements MonitoringRepositoryInterface
{
    protected string $url;
    protected string $token;

    protected int $timeout;

    protected int $retryTimes;

    protected int $retrySleep;

    protected bool $forceSync;

    /**
     * @var array<string, mixed>
     */
    protected array $config = [];

    protected bool $isJob = false;

    public static string $HOST = "https://monitoring.app.br/api/v1/store/monitoring";
    public static string $HOST_API = "https://monitoring.app.br/api/v1/monitoring";

    public function __construct(array|string $config, bool $forceSync = false)
    {
        $this->forceSync = $forceSync;

        if (is_array($config)) {
            $this->config = $config;
            $this->token = $config['token'] ?? '';
            $this->url = self::$HOST;
            $this->timeout = (int)($config['timeout'] ?? 10);
            $retry = $config['retry'] ?? [];
            $this->retryTimes = max(0, (int)($retry['times'] ?? 0));
            $this->retrySleep = max(0, (int)($retry['sleep'] ?? 0));
        } else {
            $this->token = $config;
            $this->url = self::$HOST;
            $this->timeout = 10;
            $this->retryTimes = 0;
            $this->retrySleep = 0;
        }
    }

    public function setIsJob(): void
    {
        $this->isJob = true;
    }

    public function create(array $data): void
    {
        try {
            $response = $this->request()->post($this->url, $data);

            if ($response->status() === 202 || $response->status() === 200) {
                return;
            }

            if (!$this->isJob) {
                $dispatch = SendMonitoringPayloadJob::dispatch($data, $this->config ?: $this->toArrayConfig());

                $dispatch->delay(Carbon::now()->addMinute());
            }

            $context = [
                'status' => $response->status(),
                'body' => $response->body(),
                'context' => $data
            ];

            Log::critical('Failed to send monitoring payload to HTTP endpoint after retries', $context);

            if ($this->isJob) {
                throw new \Exception('Failed to send monitoring payload to HTTP endpoint after retries', $context);
            }
        } catch (Throwable $exception) {
            $context = [
                'context' => $data
            ];

            Log::critical('Exception sending monitoring payload to HTTP endpoint after retries', $context);

            if ($this->isJob) {
                throw new \Exception('Failed to send monitoring payload to HTTP endpoint after retries', $context);
            }
        }
    }

    public function getAllEvents(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API);
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
            $response = $this->request()->get(self::$HOST_API . '/' . $id);
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
            $response = $this->request()->get(self::$HOST_API . '/type/' . $type);

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

    public function getEventsByTags(): Collection
    {
        return collect(EntryType::getTypes());
    }

    public function getByBatch(string $id): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/batch/' . $id);

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'batch_id' => $id,
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'batch_id' => $id,
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getLast24Hours(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/period/24h');

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'period' => '24h',
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'period' => '24h',
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getLast7Days(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/period/7d');

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'period' => '7d',
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'period' => '7d',
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getLast15Days(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/period/15d');

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'period' => '15d',
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'period' => '15d',
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getLast30Days(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/period/30d');

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'period' => '30d',
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'period' => '30d',
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getLast60Days(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/period/60d');

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'period' => '60d',
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'period' => '60d',
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    public function getLast90Days(): Collection
    {
        try {
            $response = $this->request()->get(self::$HOST_API . '/period/90d');

            if ($response->successful()) {
                return collect($response->json());
            }

            Log::warning('Failed to fetch monitoring events by type from HTTP endpoint', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $this->url,
                'period' => '90d',
            ]);
        } catch (Throwable $exception) {
            Log::critical('Exception fetching monitoring events by type from HTTP endpoint', [
                'endpoint' => $this->url,
                'period' => '90d',
                'exception' => $exception,
            ]);
        }

        return collect();
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key' => $this->token,
        ])->timeout(max(1, $this->timeout));
    }

    protected function sleepBetweenRetries(): void
    {
        if ($this->retrySleep > 0) {
            usleep($this->retrySleep * 1000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function toArrayConfig(): array
    {
        return [
            'token' => $this->token,
            'endpoint' => $this->url,
            'timeout' => $this->timeout,
            'retry' => [
                'times' => $this->retryTimes,
                'sleep' => $this->retrySleep,
            ],
        ];
    }
}
