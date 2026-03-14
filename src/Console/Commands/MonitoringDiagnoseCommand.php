<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Loggly\Loggly;
use RiseTechApps\Monitoring\Monitoring;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

/**
 * Diagnóstico completo do pipeline de monitoramento.
 *
 * Uso:
 *   php artisan monitoring:diagnose
 *   php artisan monitoring:diagnose --write   (tenta gravar um log real no banco)
 */
class MonitoringDiagnoseCommand extends Command
{
    protected $signature = 'monitoring:diagnose
                            {--write : Grava um log de teste real no banco e verifica a inserção}';

    protected $description = 'Diagnostica o pipeline completo do Monitoring (config, DB, singleton, buffer, flush)';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<fg=cyan>╔══════════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║   Monitoring — Diagnóstico Completo      ║</>');
        $this->line('<fg=cyan>╚══════════════════════════════════════════╝</>');
        $this->newLine();

        $allPassed = true;

        // ── 1. Config ─────────────────────────────────────────────────────────
        $this->line('<fg=yellow>① Configuração</>');

        $enabled    = config('monitoring.enabled');
        $driver     = config('monitoring.driver');
        $bufferSize = config('monitoring.buffer_size');
        $connection = config('monitoring.drivers.database.connection');

        $this->table(['Chave', 'Valor'], [
            ['monitoring.enabled',                  $enabled    ? '<fg=green>true</>'  : '<fg=red>false</>'],
            ['monitoring.driver',                   $driver],
            ['monitoring.buffer_size',              $bufferSize],
            ['monitoring.drivers.database.connection', $connection],
        ]);

        if (!$enabled) {
            $this->warn('  ⚠  monitoring.enabled = false — nenhum log será gravado.');
            $this->line('  Defina MONITORING_ENABLED=true no seu .env');
            $allPassed = false;
        }

        if ($driver !== 'database') {
            $this->warn("  ⚠  driver = {$driver} — logs vão para arquivo, não para o banco.");
        }

        // ── 2. Conexão com banco ───────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>② Conexão com o banco de dados</>');

        try {
            DB::connection($connection)->getPdo();
            $this->line("  <fg=green>✔  Conexão [{$connection}] estabelecida com sucesso.</>");
        } catch (\Throwable $e) {
            $this->error("  ✗  FALHA na conexão [{$connection}]: " . $e->getMessage());
            $this->line('  → Verifique DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD no .env');
            $allPassed = false;
        }

        // ── 3. Tabela monitoring existe ────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>③ Tabela "monitoring" no banco</>');

        try {
            $tableExists = DB::connection($connection)
                ->getSchemaBuilder()
                ->hasTable('monitoring');

            if ($tableExists) {
                $count = DB::connection($connection)->table('monitoring')->count();
                $this->line("  <fg=green>✔  Tabela existe. Registros atuais: {$count}</>");
            } else {
                $this->error('  ✗  Tabela "monitoring" NÃO EXISTE.');
                $this->line('  → Execute: php artisan migrate');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->error('  ✗  Erro ao verificar tabela: ' . $e->getMessage());
            $allPassed = false;
        }

        // ── 4. Loggly singleton ────────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>④ Loggly registrado como singleton no container</>');

        try {
            $a = app(Loggly::class);
            $b = app(Loggly::class);

            if ($a === $b) {
                $this->line('  <fg=green>✔  Loggly é singleton — mesma instância retornada em chamadas consecutivas.</>');
            } else {
                $this->error('  ✗  Loggly NÃO é singleton — nova instância criada a cada chamada helper.');
                $this->line('  → Verifique se MonitoringServiceProvider registra: $this->app->singleton(Loggly::class, ...)');
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            $this->error('  ✗  Erro ao resolver Loggly: ' . $e->getMessage());
            $allPassed = false;
        }

        // ── 5. Repositório ─────────────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>⑤ Repositório (MonitoringRepositoryInterface)</>');

        try {
            $repo = app(MonitoringRepositoryInterface::class);
            $this->line('  <fg=green>✔  Repositório resolvido: ' . get_class($repo) . '</>');
        } catch (\Throwable $e) {
            $this->error('  ✗  Erro ao resolver repositório: ' . $e->getMessage());
            $allPassed = false;
        }

        // ── 6. Monitoring::isEnabled() ─────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>⑥ Estado estático de Monitoring</>');

        $isEnabled = Monitoring::isEnabled();
        if ($isEnabled) {
            $this->line('  <fg=green>✔  Monitoring::isEnabled() = true</>');
        } else {
            $this->warn('  ⚠  Monitoring::isEnabled() = false');
            $this->line('  → Monitoring::disable() foi chamado. Verifique se algum middleware desabilitou o monitoring.');
        }

        // ── 7. Log interno ─────────────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>⑦ Arquivo de log interno (erros silenciosos)</>');

        $internalLog = storage_path('logs/monitoring-internal.log');
        if (file_exists($internalLog)) {
            $size  = filesize($internalLog);
            $lines = count(file($internalLog));
            $this->warn("  ⚠  Arquivo existe com {$lines} linha(s) — {$size} bytes.");
            $this->line('  Últimas 5 linhas:');
            $last = array_slice(file($internalLog), -5);
            foreach ($last as $line) {
                $this->line('     <fg=red>' . trim($line) . '</>');
            }
            $this->line("  Caminho completo: {$internalLog}");
            $allPassed = false;
        } else {
            $this->line('  <fg=green>✔  Nenhum erro interno registrado.</>');
        }

        // ── 8. Teste real de gravação ──────────────────────────────────────────
        if ($this->option('write')) {
            $this->newLine();
            $this->line('<fg=yellow>⑧ Teste real de gravação no banco</>');

            $countBefore = 0;
            try {
                $countBefore = DB::connection($connection)->table('monitoring')->count();
            } catch (\Throwable) {}

            try {
                logglyInfo()
                    ->withTags(['diagnose' => true, 'command' => 'monitoring:diagnose'])
                    ->log('[Monitoring Diagnose] Teste de gravação — ' . now()->toIso8601String());

                // Força flush imediato (em console, já ocorre automaticamente)
                Monitoring::flushAll();

                $countAfter = DB::connection($connection)->table('monitoring')->count();

                if ($countAfter > $countBefore) {
                    $this->line("  <fg=green>✔  Log gravado com sucesso! Registros: {$countBefore} → {$countAfter}</>");
                } else {
                    $this->error("  ✗  Log NÃO foi gravado. Contagem antes/depois: {$countBefore}/{$countAfter}");
                    $this->line('  → Verifique storage/logs/monitoring-internal.log para erros silenciosos.');
                    $allPassed = false;
                }
            } catch (\Throwable $e) {
                $this->error('  ✗  Exceção ao gravar: ' . $e->getMessage());
                $allPassed = false;
            }
        }

        // ── Resultado final ────────────────────────────────────────────────────
        $this->newLine();
        if ($allPassed) {
            $this->line('<fg=green>╔══════════════════════════════════════════╗</>');
            $this->line('<fg=green>║  ✔  Todos os checks passaram!            ║</>');
            $this->line('<fg=green>╚══════════════════════════════════════════╝</>');
            if (!$this->option('write')) {
                $this->line('  Dica: use --write para testar uma gravação real no banco.');
            }
        } else {
            $this->newLine();
            $this->error('Alguns checks falharam. Veja os detalhes acima.');
        }

        $this->newLine();
        return $allPassed ? self::SUCCESS : self::FAILURE;
    }
}
