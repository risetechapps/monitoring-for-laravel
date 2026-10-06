<?php

namespace RiseTechApps\Monitoring\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Support\Spool;
use RiseTechApps\Monitoring\Tests\TestCase;

/**
 * Nenhum log perdido: cada entrada vai para o rascunho em disco no ato do
 * registro. Processo morto antes do flush, ou banco fora na hora de gravar →
 * o monitoring:spool-recover grava depois, sem duplicar.
 */
class SpoolTest extends TestCase
{
    public function test_entry_is_on_disk_before_the_flush_and_cleared_after_it(): void
    {
        logglyError()->log('falha no pagamento');

        $this->assertStringContainsString('falha no pagamento', $this->spoolContents());
        $this->assertSame([], $this->storedEntries());

        Monitoring::flushAll();

        $this->assertCount(1, $this->storedEntries('log'));
        $this->assertSame('', $this->spoolContents(), 'spool not cleared after the insert');
    }

    public function test_entries_of_a_killed_process_are_recovered(): void
    {
        logglyError()->log('antes de morrer 1');
        logglyError()->log('antes de morrer 2');

        $this->killProcess();
        // Linha cortada no meio (processo morto durante a escrita).
        file_put_contents($this->spoolFiles()[0], '{"uuid":"incompleto","ty', FILE_APPEND);

        $this->artisan('monitoring:spool-recover')->assertSuccessful();

        $this->assertSame(
            ['antes de morrer 1', 'antes de morrer 2'],
            array_map(fn ($row) => $row->content['message'], $this->storedEntries('log')),
        );
        $this->assertSame([], $this->spoolFiles(), 'recovered file not removed');
    }

    public function test_entries_survive_the_database_being_down_at_flush_time(): void
    {
        logglyError()->log('banco caiu');

        // O flush reporta a falha via error_log(); não poluir a saída do teste.
        $errorLog = ini_set('error_log', $this->spoolPath . '/../monitoring_error_' . getmypid() . '.log');

        Schema::rename('monitoring', 'monitoring_fora');
        Monitoring::flushAll();
        Schema::rename('monitoring_fora', 'monitoring');
        ini_set('error_log', (string) $errorLog);

        $this->assertSame([], $this->storedEntries());
        $this->assertCount(1, $this->spoolFiles(), 'failed batch must stay on disk');

        $this->artisan('monitoring:spool-recover')->assertSuccessful();

        $this->assertSame('banco caiu', $this->storedEntries('log')[0]->content['message']);
    }

    public function test_recover_keeps_the_file_while_the_database_is_still_down(): void
    {
        logglyError()->log('ainda fora');
        $this->killProcess();

        Schema::rename('monitoring', 'monitoring_fora');
        $this->artisan('monitoring:spool-recover')->assertFailed();
        Schema::rename('monitoring_fora', 'monitoring');

        $this->assertCount(1, $this->spoolFiles());

        $this->artisan('monitoring:spool-recover')->assertSuccessful();
        $this->assertCount(1, $this->storedEntries('log'));
    }

    public function test_recover_skips_the_file_of_a_live_process(): void
    {
        logglyError()->log('processo vivo');

        $this->artisan('monitoring:spool-recover')->assertSuccessful();

        $this->assertSame([], $this->storedEntries(), 'recover touched a file still in use');
        $this->assertStringContainsString('processo vivo', $this->spoolContents());
    }

    public function test_recovering_entries_that_were_already_inserted_does_not_duplicate(): void
    {
        logglyError()->log('gravado e depois morreu');
        $line = $this->spoolContents();

        Monitoring::flushAll();
        // O INSERT entrou, mas o processo morreu antes de zerar o rascunho.
        file_put_contents($this->spoolPath . '/morto.jsonl', $line);

        $this->artisan('monitoring:spool-recover')->assertSuccessful();

        $this->assertCount(1, $this->storedEntries('log'));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** O que o sistema operacional faz num SIGKILL: memória some, trava solta. */
    private function killProcess(): void
    {
        (new \ReflectionProperty(Monitoring::class, 'buffer'))->setValue(null, []);

        $handle = (new \ReflectionProperty(Spool::class, 'handle'))->getValue();
        flock($handle, LOCK_UN);
        fclose($handle);

        foreach (['handle' => null, 'file' => null, 'bytes' => 0] as $property => $value) {
            (new \ReflectionProperty(Spool::class, $property))->setValue(null, $value);
        }
    }

    private function spoolFiles(): array
    {
        return glob($this->spoolPath . '/*.jsonl') ?: [];
    }

    private function spoolContents(): string
    {
        // Arquivo ainda travado pelo processo: lê pelo próprio handle (no
        // Windows a trava é obrigatória e bloqueia outro leitor; no Linux,
        // onde os containers rodam, ela só avisa).
        $handle = (new \ReflectionProperty(Spool::class, 'handle'))->getValue();

        if ($handle !== null) {
            $position = ftell($handle);
            rewind($handle);
            $contents = (string) stream_get_contents($handle);
            fseek($handle, $position);

            return $contents;
        }

        return implode('', array_map('file_get_contents', $this->spoolFiles()));
    }
}
