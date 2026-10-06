<?php

namespace RiseTechApps\Monitoring\Tests;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Support\Spool;

/**
 * Monitoring gravando de verdade (driver database) num SQLite em memória, com
 * as migrations do package. Testes que precisam de PostgreSQL/Redis sobem o
 * próprio ambiente (ver PostgresTagQueryTest e PerformanceMetricsTest).
 */
abstract class TestCase extends BaseTestCase
{
    /** argv simulado do processo (ex.: ['artisan', 'horizon:work']). */
    protected array $argv = ['vendor/bin/phpunit'];

    private array $originalArgv = [];

    /** Pasta do rascunho em disco deste teste (apagada no fim). */
    protected string $spoolPath;

    protected function setUp(): void
    {
        $this->spoolPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monitoring_spool_' . getmypid() . '_' . uniqid();
        $this->originalArgv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = $this->argv;

        self::resetMonitoringState();

        parent::setUp();

        $this->artisan('migrate', ['--database' => $this->monitoringConnection()])->run();
    }

    protected function tearDown(): void
    {
        self::resetMonitoringState();
        $_SERVER['argv'] = $this->originalArgv;

        parent::tearDown();

        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->spoolPath);
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Sanctum\SanctumServiceProvider::class,
            \RiseTechApps\RiseTools\RiseToolsServiceProvider::class,
            \RiseTechApps\Monitoring\MonitoringServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');

        $app['config']->set('monitoring.enabled', true);
        $app['config']->set('monitoring.driver', 'database');
        $app['config']->set('monitoring.drivers.database.connection', $this->monitoringConnection());
        $app['config']->set('monitoring.retention.auto_schedule', false);
        $app['config']->set('monitoring.spool.path', $this->spoolPath);
    }

    protected function monitoringConnection(): string
    {
        return 'testing';
    }

    /** Linhas gravadas, com content/tags decodificados. */
    protected function storedEntries(?string $type = null): array
    {
        $query = DB::connection($this->monitoringConnection())->table('monitoring')->orderBy('created_at')->orderBy('id');

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->get()->map(function ($row) {
            $row->content = json_decode((string) $row->content, true);
            $row->tags = json_decode((string) $row->tags, true);

            return $row;
        })->all();
    }

    /** O estado do Monitoring é estático: zera entre testes. */
    protected static function resetMonitoringState(): void
    {
        $reflection = new \ReflectionClass(Monitoring::class);

        // Solta o arquivo do rascunho do teste anterior (como se o processo acabasse).
        Spool::abandon();

        foreach (['buffer' => [], 'bufferBytes' => 0, 'watchers' => [], 'tagUsing' => [], 'lastFlushAt' => 0.0, 'workerMode' => false] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue(null, $value);
            }
        }

        IncomingEntry::resetDeviceCache();
    }
}
