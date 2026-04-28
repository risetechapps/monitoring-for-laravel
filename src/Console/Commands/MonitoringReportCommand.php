<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\Monitoring\Services\Reporting\ReportService;

/**
 * Comando Artisan para gerar relatórios de monitoramento.
 *
 * Uso:
 *   php artisan monitoring:report daily
 *   php artisan monitoring:report weekly --send
 *   php artisan monitoring:report monthly --channels=email,slack
 *   php artisan monitoring:report daily --preview
 */
class MonitoringReportCommand extends Command
{
    protected $signature = 'monitoring:report
                            {period=daily : Tipo de período — daily, weekly, monthly}
                            {--send : Envia o relatório automaticamente}
                            {--channels=email : Canais de envio separados por vírgula}
                            {--preview : Mostra preview do relatório no console}
                            {--save : Salva o relatório HTML no storage}';

    protected $description = 'Gera relatórios periódicos de monitoramento';

    public function __construct(private readonly ReportService $reportService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->argument('period');

        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $this->error("Período inválido: {$period}. Use: daily, weekly, monthly");
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<fg=cyan>┌─────────────────────────────────────────┐</>");
        $this->line("<fg=cyan>│    Monitoring — Gerar Relatório         │</>");
        $this->line("<fg=cyan>└─────────────────────────────────────────┘</>");
        $this->newLine();

        $this->info("Gerando relatório {$period}...");
        $this->newLine();

        // Gera o relatório
        $report = $this->reportService->generate($period);

        // Mostra preview se solicitado
        if ($this->option('preview')) {
            $this->showPreview($report);
        }

        // Salva HTML
        if ($this->option('save')) {
            $path = $this->saveReport($report);
            $this->info("✔ Relatório salvo em: {$path}");
        }

        // Envia notificações
        if ($this->option('send')) {
            $channels = explode(',', $this->option('channels'));
            $this->info("Enviando relatório para: " . implode(', ', $channels));

            $this->reportService->sendReport($report, $channels);

            $this->info('✔ Relatório enviado com sucesso!');
        }

        $this->newLine();
        $this->info('✔ Relatório gerado com sucesso!');

        return self::SUCCESS;
    }

    /**
     * Mostra preview do relatório no console.
     */
    private function showPreview(array $report): void
    {
        $this->newLine();
        $this->line("<fg=yellow>─────────────────────────────────────────</>");
        $this->line("  {$report['period_label']}");
        $this->line("<fg=yellow>─────────────────────────────────────────</>");
        $this->newLine();

        // Resumo
        $this->line('<fg=green>Resumo:</>');
        $summary = $report['summary'];
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total de Eventos', number_format($summary['total_events'])],
                ['Exceções', number_format($summary['total_exceptions'])],
                ['Requisições HTTP', number_format($summary['total_requests'])],
                ['Jobs', number_format($summary['total_jobs'])],
                ['Jobs Falhos', number_format($summary['failed_jobs'])],
                ['Taxa de Erro', "{$summary['error_rate_percent']}%"],
                ['Taxa de Sucesso', "{$summary['success_rate_percent']}%"],
                ['Tempo Médio de Resposta', "{$summary['avg_response_time_ms']}ms"],
            ]
        );

        // Performance
        $this->newLine();
        $this->line('<fg=green>Métricas de Performance:</>');
        $perf = $report['performance'];
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Apdex Score', "{$perf['apdex_score']} ({$perf['apdex_rating']})"],
                ['Tempo Mínimo', "{$perf['min_response_time_ms']}ms"],
                ['Tempo Máximo', "{$perf['max_response_time_ms']}ms"],
                ['Tempo Médio', "{$perf['avg_response_time_ms']}ms"],
                ['Queries Lentas', $perf['slow_queries_count']],
            ]
        );

        // Top Erros
        if (!empty($report['top_errors'])) {
            $this->newLine();
            $this->line('<fg=red>Top Erros (não resolvidos):</>');
            $errors = collect($report['top_errors'])->take(5)->map(fn($e) => [
                class_basename($e['exception_class']),
                $e['count'],
                $e['last_occurrence'],
            ]);
            $this->table(['Exceção', 'Ocorrências', 'Última'], $errors->toArray());
        }

        // Tendências
        $this->newLine();
        $this->line('<fg=green>Tendências (vs período anterior):</>');
        $trends = $report['trends'];
        $trendEmoji = fn($trend) => $trend === 'up' ? '📈' : '📉';
        $this->table(
            ['Métrica', 'Período Atual', 'Período Anterior', 'Mudança'],
            [
                [
                    'Exceções',
                    $trends['exceptions']['current'],
                    $trends['exceptions']['previous'],
                    $trendEmoji($trends['exceptions']['trend']) . " {$trends['exceptions']['change_percent']}%"
                ],
                [
                    'Requisições',
                    $trends['requests']['current'],
                    $trends['requests']['previous'],
                    $trendEmoji($trends['requests']['trend']) . " {$trends['requests']['change_percent']}%"
                ],
            ]
        );

        $this->newLine();
    }

    /**
     * Salva o relatório HTML no storage.
     */
    private function saveReport(array $report): string
    {
        $html = view('monitoring::reports.report', compact('report'))->render();

        $directory = 'monitoring/reports';
        $filename = "{$directory}/{$report['period']}_{$report['date_range']['start']->format('Y-m-d')}.html";

        // Garante que o diretório existe
        if (!\Storage::disk('local')->exists($directory)) {
            \Storage::disk('local')->makeDirectory($directory, 0755, true);
        }

        \Storage::disk('local')->put($filename, $html);

        return \Storage::disk('local')->path($filename);
    }
}
