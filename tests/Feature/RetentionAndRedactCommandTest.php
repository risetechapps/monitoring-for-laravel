<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RiseTechApps\Monitoring\Services\RetentionService;
use RiseTechApps\Monitoring\Support\Redactor;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Retenção apaga TODO o elegível numa execução (antes, ~metade: chunk() por
 * OFFSET apagando dentro do laço) e o monitoring:redact limpa o que foi gravado
 * antes da ocultação existir.
 */
class RetentionAndRedactCommandTest extends TestCase
{
    public function test_retention_deletes_every_eligible_row_in_one_run(): void
    {
        config(['monitoring.retention.export' => false]);

        $old = array_map(fn () => $this->row('request', now()->subDays(40)), range(1, 7));
        $recent = [$this->row('request', now()->subDays(2)), $this->row('request', now())];

        $stats = app(RetentionService::class)->run(30, 'json', 'local', 2);

        $this->assertSame(7, $stats['deleted']);
        $this->assertEqualsCanonicalizing($recent, DB::table('monitoring')->pluck('id')->all());
        $this->assertNotEmpty($old);
    }

    public function test_retention_exports_before_deleting_when_enabled(): void
    {
        Storage::fake('backup');
        array_map(fn () => $this->row('request', now()->subDays(40)), range(1, 3));

        $stats = app(RetentionService::class)->run(30, 'json', 'backup', 2);

        $this->assertSame(3, $stats['exported']);
        $this->assertSame(3, $stats['deleted']);
        $this->assertCount(2, Storage::disk('backup')->allFiles());
    }

    public function test_redact_command_cleans_rows_stored_before_the_fix(): void
    {
        $legacy = $this->row('log', now(), [
            'message' => 'Invalid email or password',
            // O que o antigo Loggly::withRequest() gravava: o objeto despejado.
            'request' => ["\0*\0content" => '{"password":"MinhaSenha123"}', 'attributes' => []],
            'response' => ['data' => ['access_token' => '12|TOKEN']],
        ]);
        $clean = $this->row('log', now(), ['message' => 'ok']);

        $this->artisan('monitoring:redact', ['--dry-run' => true])->assertSuccessful();
        $this->assertStringContainsString('MinhaSenha123', DB::table('monitoring')->where('id', $legacy)->value('content'), 'dry-run changed data');

        $this->artisan('monitoring:redact', ['--force' => true])->assertSuccessful();

        $content = DB::table('monitoring')->where('id', $legacy)->value('content');
        $this->assertStringNotContainsString('MinhaSenha123', $content);
        $this->assertStringNotContainsString('12|TOKEN', $content);
        $this->assertSame('Invalid email or password', json_decode($content, true)['message']);
        $this->assertSame(Redactor::MASK, json_decode($content, true)['response']['data']['access_token']);
        $this->assertSame(['message' => 'ok'], json_decode(DB::table('monitoring')->where('id', $clean)->value('content'), true));
    }

    private function row(string $type, $createdAt, array $content = ['x' => 1]): string
    {
        $id = (string) Str::orderedUuid();
        usleep(1000);

        DB::table('monitoring')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'batch_id' => (string) Str::uuid(),
            'type' => $type,
            'content' => json_encode($content),
            'tags' => '[]',
            'created_at' => $createdAt->toDateTimeString(),
        ]);

        return $id;
    }
}
