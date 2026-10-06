<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Services\MonitoringQueryService;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * PostgreSQL real (banco próprio, criado e apagado pelo teste): busca por tags
 * pelo índice GIN e a migration que remove os índices sem uso.
 */
class PostgresTagQueryTest extends TestCase
{
    private static string $database;

    protected function setUp(): void
    {
        self::$database = 'monitoring_test_' . getmypid();

        try {
            $pdo = $this->adminPdo();
            $pdo->exec('DROP DATABASE IF EXISTS "' . self::$database . '"');
            $pdo->exec('CREATE DATABASE "' . self::$database . '"');
        } catch (\Throwable $exception) {
            $this->markTestSkipped('PostgreSQL not available: ' . $exception->getMessage());
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        DB::disconnect('monitoring_pg');

        parent::tearDown();

        try {
            $pdo = $this->adminPdo();
            $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '" . self::$database . "' AND pid <> pg_backend_pid()");
            $pdo->exec('DROP DATABASE IF EXISTS "' . self::$database . '"');
        } catch (\Throwable) {
        }
    }

    protected function getPackageProviders($app): array
    {
        return array_merge([\Tpetry\PostgresqlEnhanced\PostgresqlEnhancedServiceProvider::class], parent::getPackageProviders($app));
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.monitoring_pg', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => self::$database,
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function monitoringConnection(): string
    {
        return 'monitoring_pg';
    }

    public function test_unused_tag_indexes_are_dropped_and_the_gin_index_stays(): void
    {
        $indexes = DB::connection('monitoring_pg')->table('pg_indexes')->where('tablename', 'monitoring')->pluck('indexname')->all();

        $this->assertContains('monitoring_tags_gin_idx', $indexes);
        $this->assertNotContains('monitoring_tags_user_id_idx', $indexes);
        $this->assertNotContains('monitoring_tags_trgm_idx', $indexes);
    }

    public function test_tag_filter_matches_text_and_numeric_values(): void
    {
        $text = $this->row(['user_id' => 'abc-123']);
        $numeric = $this->row(['user_id' => 42]);
        $this->row(['user_id' => 'outro']);

        $service = new MonitoringQueryService('monitoring_pg');
        $ids = fn (array $tags) => $service->scopeTags(DB::connection('monitoring_pg')->table('monitoring'), $tags)->pluck('id')->all();

        $this->assertSame([$text], $ids(['user_id' => 'abc-123']));
        $this->assertSame([$numeric], $ids(['user_id' => '42']));
    }

    public function test_tag_filter_uses_the_gin_index(): void
    {
        $this->row(['user_id' => 'abc-123']);

        $service = new MonitoringQueryService('monitoring_pg');
        $query = $service->scopeTags(DB::connection('monitoring_pg')->table('monitoring'), ['user_id' => 'abc-123']);

        $connection = DB::connection('monitoring_pg');
        $connection->statement('SET enable_seqscan = off');

        $plan = collect($connection->select('EXPLAIN ' . $query->toSql(), $query->getBindings()))
            ->map(fn ($row) => (array) $row)->flatten()->implode("\n");

        $this->assertStringContainsString('monitoring_tags_gin_idx', $plan);
    }

    public function test_paginate_filters_by_tenant_and_searches_case_insensitive(): void
    {
        $this->row(['tenant_id' => 'tenant-a'], ['message' => 'Falha no PAGAMENTO']);
        $this->row(['tenant_id' => 'tenant-b'], ['message' => 'Falha no pagamento']);
        $this->row(['tenant_id' => 'tenant-a'], ['message' => 'outro']);

        $service = new MonitoringQueryService('monitoring_pg');

        $this->assertSame(1, $service->paginate(['tenant_id' => 'tenant-a', 'search' => 'pagamento'], 50, 1)->total());
        $this->assertSame(2, $service->paginate(['tenant_id' => 'tenant-a'], 50, 1)->total());
        $this->assertSame(2, $service->paginate(['search' => 'pagamento'], 50, 1)->total());
        // Curinga do LIKE é texto: nenhuma mensagem tem "%" literal.
        $this->assertSame(0, $service->paginate(['search' => '%pagamento'], 50, 1)->total());
    }

    private function row(array $tags, array $content = []): string
    {
        $id = (string) Str::orderedUuid();

        DB::connection('monitoring_pg')->table('monitoring')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'batch_id' => (string) Str::uuid(),
            'type' => 'log',
            'content' => json_encode((object) $content),
            'tags' => json_encode($tags),
            'created_at' => now()->toDateTimeString(),
        ]);

        return $id;
    }

    private function adminPdo(): \PDO
    {
        return new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=postgres', env('DB_HOST', '127.0.0.1'), env('DB_PORT', 5432)),
            env('DB_USERNAME', 'postgres'),
            env('DB_PASSWORD', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3],
        );
    }
}
