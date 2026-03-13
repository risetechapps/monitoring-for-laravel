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

    /** Limite de 512KB para o payload inteiro do job */
    private const MAX_PAYLOAD_SIZE = 524288;

    /** Limite de 32KB por entry individual */
    private const MAX_ENTRY_SIZE = 32768;

    public array $payload;
    public array|string $config;

    public function __construct(array $payload, array|string $config)
    {
        $this->payload = $this->truncatePayload($payload);
        $this->config  = $config;
    }

    public function handle(): void
    {
        Monitoring::disable();

        $repository = new MonitoringRepositoryHttp($this->config, true);
        $repository->setIsJob();
        $repository->create($this->payload);
    }

    /**
     * Trunca o payload para garantir que não estoure a memória durante a serialização
     * e o armazenamento na fila (Redis/database).
     */
    private function truncatePayload(array $data): array
    {
        $truncated = array_map(function (array $entry) {
            // Se content já é string JSON, limita diretamente
            if (isset($entry['content']) && is_string($entry['content'])) {
                if (strlen($entry['content']) > self::MAX_ENTRY_SIZE) {
                    $entry['content'] = json_encode(['_purged' => 'Entry content too large']);
                }
                return $entry;
            }

            // Se content é array, trunca sub-campos pesados
            if (isset($entry['content']) && is_array($entry['content'])) {
                $content = $entry['content'];

                if (isset($content['response']) && is_array($content['response'])) {
                    $encoded = json_encode($content['response'], JSON_PARTIAL_OUTPUT_ON_ERROR);
                    if ($encoded && strlen($encoded) > 16384) {
                        $content['response'] = ['_purged' => 'Response too large (' . round(strlen($encoded) / 1024, 1) . 'KB)'];
                    }
                }

                if (isset($content['headers']) && is_array($content['headers'])) {
                    $content['headers'] = array_intersect_key($content['headers'], array_flip([
                        'content-type', 'accept', 'user-agent', 'host', 'x-tenant',
                    ]));
                }

                unset($content['session']);

                $entry['content'] = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
                    ?: json_encode(['_purged' => 'Could not serialize content']);
            }

            return $entry;
        }, $data);

        // Último recurso: se o payload total ainda for grande demais, descarta os entries mais pesados
        $totalSize = strlen(json_encode($truncated, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        if ($totalSize > self::MAX_PAYLOAD_SIZE) {
            return array_map(function (array $entry) {
                $entry['content'] = json_encode(['_purged' => 'Total payload too large']);
                return $entry;
            }, $truncated);
        }

        return $truncated;
    }
}
