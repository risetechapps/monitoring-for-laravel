<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Requisição HTTP: nenhum INSERT do monitoring durante o request — tudo vai no
 * terminating(), depois da resposta, em INSERTs multi-linha. Antes, com buffer
 * 5, um request com ~450 eventos fazia ~90 INSERTs no caminho do usuário.
 */
class HttpFlushTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('monitoring.buffer_size', 5);
        $app['config']->set('monitoring.insert_chunk_size', 20);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Como no PHP-FPM: o processo não é de console.
        (fn () => $this->isRunningInConsole = false)->call($this->app);

        Route::get('/pedido/{logs}', function (int $logs) {
            foreach (range(1, $logs) as $i) {
                logglyInfo()->log("passo {$i}");
            }

            // O que já estava no banco enquanto o request ainda rodava.
            return ['gravados_durante' => DB::table('monitoring')->count()];
        });
    }

    public function test_nothing_is_written_during_the_request_and_everything_after_it(): void
    {
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'into "monitoring"')) {
                $inserts++;
            }
        });

        $this->getJson('/pedido/50')->assertOk()->assertJsonPath('gravados_durante', 0);

        // 50 logs + a entrada do próprio request = 51 linhas em lotes de 20.
        $this->assertCount(50, $this->storedEntries('log'));
        $this->assertCount(1, $this->storedEntries('request'));
        $this->assertSame(3, $inserts);
    }

    public function test_memory_cap_writes_during_the_request(): void
    {
        config(['monitoring.http_max_buffer' => 30]);

        $this->getJson('/pedido/35')->assertOk()->assertJsonPath('gravados_durante', 30);

        $this->assertCount(35, $this->storedEntries('log'));
    }
}
