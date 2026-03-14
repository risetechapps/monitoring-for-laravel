<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\Monitoring\Services\RetentionService;

/**
 * Comando Artisan para gerenciar a política de retenção de logs.
 *
 * Uso:
 *   php artisan monitoring:retention
 *   php artisan monitoring:retention --days=60 --format=csv --disk=s3
 *   php artisan monitoring:retention --dry-run
 */
class MonitoringRetentionCommand extends Command
{
    protected $signature = 'monitoring:retention
                            {--days=90         : Número de dias de retenção (padrão: 90)}
                            {--format=json     : Formato do backup — json ou csv}
                            {--disk=local      : Disco do Storage para o backup}
                            {--chunk=500       : Registros processados por lote}
                            {--dry-run         : Simula a execução sem gravar ou remover nada}
                            {--force           : Executa sem pedir confirmação (recomendado no scheduler)}';

    protected $description = 'Exporta logs antigos para o Storage e os remove do banco (política de retenção)';

    public function __construct(private readonly RetentionService $retentionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days      = (int)  $this->option('days');
        $format    = (string) $this->option('format');
        $disk      = (string) $this->option('disk');
        $chunk     = (int)  $this->option('chunk');
        $dryRun    = (bool) $this->option('dry-run');
        $force     = (bool) $this->option('force');

        // Validações
        if (!in_array($format, ['json', 'csv'], true)) {
            $this->error("Formato inválido: {$format}. Use 'json' ou 'csv'.");
            return self::FAILURE;
        }

        if ($days < 1) {
            $this->error('O número de dias deve ser maior que zero.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<fg=cyan>┌─────────────────────────────────────────┐</>");
        $this->line("<fg=cyan>│   Monitoring — Política de Retenção     │</>");
        $this->line("<fg=cyan>└─────────────────────────────────────────┘</>");
        $this->newLine();

        $this->table(
            ['Parâmetro', 'Valor'],
            [
                ['Dias de retenção',    $days],
                ['Formato de backup',   strtoupper($format)],
                ['Disco (Storage)',     $disk],
                ['Registros por lote', $chunk],
                ['Modo dry-run',       $dryRun ? 'SIM' : 'NÃO'],
            ]
        );

        if ($dryRun) {
            $this->warn('⚠  DRY-RUN: nenhuma alteração será feita no banco ou no Storage.');
            $this->newLine();
        }

        // Confirmação interativa (skip se --force ou dry-run)
        if (!$dryRun && !$force && !$this->confirm("Continuar com a retenção dos logs com mais de {$days} dias?")) {
            $this->line('Operação cancelada pelo usuário.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->previewDryRun($days);
            return self::SUCCESS;
        }

        // Execução real
        $this->info('Iniciando exportação e remoção dos logs antigos...');
        $this->newLine();

        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% registros processados [%bar%] %elapsed:6s%');
        $bar->start();

        $stats = $this->retentionService->run(
            retentionDays: $days,
            format: $format,
            disk: $disk,
            chunkSize: $chunk
        );

        $bar->finish();
        $this->newLine(2);

        // Resultado
        $this->info('✔  Retenção concluída.');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Registros exportados', number_format($stats['exported'])],
                ['Registros removidos',  number_format($stats['deleted'])],
                ['Arquivos gerados',     count($stats['files'])],
                ['Erros',                count($stats['errors'])],
            ]
        );

        if (!empty($stats['files'])) {
            $this->newLine();
            $this->line('<fg=green>Arquivos gerados no Storage:</>');
            foreach ($stats['files'] as $file) {
                $this->line("  • {$file}");
            }
        }

        if (!empty($stats['errors'])) {
            $this->newLine();
            $this->error('Erros encontrados durante a retenção:');
            foreach ($stats['errors'] as $error) {
                $this->line("  <fg=red>✗</> {$error}");
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function previewDryRun(int $days): void
    {
        $this->info("Contagem de registros que seriam exportados/removidos (mais de {$days} dias):");
        // Apenas informa — não executa nada
        $this->line('  Execute sem --dry-run para aplicar a retenção.');
    }
}
