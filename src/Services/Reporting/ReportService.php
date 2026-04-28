<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services\Reporting;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Contracts\ReportHandlerInterface;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Events\ReportGenerated;
use RiseTechApps\Monitoring\Repository\Contracts\MonitoringRepositoryInterface;

/**
 * Serviço de geração de relatórios de monitoramento.
 *
 * Gera relatórios periódicos (diário, semanal, mensal) com métricas
 * e estatísticas do sistema de monitoramento.
 *
 * Suporta handlers customizados para envio de relatórios, permitindo
 * 100% de autonomia na forma de notificação.
 */
class ReportService
{
    /** Handlers customizados registrados */
    private static array $customHandlers = [];

    /** Desabilita notificações padrão */
    private static bool $disableDefaultNotifications = false;

    public function __construct(
        private readonly MonitoringRepositoryInterface $monitoringRepository,
    ) {}

    /**
     * Registra um handler de relatório customizado.
     */
    public static function registerHandler(string $name, ReportHandlerInterface $handler): void
    {
        self::$customHandlers[$name] = $handler;
    }

    /**
     * Remove um handler registrado.
     */
    public static function unregisterHandler(string $name): void
    {
        unset(self::$customHandlers[$name]);
    }

    /**
     * Desabilita notificações padrão (Email, Slack, Discord).
     * Útil quando você quer usar apenas notificações customizadas.
     */
    public static function disableDefaultNotifications(): void
    {
        self::$disableDefaultNotifications = true;
    }

    /**
     * Habilita notificações padrão.
     */
    public static function enableDefaultNotifications(): void
    {
        self::$disableDefaultNotifications = false;
    }

    /**
     * Retorna todos os handlers registrados.
     */
    public static function getHandlers(): array
    {
        return self::$customHandlers;
    }

