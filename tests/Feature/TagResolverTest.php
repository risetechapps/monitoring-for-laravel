<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Tags globais: a função registrada roda a CADA registro, com o contexto
 * daquele instante (é o que o tenancy usa para marcar tenant/filial).
 */
class TagResolverTest extends TestCase
{
    public function test_resolver_runs_when_each_entry_is_recorded(): void
    {
        $context = 'tenant-a';
        Monitoring::registerTagResolver(function () use (&$context) {
            return ['tenant_id' => $context];
        });

        logglyInfo()->log('primeiro');
        $context = 'tenant-b';
        logglyInfo()->log('segundo');
        Monitoring::flushAll();

        $this->assertSame(
            ['tenant-a', 'tenant-b'],
            array_map(fn ($row) => $row->tags['tenant_id'] ?? null, $this->storedEntries('log')),
        );
    }

    public function test_deprecated_tag_does_not_turn_a_disabled_monitoring_back_on(): void
    {
        Monitoring::disable();

        // Antes: `new static(...)` → o construtor fazia $enabled = true.
        Monitoring::tag(fn () => ['x' => 'y']);

        $this->assertFalse(Monitoring::isEnabled());
    }
}
