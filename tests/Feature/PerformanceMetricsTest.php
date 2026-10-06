<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RiseTechApps\Monitoring\Services\PerformanceMonitoringService;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Métricas de performance no Redis (Memurai/Redis local, db de teste): uma ida
 * por request, contagem exata, chave com TTL e fora do prefixo de tenant.
 */
class PerformanceMetricsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $connection = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD'),
            'database' => (int) env('REDIS_TEST_DB', 15),
        ];

        $app['config']->set('database.redis.client', 'predis');
        $app['config']->set('database.redis.options.prefix', 'monitoring_test:');
        $app['config']->set('database.redis.default', $connection);
        $app['config']->set('database.redis.cache', $connection);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache']);
        $app['config']->set('cache.prefix', 'central');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ((int) env('REDIS_TEST_DB', 15) === 0) {
            $this->fail('REDIS_TEST_DB must not be 0: the test database is flushed.');
        }

        try {
            Redis::connection('cache')->flushdb();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Redis not available: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection('cache')->flushdb();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    public function test_requests_are_counted_exactly_with_percentiles_from_the_histogram(): void
    {
        $service = app(PerformanceMonitoringService::class);

        $service->recordRequestMetrics(['duration' => 30, 'response_status' => 200]);
        $service->recordRequestMetrics(['duration' => 300, 'response_status' => 404]);
        $service->recordRequestMetrics(['duration' => 3000, 'response_status' => 500]);

        $stats = $service->getStatistics();

        $this->assertSame(3, $stats['period']['total_requests']);
        $this->assertEquals(66.67, $stats['error_rate_percent']);
        $this->assertEquals(33.33, $stats['server_error_rate']);
        $this->assertEquals(30.0, $stats['latency']['min']);
        $this->assertEquals(3000.0, $stats['latency']['max']);
        $this->assertEquals(1110.0, $stats['latency']['avg']);
        $this->assertEquals(500.0, $stats['latency']['p50'], 'upper bound of the 300ms bucket');
        $this->assertEquals(3000.0, $stats['latency']['p99'], 'never above the observed max');
        // Apdex (limiares 500/2000 ms): 30 e 300 satisfeitos, 3000 frustrado → 2/3.
        $this->assertEquals(0.67, $stats['apdex_score']);
    }

    public function test_metrics_key_is_central_and_expires(): void
    {
        // O que o CacheManager do tenancy faz dentro de um tenant: troca o
        // prefixo do store compartilhado. A métrica não pode ir junto.
        Cache::store()->getStore()->setPrefix('central_tenant_x_sub_tenant_y_auth_z');

        app(PerformanceMonitoringService::class)->recordRequestMetrics(['duration' => 50, 'response_status' => 200]);

        $keys = Redis::connection('cache')->keys('*monitoring:perf:*');
        $this->assertCount(1, $keys);
        $this->assertStringNotContainsString('tenant_x', $keys[0]);

        $ttl = Redis::connection('cache')->ttl(str_replace('monitoring_test:', '', $keys[0]));
        $this->assertGreaterThan(0, $ttl, 'metrics window without expiry');
    }
}
