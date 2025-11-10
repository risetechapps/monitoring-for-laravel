<?php

namespace RiseTechApps\Monitoring\Repository;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Monitoring\Jobs\SendMonitoringPayload;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use Throwable;

class MonitoringRepositoryHttp implements MonitoringRepositoryInterface
{
    protected string $url;
    protected string $token;

    protected int $timeout;

    protected int $retryTimes;

    protected int $retrySleep;

    protected bool $queueEnabled = false;

    protected ?string $queueConnection = null;

    protected ?string $queueName = null;

    protected int $queueDelay = 0;

    protected bool $forceSync;

    /**
     * @var array<string, mixed>
     */
    protected array $config = [];

    public function __construct(array|string $config, bool $forceSync = false)
    {
        $this->forceSync = $forceSync;

        if (is_array($config)) {
            $this->config = $config;
            $this->token = $config['token'] ?? '';
            $this->url = $config['endpoint'] ?? 'https://monitoring.app.br/api/logs';
            $this->timeout = (int) ($config['timeout'] ?? 10);
            $retry = $config['retry'] ?? [];
            $this->retryTimes = max(0, (int) ($retry['times'] ?? 0));
            $this->retrySleep = max(0, (int) ($retry['sleep'] ?? 0));

            $queue = $config['queue'] ?? [];
            $this->queueEnabled = (bool) ($queue['enabled'] ?? false);
            $this->queueConnection = $queue['connection'] ?? null;
            $this->queueName = $queue['queue'] ?? null;
            $this->queueDelay = max(0, (int) ($queue['delay'] ?? 0));
        } else {
            $this->token = $config;
            $this->url = 'https://monitoring.app.br/api/logs';
            $this->timeout = 10;
            $this->retryTimes = 0;
            $this->retrySleep = 0;
            $this->queueEnabled = false;
        }
    }

    public function create(array $data): void
    {
        if ($this->shouldQueue()) {
            $dispatch = SendMonitoringPayload::dispatch($data, $this->config ?: $this->toArrayConfig());

            if (!empty($this->queueConnection)) {
                $dispatch->onConnection($this->queueConnection);
            }

            if (!empty($this->queueName)) {
                $dispatch->onQueue($this->queueName);
            }

            if ($this->queueDelay > 0) {
                $dispatch->delay(Carbon::now()->addSeconds($this->queueDelay));
            }

            return;
        }

        $this->sendSynchronously($data);
    }

    protected function sendSynchronously(array $data): void
    {
        $attempts = max(1, $this->retryTimes ?: 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->request()->post($this->url, $data);

                if ($response->status() === 202) {
                    return;
                }

                $context = $this->buildContext($data, [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                ]);

                if ($attempt < $attempts) {
                    Log::warning('Failed to send monitoring payload to HTTP endpoint, retrying', $context);
                    $this->sleepBetweenRetries();

                    continue;
                }

                Log::critical('Failed to send monitoring payload to HTTP endpoint after retries', $context);
            } catch (Throwable $exception) {
                $context = $this->buildContext($data, [
                    'exception' => $exception,
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                ]);

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

    protected function shouldQueue(): bool
    {
        return $this->queueEnabled && !$this->forceSync;
    }

    protected function buildContext(array $data, array $context = []): array
    {
        $entries = is_countable($data) ? count($data) : 1;

        return array_merge([
            'endpoint' => $this->url,
            'entries' => $entries,
        ], $context);
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
            'queue' => [
                'enabled' => $this->queueEnabled,
                'connection' => $this->queueConnection,
                'queue' => $this->queueName,
                'delay' => $this->queueDelay,
            ],
        ];
    }
}
