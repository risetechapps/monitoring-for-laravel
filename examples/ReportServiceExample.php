<?php

/**
 * Exemplo de uso do ReportService para relatórios customizados
 *
 * Demonstra como gerar relatórios programaticamente e enviar
 * por diferentes canais.
 */

declare(strict_types=1);

namespace App\Services;

use RiseTechApps\Monitoring\Services\Reporting\ReportService;
use Carbon\Carbon;

class CustomReportService
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    /**
     * Gera relatório diário e envia por email.
     */
    public function sendDailyReport(): void
    {
        $report = $this->reportService->generate('daily');

        // Envia apenas por email
        $this->reportService->sendReport($report, ['email']);
    }

    /**
     * Gera relatório semanal com dados customizados.
     */
    public function generateWeeklySummary(): array
    {
        // Gera para a semana anterior
        $report = $this->reportService->generate('weekly');

        // Adiciona análises customizadas
        $report['custom_analysis'] = [
            'recommendations' => $this->analyzePerformance($report),
            'action_items' => $this->identifyIssues($report),
        ];

        return $report;
    }

    /**
     * Gera relatório para data específica.
     */
    public function reportForDate(Carbon $date): array
    {
        return $this->reportService->generate('daily', $date);
    }

    /**
     * Envia relatório multi-canal.
     */
    public function broadcastReport(string $period): void
    {
        $report = $this->reportService->generate($period);

        // Envia para todos os canais configurados
        $this->reportService->sendReport($report, ['email', 'slack', 'discord']);
    }

    /**
     * Analisa performance e gera recomendações.
     */
    private function analyzePerformance(array $report): array
    {
        $recommendations = [];

        $apdex = $report['performance']['apdex_score'];
        $errorRate = $report['summary']['error_rate_percent'];
        $avgResponse = $report['summary']['avg_response_time_ms'];

        if ($apdex < 0.85) {
            $recommendations[] = 'Apdex score abaixo do ideal. Considere otimizar endpoints críticos.';
        }

        if ($errorRate > 5) {
            $recommendations[] = 'Taxa de erro acima de 5%. Recomendada revisão urgente.';
        }

        if ($avgResponse > 1000) {
            $recommendations[] = 'Tempo médio de resposta alto. Verifique queries e cache.';
        }

        return $recommendations;
    }

    /**
     * Identifica problemas críticos.
     */
    private function identifyIssues(array $report): array
    {
        $issues = [];

        foreach ($report['top_errors'] as $error) {
            if ($error['count'] > 50) {
                $issues[] = [
                    'severity' => 'high',
                    'exception' => $error['exception_class'],
                    'count' => $error['count'],
                ];
            }
        }

        return $issues;
    }
}

/**
 * Exemplo em um Controller
 */

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RiseTechApps\Monitoring\Services\Reporting\ReportService;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    /**
     * Gera relatório sob demanda.
     */
    public function generate(string $period): JsonResponse
    {
        $report = $this->reportService->generate($period);

        return response()->json([
            'period' => $period,
            'generated_at' => now()->toIso8601String(),
            'summary' => $report['summary'],
            'download_url' => $this->saveAndGetUrl($report),
        ]);
    }

    /**
     * Salva relatório e retorna URL para download.
     */
    private function saveAndGetUrl(array $report): string
    {
        $html = view('monitoring::reports.report', compact('report'))->render();

        $filename = "reports/monitoring_{$report['period']}_" . now()->format('Y-m-d') . '.html';

        \Storage::disk('public')->put($filename, $html);

        return \Storage::disk('public')->url($filename);
    }

    /**
     * Dashboard com relatório em tempo real.
     */
    public function dashboard(): JsonResponse
    {
        $daily = $this->reportService->generate('daily');
        $weekly = $this->reportService->generate('weekly');

        return response()->json([
            'daily' => [
                'error_rate' => $daily['summary']['error_rate_percent'],
                'total_requests' => $daily['summary']['total_requests'],
                'apdex' => $daily['performance']['apdex_score'],
            ],
            'weekly' => [
                'error_rate' => $weekly['summary']['error_rate_percent'],
                'total_requests' => $weekly['summary']['total_requests'],
                'apdex' => $weekly['performance']['apdex_score'],
            ],
            'trends' => $weekly['trends'],
        ]);
    }
}

/**
 * Exemplo de Scheduler customizado
 */

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use RiseTechApps\Monitoring\Services\Reporting\ReportService;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Relatório personalizado toda sexta-feira
        $schedule->call(function () {
            $reportService = app(ReportService::class);

            $report = $reportService->generate('weekly');

            // Só envia se houver problemas críticos
            if ($report['summary']['error_rate_percent'] > 10) {
                $reportService->sendReport($report, ['email', 'slack']);
            }
        })->weeklyOn(5, '17:00'); // Sexta-feira às 17h

        // Relatório mensal para stakeholders (simplificado)
        $schedule->call(function () {
            $reportService = app(ReportService::class);

            $report = $reportService->generate('monthly');

            // Envia PDF customizado (usando pacote externo)
            $pdf = \PDF::loadView('reports.pdf', compact('report'));

            \Mail::send([], [], function ($message) use ($pdf) {
                $message->to('stakeholders@empresa.com')
                    ->subject('Relatório Mensal de Performance')
                    ->attachData($pdf->output(), 'relatorio-mensal.pdf');
            });
        })->monthlyOn(1, '09:00');
    }
}
