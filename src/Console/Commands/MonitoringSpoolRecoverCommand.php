<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;
use RiseTechApps\Monitoring\Support\Spool;

/**
 * Grava no banco as entradas do rascunho em disco (Support\Spool) que ficaram
 * sem dono: processo morto antes do flush (SIGKILL, OOM, timeout do PHP-FPM,
 * deploy) ou flush que falhou (banco fora do ar).
 *
 * "Sem dono" = a trava do arquivo está livre. Arquivo de processo vivo fica
 * travado e é pulado. Rodar duas vezes não duplica (o uuid é único).
 *
 * Agendado a cada minuto (monitoring.spool.auto_recover). Uso manual:
 *   php artisan monitoring:spool-recover
 */
class MonitoringSpoolRecoverCommand extends Command
{
    protected $signature = 'monitoring:spool-recover';

    protected $description = 'Grava no banco as entradas do rascunho em disco deixadas por processos que morreram ou por flush que falhou';

    public function handle(MonitoringRepositoryInterface $repository): int
    {
        $directory = Spool::directory();

        if (!is_dir($directory)) {
            return self::SUCCESS;
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.jsonl') ?: [];
        $recovered = 0;
        $failed = 0;

        foreach ($files as $file) {
            $handle = @fopen($file, 'r+b');

            if ($handle === false) {
                continue;
            }

            // Trava ocupada = o processo dono está vivo.
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }

            $rows = $this->readRows($handle);

            try {
                if ($rows !== []) {
                    $repository->create($rows);
                    $recovered += count($rows);
                }

                flock($handle, LOCK_UN);
                fclose($handle);
                @unlink($file);
            } catch (\Throwable $e) {
                // Banco ainda fora: o arquivo fica para a próxima execução.
                flock($handle, LOCK_UN);
                fclose($handle);
                $failed++;

                $this->error("Falha ao gravar {$file}: {$e->getMessage()}");
            }
        }

        if ($recovered > 0 || $failed > 0) {
            $this->info("{$recovered} entrada(s) recuperada(s); {$failed} arquivo(s) mantido(s) para nova tentativa.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param resource $handle */
    private function readRows($handle): array
    {
        $rows = [];

        rewind($handle);

        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true);

            // Linha incompleta (processo morto no meio da escrita) é descartada.
            if (is_array($row) && isset($row['uuid'], $row['type'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
