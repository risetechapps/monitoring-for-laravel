<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Jobs\SendMonitoringPayloadJob;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\Monitoring\Services\ExceptionContext;
use RiseTechApps\Monitoring\Services\ExtractProperties;
use RiseTechApps\Monitoring\Services\ExtractTags;
use RuntimeException;

class JobWatcher extends Watcher
{

    /**
     * Namespaces de jobs internos que NUNCA devem ser monitorados.
     * Evita o loop: job de monitoring falha → JobFailed → novo job → falha → ...
     */
    private const IGNORED_JOB_NAMESPACES = [
        'RiseTechApps\\Monitoring\\',   // todos os jobs deste pacote
        'Laravel\\Telescope\\',          // jobs internos do Telescope
        'Laravel\\Horizon\\',            // jobs internos do Horizon
        'Laravel\\Pulse\\',
    ];

    public function register($app): void
    {
        Queue::createPayloadUsing(function ($connection, $queue, $payload) {

            $command = $payload['data']['command'] ?? null;

            if ($this->isIgnoredJob($command)) {
                return [];
            }

            $batchId = optional($this->recordPendingJob($connection, $queue, $payload))->batch_id;
            return ['batch_id' => $batchId];
        });

        $app['events']->listen(JobProcessed::class, [$this, 'recordProcessedJob']);
        $app['events']->listen(JobFailed::class, [$this, 'recordFailedJob']);
    }

    public function recordPendingJob($connection, $queue, array $payload): object
    {
        $batchId = Str::uuid()->toString();

        if (Monitoring::isEnabled()) {
            try {
                $content = array_merge([
                    'status'   => 'pending',
                    'batch_id' => $batchId,
                ], $this->defaultJobData($connection, $queue, $payload, $this->data($payload)));

                app(BatchIdService::class)->setBatchId($batchId);
                Monitoring::recordJob(IncomingEntry::make($content));
            } catch (\Exception $exception) {
                loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
            }
        }

        return (object) ['batch_id' => $batchId];
    }

    public function recordProcessedJob(JobProcessed $event): void
    {
        try {
            if (! Monitoring::isEnabled()) return;

            $displayName = $event->job->payload()['displayName'] ?? '';
            if ($this->isIgnoredJob($displayName)) return;

            $batchId = $event->job->payload()['batch_id'] ?? $event->job->uuid();
            if (! $batchId) return;

            app(BatchIdService::class)->setBatchId($batchId);

            $entry = IncomingEntry::make([
                'status'     => 'processed',
                'jobName'    => $event->job->getName(),
                'connection' => $event->job->getConnectionName(),
                'queue'      => $event->job->getQueue(),
                'attempts'   => $event->job->attempts(),
                // Não armazena o payload completo — pode ser enorme
            ]);

            Monitoring::recordJob($entry);
            app(BatchIdService::class)->forceDelete();
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    public function recordFailedJob(JobFailed $event): void
    {
        try {
            if (! Monitoring::isEnabled()) return;

            $displayName = $event->job->payload()['displayName'] ?? '';
            if ($this->isIgnoredJob($displayName)) return;

            $batchId = $event->job->payload()['batch_id'] ?? $event->job->uuid();
            if (! $batchId) return;

            app(BatchIdService::class)->forceDelete();
            app(BatchIdService::class)->setBatchId($batchId);

            // Limita o trace para não estourar memória
            $trace = array_slice($event->exception->getTrace(), 0, 20);

            $entry = IncomingEntry::make([
                'status'      => 'failed',
                'displayName' => $event->job->payload()['displayName'] ?? 'Unknown',
                'exception'   => [
                    'message'      => substr($event->exception->getMessage(), 0, 500),
                    'trace'        => $trace,
                    'line'         => $event->exception->getLine(),
                    'line_preview' => ExceptionContext::get($event->exception),
                ],
            ]);

            Monitoring::recordJob($entry);
            app(BatchIdService::class)->forceDelete();
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    protected function defaultJobData($connection, $queue, array $payload, array $data): array
    {
        return [
            'connection' => $connection,
            'queue'      => $queue,
            'name'       => $payload['displayName'] ?? 'Unknown',
            'tries'      => $payload['maxTries'] ?? 0,
            'timeout'    => $payload['timeout'] ?? 0,
            'data'       => $data,
            // Não inclui $payload completo — pode ser enorme
        ];
    }

    protected function data(array $payload): array
    {
        if (! isset($payload['data']['command'])) {
            return $payload['data'] ?? [];
        }

        try {
            return ExtractProperties::from($payload['data']['command']);
        } catch (\Throwable) {
            return ['_error' => 'Could not extract job data'];
        }
    }

    protected function tags(array $payload): array
    {
        if (! isset($payload['data']['command'])) {
            return [];
        }

        try {
            return ExtractTags::fromJob($payload['data']['command']);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function getCommand(array $data): mixed
    {
        if (Str::startsWith($data['command'], 'O:')) {
            return unserialize($data['command']);
        }

        if (app()->bound(Encrypter::class)) {
            return unserialize(app(Encrypter::class)->decrypt($data['command']));
        }

        throw new RuntimeException('Unable to extract job payload.');
    }

    /**
     * Verifica se o job deve ser ignorado pelo monitoramento.
     *
     * Aceita objeto instanciado, string com nome da classe, ou string serializada.
     * Bloqueia o próprio pacote + Telescope + Horizon para evitar loops.
     */
    private function isIgnoredJob(mixed $command): bool
    {
        $className = null;

        if (is_object($command)) {
            $className = get_class($command);
        } elseif (is_string($command) && $command !== '') {
            // Pode ser displayName ("App\Jobs\MyJob") ou classe serializada
            $className = $command;
        }

        if ($className === null) {
            return false;
        }

        // Verifica por namespace prefix (mais robusto que comparação exata)
        foreach (self::IGNORED_JOB_NAMESPACES as $ns) {
            if (str_starts_with($className, $ns)) {
                return true;
            }
        }

        // Mantém compatibilidade com a verificação exata anterior
        return $className === SendMonitoringPayloadJob::class
            || is_a($className, SendMonitoringPayloadJob::class, true);
    }
}
