<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Tests\TestCase;

/** monitoring.workers.enabled=false: o comportamento antigo (nada em worker). */
class WorkerModeDisabledTest extends TestCase
{
    protected array $argv = ['artisan', 'queue:work'];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('monitoring.workers.enabled', false);
    }

    public function test_nothing_is_recorded(): void
    {
        $this->assertFalse(Monitoring::isEnabled());

        logglyError()->log('ignorado');
        Monitoring::flushAll();

        $this->assertSame([], $this->storedEntries());
    }
}
