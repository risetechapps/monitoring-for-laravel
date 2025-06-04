<?php

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\Monitoring\Services\ExceptionContext;
use RiseTechApps\Monitoring\Services\ExtractProperties;
use RiseTechApps\Monitoring\Services\ExtractTags;
use RuntimeException;

class JobWatcher extends Watcher
{
    /**
     * Registra os ouvintes para os eventos relacionados ao processamento de jobs.
     *
     * Este método configura um payload personalizado para os jobs e registra
     * ouvintes para os eventos `JobProcessed` e `JobFailed`.
     *
     * @param  mixed  $app A instância do aplicativo que fornece o container de eventos.
     * @return void
     */
    public function register($app): void
    {
        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            $batchId = optional($this->recordPendingJob($connection, $queue, $payload))->batch_id;
            return ['batch_id' => $batchId];
        });

        $app['events']->listen(JobProcessed::class, [$this, 'recordProcessedJob']);
        $app['events']->listen(JobFailed::class, [$this, 'recordFailedJob']);
    }

    /**
     * Registra um job pendente.
     *
     * Cria um identificador único (batch_id) para o job pendente e registra
     * as informações do job no sistema de monitoramento.
     *
     * @param  string  $connection O nome da conexão do job.
     * @param  string  $queue O nome da fila do job.
     * @param  array  $payload Os dados do payload do job.
     * @return object Um objeto contendo o batch_id do job.
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada de monitoramento.
     */
    public function recordPendingJob($connection, $queue, array $payload): object
    {
        $batchId = Str::uuid()->toString();

        if(Monitoring::isEnabled()){
            try {

                $content = array_merge([
                    'status' => 'pending',
                    'batch_id' => $batchId,
                ], $this->defaultJobData($connection, $queue, $payload, $this->data($payload)));
                app(BatchIdService::class)->setBatchId($batchId);

                Monitoring::recordJob(IncomingEntry::make($content));
            } catch (\Exception $exception) {
                loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
            }
        }
        return (object)['batch_id' => $batchId];
    }

    /**
     * Registra um job processado.
     *
     * Registra as informações do job processado, incluindo o batch_id, no
     * sistema de monitoramento e remove o batch_id do serviço.
     *
     * @param  JobProcessed  $event O evento de job processado.
     * @return void
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada de monitoramento.
     */
    public function recordProcessedJob(JobProcessed $event): void
    {
        try {
            if(!Monitoring::isEnabled()) return;

            $batchId = $event->job->payload()['batch_id'] ?? $event->job->uuid();

            if (!$batchId) {
                return;
            }

            app(BatchIdService::class)->setBatchId($batchId);

            $jobDetails = [
                'jobName' => $event->job->getName(),
                'connection' => $event->job->getConnectionName(),
                'queue' => $event->job->getQueue(),
                'payload' => $event->job->payload(),
                'attempts' => $event->job->attempts(),
            ];

            $entry = IncomingEntry::make([
                'status' => 'processed',
                'content' => $jobDetails,
                'hostname' => gethostname(),
            ]);

            Monitoring::recordJob($entry);

            app(BatchIdService::class)->forceDelete();
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Registra um job falhado.
     *
     * Registra as informações do job falhado, incluindo o batch_id e detalhes
     * da exceção, no sistema de monitoramento e remove o batch_id do serviço.
     *
     * @param  JobFailed  $event O evento de job falhado.
     * @return void
     * @throws \Exception Se ocorrer um erro ao criar ou gravar a entrada de monitoramento.
     */
    public function recordFailedJob(JobFailed $event): void
    {
        try {

            if(!Monitoring::isEnabled()) return;

            $batchId = $event->job->payload()['batch_id'] ?? $event->job->uuid();

            if (!$batchId) {
                return;
            }

            app(BatchIdService::class)->forceDelete();
            app(BatchIdService::class)->setBatchId($batchId);

            $entry = IncomingEntry::make([
                'status' => 'failed',
                'displayName' => $event->job->payload()['displayName'] ?? 'Unknown',
                'exception' => [
                    'message' => $event->exception->getMessage(),
                    'trace' => $event->exception->getTrace(),
                    'line' => $event->exception->getLine(),
                    'line_preview' => ExceptionContext::get($event->exception),
                ],
                'hostname' => gethostname(),
            ]);

            Monitoring::recordJob($entry);

            app(BatchIdService::class)->forceDelete();
        } catch (\Exception $exception) {
            loggly()->to('file')->performedOn(self::class)->exception($exception)->level('error')->log($exception->getMessage());
        }
    }

    /**
     * Obtém os dados padrão para um job.
     *
     * Preenche os dados padrão para o job com base nas informações fornecidas.
     *
     * @param  string  $connection O nome da conexão do job.
     * @param  string  $queue O nome da fila do job.
     * @param  array  $payload Os dados do payload do job.
     * @param  array  $data Os dados adicionais do job.
     * @return array Os dados padrão para o job.
     */
    protected function defaultJobData($connection, $queue, array $payload, array $data): array
    {
        return [
            'connection' => $connection,
            'queue' => $queue,
            'name' => $payload['displayName'] ?? 'Unknown',
            'tries' => $payload['maxTries'] ?? 0,
            'timeout' => $payload['timeout'] ?? 0,
            'data' => $data,
            'payload' => $payload,
        ];
    }

    /**
     * Extrai os dados do payload do job.
     *
     * Se o comando estiver presente no payload, ele extrai e retorna as
     * propriedades do comando. Caso contrário, retorna os dados do payload.
     *
     * @param  array  $payload Os dados do payload do job.
     * @return array Os dados extraídos.
     */
    protected function data(array $payload)
    {
        if (!isset($payload['data']['command'])) {
            return $payload['data'];
        }

        return ExtractProperties::from($payload['data']['command']);
    }

    /**
     * Extrai as tags do payload do job.
     *
     * Se o comando estiver presente no payload, ele extrai e retorna as
     * tags associadas ao comando. Caso contrário, retorna um array vazio.
     *
     * @param  array  $payload Os dados do payload do job.
     * @return array As tags extraídas.
     */
    protected function tags(array $payload)
    {
        if (!isset($payload['data']['command'])) {
            return [];
        }

        return ExtractTags::fromJob($payload['data']['command']);
    }

    /**
     * Obtém o comando a partir dos dados do job.
     *
     * Deserializa o comando a partir dos dados fornecidos, decodificando-o se
     * estiver criptografado. Lança uma exceção se não for possível extrair o payload.
     *
     * @param  array  $data Os dados do job.
     * @return mixed O comando deserializado.
     * @throws RuntimeException Se não for possível extrair o payload do job.
     */
    protected function getCommand(array $data)
    {
        if (Str::startsWith($data['command'], 'O:')) {
            return unserialize($data['command']);
        }

        if (app()->bound(Encrypter::class)) {
            return unserialize(app(Encrypter::class)->decrypt($data['command']));
        }

        throw new RuntimeException('Unable to extract job payload.');
    }
}
