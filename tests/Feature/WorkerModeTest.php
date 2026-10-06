<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Tests\TestCase;
use RiseTechApps\Monitoring\Watchers;

/**
 * Processo de worker (horizon:work): antes o monitoring era desligado por
 * inteiro e logglyError() dentro de job, e job que falhou, sumiam. Agora fica o
 * essencial (Loggly, exceções, jobs falhos) com batch e flush por job.
 */
class WorkerModeTest extends TestCase
{
    protected array $argv = ['artisan', 'horizon:work', 'redis'];

    public function test_worker_keeps_monitoring_with_only_the_low_volume_watchers(): void
    {
        $this->assertTrue(Monitoring::isEnabled());
        $this->assertTrue(Monitoring::isWorkerMode());

        $watchers = (fn () => static::$watchers)->bindTo(null, Monitoring::class)();
        $this->assertEqualsCanonicalizing([Watchers\ExceptionWatcher::class, Watchers\JobWatcher::class], $watchers);
    }

    public function test_loggly_inside_a_job_is_recorded_in_the_job_batch_and_flushed_when_it_ends(): void
    {
        $job = $this->job();

        event(new JobProcessing('redis', $job));
        logglyError()->withProperties(['tenant_id' => 't-1'])->log('Falha ao provisionar');

        $this->assertSame([], $this->storedEntries(), 'flushed before the job ended');

        event(new JobProcessed('redis', $job));

        $logs = $this->storedEntries('log');
        $this->assertCount(1, $logs);
        $this->assertSame('Falha ao provisionar', $logs[0]->content['message']);
        $this->assertSame($job->payload()['batch_id'], $logs[0]->batch_id);
    }

    public function test_each_job_gets_its_own_batch(): void
    {
        foreach ([$first = $this->job(), $second = $this->job()] as $job) {
            event(new JobProcessing('redis', $job));
            logglyInfo()->log('passo');
            event(new JobProcessed('redis', $job));
        }

        $this->assertSame(
            [$first->payload()['batch_id'], $second->payload()['batch_id']],
            array_column($this->storedEntries('log'), 'batch_id'),
        );
    }

    public function test_only_failed_jobs_are_recorded_by_the_job_watcher(): void
    {
        $ok = $this->job();
        event(new JobProcessing('redis', $ok));
        event(new JobProcessed('redis', $ok));

        $failed = $this->job();
        event(new JobProcessing('redis', $failed));
        event(new JobFailed('redis', $failed, new \RuntimeException('Banco fora do ar')));

        $jobs = $this->storedEntries('job');
        $this->assertCount(1, $jobs, 'processed job recorded in worker');
        $this->assertSame('failed', $jobs[0]->content['status']);
        $this->assertSame('Banco fora do ar', $jobs[0]->content['exception']['message']);
    }

    private function job(): FakeJob
    {
        return new class(['uuid' => (string) Str::uuid(), 'batch_id' => (string) Str::uuid(), 'displayName' => 'App\\Jobs\\ProvisionTenant']) extends FakeJob {
            public function __construct(private array $body)
            {
            }

            public function getRawBody()
            {
                return json_encode($this->body);
            }
        };
    }
}
