<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use RiseTechApps\Monitoring\Tests\TestCase;
use RiseTechApps\RiseTools\Features\Device\Device;

/**
 * Geo do risetools (Device::info), usado pelo monitoring quando
 * monitoring.device.geo_ip=true: IP privado não consulta e falha fica em cache
 * negativo. (O store fora do prefixo de tenant usa o mesmo resolve() das
 * métricas — coberto no PerformanceMetricsTest.)
 */
class DeviceGeoIpTest extends TestCase
{
    private array $server = [];

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $_SERVER;
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);

        // Porta fechada: a "consulta" falha na hora, sem rede externa.
        config(['risetools.geoip.url' => 'http://127.0.0.1:9/{ip}', 'risetools.geoip.connect_timeout' => 1]);

        // Backend compartilhado entre instâncias do store (o array não é).
        $this->cachePath = sys_get_temp_dir() . '/monitoring_geo_' . getmypid() . '_' . uniqid();
        config(['cache.default' => 'file', 'cache.stores.file' => ['driver' => 'file', 'path' => $this->cachePath]]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->cachePath);

        parent::tearDown();
    }

    public function test_geo_can_be_skipped(): void
    {
        $this->assertArrayNotHasKey('geo_ip', Device::info(false));
        $this->assertArrayHasKey('geo_ip', Device::info());
    }

    public function test_private_ip_is_not_looked_up(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';

        Device::info();

        $this->assertNull($this->centralCache()->get('risetools:geoip:10.1.2.3'));
    }

    public function test_failure_is_cached_so_the_next_request_does_not_wait_again(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';

        $this->assertSame('', Device::info()['geo_ip']['status']);
        $this->assertSame([], $this->centralCache()->get('risetools:geoip:8.8.8.8'), 'failure not cached: every request would pay the timeout again');
    }

    private function centralCache(): \Illuminate\Contracts\Cache\Repository
    {
        return app('cache')->resolve(config('cache.default'));
    }
}
