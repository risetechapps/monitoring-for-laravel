<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Services\BatchIdService;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Quando o buffer vai para o banco: nunca no meio de uma transação aberta na
 * conexão do monitoring (o rollback levaria os logs do que deu errado), e por
 * tempo em processos de console longos.
 */
class FlushTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('monitoring.buffer_size', 2);
        $app['config']->set('monitoring.flush_interval_seconds', 0);
    }

    public function test_logs_written_inside_a_rolled_back_transaction_survive(): void
    {
        DB::connection('testing')->beginTransaction();

        foreach (range(1, 5) as $i) {
            logglyError()->log("passo {$i} antes da falha");
        }

        DB::connection('testing')->rollBack();

        // O que o terminating() faz no fim do request.
        Monitoring::flushAll();

        $this->assertCount(5, $this->storedEntries('log'));
    }

    public function test_buffer_is_flushed_by_size_outside_transactions(): void
    {
        logglyInfo()->log('um');
        logglyInfo()->log('dois');

        $this->assertCount(2, $this->storedEntries('log'));
    }

    public function test_console_flushes_by_time_even_with_a_small_buffer(): void
    {
        config(['monitoring.buffer_size' => 100, 'monitoring.flush_interval_seconds' => 1]);
        (new \ReflectionProperty(Monitoring::class, 'bufferSize'))->setValue(null, 100);

        logglyInfo()->log('primeiro');
        $this->assertSame([], $this->storedEntries());

        (new \ReflectionProperty(Monitoring::class, 'lastFlushAt'))->setValue(null, microtime(true) - 5);
        logglyInfo()->log('depois do intervalo');

        $this->assertCount(2, $this->storedEntries('log'));
    }

    public function test_automatic_batch_expires_but_an_explicit_one_does_not(): void
    {
        config(['monitoring.batch_max_age_seconds' => 60]);
        $batch = new BatchIdService();
        $age = new \ReflectionProperty(BatchIdService::class, 'startedAt');

        $automatic = $batch->getBatchId();
        $age->setValue($batch, microtime(true) - 61);
        $this->assertNotSame($automatic, $batch->getBatchId(), 'long-running process kept the same batch forever');

        $batch->forceDelete();
        $batch->setBatchId('job-batch');
        $age->setValue($batch, microtime(true) - 3600);
        $this->assertSame('job-batch', $batch->getBatchId(), 'a job batch must last the whole job');
    }
}