    /**
     * Retorna o driver de banco atual.
     */
    private function getDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Retorna a sintaxe SQL para extrair texto de JSON.
     * Compatível com MySQL/MariaDB e PostgreSQL.
     */
    private function jsonExtractText(string $column, string $path): string
    {
        $driver = $this->getDriver();

        if ($driver === 'pgsql') {
            // PostgreSQL: content->>'status'
            return "{$column}->>'{$path}'";
        }

        // MySQL/MariaDB
        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '\$.{$path}'))";
    }

    /**
     * Gera um relatório para o período especificado.
     *
     * @param string $period Tipo de período: 'daily', 'weekly', 'monthly'
     * @param Carbon|null $date Data de referência (default: agora)
     * @return array Dados do relatório
     */
    public function generate(string $period, ?Carbon $date = null): array
    {
        $date = $date ?? now();

        $range = $this->getDateRange($period, $date);

        return [
            'period' => $period,
            'period_label' => $this->getPeriodLabel($period, $range['start'], $range['end']),
            'generated_at' => now()->toIso8601String(),
            'app_name' => config('app.name'),
            'environment' => config('app.env'),
            'date_range' => $range,
            'summary' => $this->generateSummary($range['start'], $range['end']),
            'by_type' => $this->generateByType($range['start'], $range['end']),
            'top_errors' => $this->generateTopErrors($range['start'], $range['end']),
            'performance' => $this->generatePerformanceMetrics($range['start'], $range['end']),
            'trends' => $this->generateTrends($range['start'], $range['end']),
        ];
    }

    /**
     * Determina o intervalo de datas baseado no período.
     */
    private function getDateRange(string $period, Carbon $date): array
    {
        return match ($period) {
            'daily' => [
                'start' => $date->copy()->startOfDay()->subDay(),
                'end' => $date->copy()->startOfDay()->subSecond(),
            ],
            'weekly' => [
                'start' => $date->copy()->startOfWeek()->subWeek(),
                'end' => $date->copy()->startOfWeek()->subSecond(),
            ],
            'monthly' => [
                'start' => $date->copy()->startOfMonth()->subMonth(),
                'end' => $date->copy()->startOfMonth()->subSecond(),
            ],
            default => [
                'start' => $date->copy()->startOfDay()->subDay(),
                'end' => $date->copy(),
            ],
        };
    }

    /**
     * Label amigável para o período.
     */
    private function getPeriodLabel(string $period, Carbon $start, Carbon $end): string
    {
        return match ($period) {
            'daily' => "Relatório Diário - {$start->format('d/m/Y')}",
            'weekly' => "Relatório Semanal - {$start->format('d/m')} a {$end->format('d/m/Y')}",
            'monthly' => "Relatório Mensal - {$start->format('F Y')}",
            default => "Relatório - {$start->format('d/m/Y')} a {$end->format('d/m/Y')}",
        };
    }

    /**
     * Gera resumo geral das métricas.
     */
    private function generateSummary(Carbon $start, Carbon $end): array
    {
        $total = DB::table('monitoring')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $exceptions = DB::table('monitoring')
            ->where('type', EntryType::EXCEPTION)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $requests = DB::table('monitoring')
            ->where('type', EntryType::REQUEST)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $jobs = DB::table('monitoring')
            ->where('type', EntryType::JOB)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $failedJobs = DB::table('monitoring')
            ->where('type', EntryType::JOB)
            ->whereRaw("{$this->jsonExtractText('content', 'status')} = 'failed'")
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $avgResponseTime = $this->calculateAvgResponseTime($start, $end);

        $errorRate = $requests > 0 ? round(($exceptions / $requests) * 100, 2) : 0;

        return [
            'total_events' => $total,
            'total_exceptions' => $exceptions,
            'total_requests' => $requests,
            'total_jobs' => $jobs,
            'failed_jobs' => $failedJobs,
            'avg_response_time_ms' => round($avgResponseTime, 2),
            'error_rate_percent' => $errorRate,
            'success_rate_percent' => round(100 - $errorRate, 2),
        ];
    }

    /**
     * Calcula tempo médio de resposta (compatível com MySQL e PostgreSQL).
     */
    private function calculateAvgResponseTime(Carbon $start, Carbon $end): float
    {
        $driver = $this->getDriver();

        if ($driver === 'pgsql') {
            // PostgreSQL: converte texto para float usando ::float
            $result = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull(DB::raw("content->>'duration'"))
                ->avg(DB::raw("(content->>'duration')::float"));
        } else {
            // MySQL/MariaDB
            $result = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->avg(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(content, '\$.duration'))"));
        }

        return (float) ($result ?? 0);
    }

    /**
     * Gera estatísticas por tipo de evento.
     */
    private function generateByType(Carbon $start, Carbon $end): array
    {
        $types = [
            EntryType::REQUEST => 'Requisições HTTP',
            EntryType::EXCEPTION => 'Exceções',
            EntryType::JOB => 'Jobs',
            EntryType::QUERY => 'Queries',
            EntryType::CACHE => 'Cache',
            EntryType::EVENT => 'Eventos',
            EntryType::MAIL => 'E-mails',
            EntryType::NOTIFICATION => 'Notificações',
        ];

        $results = [];

        foreach ($types as $type => $label) {
            $count = DB::table('monitoring')
                ->where('type', $type)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            if ($count > 0) {
                $results[] = [
                    'type' => $type,
                    'label' => $label,
                    'count' => $count,
                    'percentage' => 0, // Calculado depois
                ];
            }
        }

        // Calcula percentuais
        $total = array_sum(array_column($results, 'count'));
        foreach ($results as &$result) {
            $result['percentage'] = $total > 0 ? round(($result['count'] / $total) * 100, 2) : 0;
        }

        return $results;
    }

    /**
     * Gera top erros do período.
     */
    private function generateTopErrors(Carbon $start, Carbon $end): array
    {
        $driver = $this->getDriver();

        if ($driver === 'pgsql') {
            return DB::table('monitoring')
                ->where('type', EntryType::EXCEPTION)
                ->whereNull('resolved_at')
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw("content->>'class' as exception_class")
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('MAX(created_at) as last_occurrence')
                ->groupBy(DB::raw("content->>'class'"))
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'exception_class' => $row->exception_class,
                    'count' => $row->count,
                    'last_occurrence' => $row->last_occurrence,
                ])
                ->toArray();
        }

        return DB::table('monitoring')
            ->where('type', EntryType::EXCEPTION)
            ->whereNull('resolved_at')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.class')) as exception_class")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('MAX(created_at) as last_occurrence')
            ->groupBy(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.class'))"))
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'exception_class' => $row->exception_class,
                'count' => $row->count,
                'last_occurrence' => $row->last_occurrence,
            ])
            ->toArray();
    }

    /**
     * Gera métricas de performance.
     */
    private function generatePerformanceMetrics(Carbon $start, Carbon $end): array
    {
        $driver = $this->getDriver();

        if ($driver === 'pgsql') {
            // PostgreSQL usa operador ->> e casting ::float
            $percentiles = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull(DB::raw("content->>'duration'"))
                ->selectRaw('AVG((content->>\'duration\')::float) as avg_duration')
                ->selectRaw('MIN((content->>\'duration\')::float) as min_duration')
                ->selectRaw('MAX((content->>\'duration\')::float) as max_duration')
                ->first();

            // Queries lentas
            $slowQueries = DB::table('monitoring')
                ->where('type', EntryType::QUERY)
                ->whereBetween('created_at', [$start, $end])
                ->whereRaw("(content->>'time_ms')::float > 100")
                ->count();

            // Apdex score (simplificado)
            $satisfied = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->whereRaw("(content->>'duration')::float <= 500")
                ->count();

            $tolerating = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->whereRaw("(content->>'duration')::float > 500")
                ->whereRaw("(content->>'duration')::float <= 2000")
                ->count();
        } else {
            // MySQL/MariaDB
            $percentiles = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.duration')) IS NOT NULL")
                ->selectRaw("AVG(JSON_UNQUOTE(JSON_EXTRACT(content, '$.duration'))) as avg_duration")
                ->selectRaw("MIN(JSON_UNQUOTE(JSON_EXTRACT(content, '$.duration'))) as min_duration")
                ->selectRaw("MAX(JSON_UNQUOTE(JSON_EXTRACT(content, '$.duration'))) as max_duration")
                ->first();

            // Queries lentas
            $slowQueries = DB::table('monitoring')
                ->where('type', EntryType::QUERY)
                ->whereBetween('created_at', [$start, $end])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.time_ms')) > 100")
                ->count();

            // Apdex score (simplificado)
            $satisfied = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.duration')) <= 500")
                ->count();

            $tolerating = DB::table('monitoring')
                ->where('type', EntryType::REQUEST)
                ->whereBetween('created_at', [$start, $end])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.duration')) > 500")
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.duration')) <= 2000")
                ->count();
        }

        $total = $satisfied + $tolerating;
        $apdex = $total > 0 ? round(($satisfied + ($tolerating / 2)) / $total, 2) : 1.0;

        return [
            'avg_response_time_ms' => round($percentiles->avg_duration ?? 0, 2),
            'min_response_time_ms' => round($percentiles->min_duration ?? 0, 2),
            'max_response_time_ms' => round($percentiles->max_duration ?? 0, 2),
            'slow_queries_count' => $slowQueries,
            'apdex_score' => $apdex,
            'apdex_rating' => $this->getApdexRating($apdex),
        ];
    }

    /**
     * Gera tendências comparando com período anterior.
     */
    private function generateTrends(Carbon $start, Carbon $end): array
    {
        $previousStart = $start->copy()->subDays($start->diffInDays($end));
        $previousEnd = $start->copy()->subSecond();

        $currentExceptions = DB::table('monitoring')
            ->where('type', EntryType::EXCEPTION)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $previousExceptions = DB::table('monitoring')
            ->where('type', EntryType::EXCEPTION)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->count();

        $currentRequests = DB::table('monitoring')
            ->where('type', EntryType::REQUEST)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $previousRequests = DB::table('monitoring')
            ->where('type', EntryType::REQUEST)
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->count();

        return [
            'exceptions' => [
                'current' => $currentExceptions,
                'previous' => $previousExceptions,
                'change_percent' => $previousExceptions > 0
                    ? round((($currentExceptions - $previousExceptions) / $previousExceptions) * 100, 2)
                    : 0,
                'trend' => $currentExceptions > $previousExceptions ? 'up' : 'down',
            ],
            'requests' => [
                'current' => $currentRequests,
                'previous' => $previousRequests,
                'change_percent' => $previousRequests > 0
                    ? round((($currentRequests - $previousRequests) / $previousRequests) * 100, 2)
                    : 0,
                'trend' => $currentRequests > $previousRequests ? 'up' : 'down',
            ],
        ];
    }

    /**
     * Retorna rating do Apdex.
     */
    private function getApdexRating(float $score): string
    {
        return match (true) {
            $score >= 0.94 => 'Excelente',
            $score >= 0.85 => 'Bom',
            $score >= 0.70 => 'Justo',
            default => 'Ruim',
        };
    }

    /**
     * Envia o relatório via notificação.
     *
     * Processa handlers customizados primeiro, depois os canais padrão
     * (se não desabilitados e se o evento não marcou como handled).
     */
    public function sendReport(array $report, array $channels = ['email']): void
    {
        $html = $this->renderHtml($report);

        // Dispara evento para listeners externos
        $event = new ReportGenerated($report, $html, $channels);
        event($event);

        // Se o evento marcou como handled e suprimir padrão, retorna
        if ($event->handled && $event->suppressDefault) {
            return;
        }

        // Processa handlers customizados
        $this->processCustomHandlers($report, $html, $channels);

        // Processa notificações padrão (se não desabilitadas)
        if (!self::$disableDefaultNotifications && !$event->suppressDefault) {
            $this->processDefaultChannels($report, $html, $channels);
        }
    }

    /**
     * Processa handlers customizados registrados.
     */
    private function processCustomHandlers(array $report, string $html, array $channels): void
    {
        $handlersConfig = config('monitoring.reports.custom_handlers', []);

        foreach (self::$customHandlers as $name => $handler) {
            $config = $handlersConfig[$name] ?? [];

            if (!$handler->isConfigured($config)) {
                continue;
            }

            // Verifica se este handler suporta algum dos canais solicitados
            $supportedChannels = $handler->getSupportedChannels();
            $canHandle = empty($supportedChannels) || !empty(array_intersect($channels, $supportedChannels));

            if (!$canHandle) {
                continue;
            }

            try {
                $handler->send($report, $html, $config);
            } catch (\Exception $e) {
                \Log::error("Falha no handler de relatório customizado: {$name}", [
                    'error' => $e->getMessage(),
                    'period' => $report['period'] ?? 'unknown',
                ]);
            }
        }
    }

    /**
     * Processa canais padrão de notificação.
     */
    private function processDefaultChannels(array $report, string $html, array $channels): void
    {
        foreach ($channels as $channel) {
            match ($channel) {
                'email' => $this->sendEmail($report, $html),
                'slack' => $this->sendSlack($report),
                'discord' => $this->sendDiscord($report),
                default => null,
            };
        }
    }

    /**
     * Renderiza HTML do relatório.
     */
    private function renderHtml(array $report): string
    {
        return view('monitoring::reports.report', compact('report'))->render();
    }

    /**
     * Envia por email.
     */
    private function sendEmail(array $report, string $html): void
    {
        $config = config('monitoring.reports.email', []);

        if (!($config['enabled'] ?? false)) {
            return;
        }

        \Mail::html($html, function ($message) use ($report, $config) {
            $message->to($config['to'])
                ->from($config['from'])
                ->subject($report['period_label']);
        });
    }

    /**
     * Envia resumo para Slack.
     */
    private function sendSlack(array $report): void
    {
        $webhook = config('monitoring.alerts.slack_webhook');

        if (!$webhook) {
            return;
        }

        $summary = $report['summary'];

        $message = "📊 *{$report['period_label']}*\n\n";
        $message .= "*Resumo:*\n";
        $message .= "• Eventos: {$summary['total_events']}\n";
        $message .= "• Exceções: {$summary['total_exceptions']}\n";
        $message .= "• Requisições: {$summary['total_requests']}\n";
        $message .= "• Taxa de Erro: {$summary['error_rate_percent']}%\n";
        $message .= "• Tempo Médio: {$summary['avg_response_time_ms']}ms\n";

        \Http::post($webhook, [
            'text' => $message,
            'username' => 'Monitoring Reports',
            'icon_emoji' => ':chart_with_upwards_trend:',
        ]);
    }

    /**
     * Envia resumo para Discord.
     */
    private function sendDiscord(array $report): void
    {
        $webhook = config('monitoring.alerts.discord_webhook');

        if (!$webhook) {
            return;
        }

        $summary = $report['summary'];

        $message = "📊 **{$report['period_label']}**\n\n";
        $message .= "**Resumo:**\n";
        $message .= "• Eventos: {$summary['total_events']}\n";
        $message .= "• Exceções: {$summary['total_exceptions']}\n";
        $message .= "• Requisições: {$summary['total_requests']}\n";
        $message .= "• Taxa de Erro: {$summary['error_rate_percent']}%\n";

        \Http::post($webhook, [
            'content' => $message,
            'username' => 'Monitoring Reports',
        ]);
    }
}
