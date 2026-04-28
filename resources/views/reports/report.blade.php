<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $report['period_label'] }} - {{ $report['app_name'] }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f7fa;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header .subtitle {
            font-size: 16px;
            opacity: 0.9;
        }

        .header .meta {
            margin-top: 20px;
            font-size: 14px;
            opacity: 0.8;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-healthy {
            background: #10b981;
            color: white;
        }

        .status-warning {
            background: #f59e0b;
            color: white;
        }

        .status-danger {
            background: #ef4444;
            color: white;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .card-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .card-icon.blue { background: #dbeafe; }
        .card-icon.green { background: #d1fae5; }
        .card-icon.red { background: #fee2e2; }
        .card-icon.yellow { background: #fef3c7; }
        .card-icon.purple { background: #ede9fe; }

        .card-title {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }

        .card-value {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
        }

        .card-change {
            font-size: 12px;
            margin-top: 8px;
            font-weight: 600;
        }

        .change-up { color: #10b981; }
        .change-down { color: #ef4444; }

        .section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }

        td {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }

        tr:hover td {
            background: #f9fafb;
        }

        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-fill.blue { background: #3b82f6; }
        .progress-fill.green { background: #10b981; }
        .progress-fill.red { background: #ef4444; }
        .progress-fill.yellow { background: #f59e0b; }

        .exception-class {
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
            color: #dc2626;
            background: #fee2e2;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .trend-box {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .trend-value {
            font-size: 28px;
            font-weight: 700;
        }

        .trend-label {
            font-size: 14px;
            color: #6b7280;
        }

        .trend-change {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
            font-weight: 600;
        }

        .footer {
            text-align: center;
            padding: 40px 0;
            color: #9ca3af;
            font-size: 14px;
        }

        .footer a {
            color: #667eea;
            text-decoration: none;
        }

        @media print {
            body {
                background: white;
            }
            .header {
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>{{ $report['period_label'] }}</h1>
            <div class="subtitle">{{ $report['app_name'] }} — Ambiente: {{ $report['environment'] }}</div>
            <div class="meta">
                Período: {{ $report['date_range']['start']->format('d/m/Y H:i') }} — {{ $report['date_range']['end']->format('d/m/Y H:i') }}<br>
                Gerado em: {{ \Carbon\Carbon::parse($report['generated_at'])->format('d/m/Y H:i:s') }}
            </div>
        </div>

        @php
            $summary = $report['summary'];
            $statusClass = $summary['error_rate_percent'] < 1 ? 'status-healthy' :
                          ($summary['error_rate_percent'] < 5 ? 'status-warning' : 'status-danger');
            $statusText = $summary['error_rate_percent'] < 1 ? 'Saudável' :
                         ($summary['error_rate_percent'] < 5 ? 'Atenção' : 'Crítico');
        @endphp

        <!-- Summary Cards -->
        <div class="cards-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon blue">📊</div>
                    <div class="card-title">Total de Eventos</div>
                </div>
                <div class="card-value">{{ number_format($summary['total_events']) }}</div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon green">🌐</div>
                    <div class="card-title">Requisições HTTP</div>
                </div>
                <div class="card-value">{{ number_format($summary['total_requests']) }}</div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon red">🚨</div>
                    <div class="card-title">Exceções</div>
                </div>
                <div class="card-value">{{ number_format($summary['total_exceptions']) }}</div>
                <div class="card-change {{ $report['trends']['exceptions']['trend'] === 'up' ? 'change-up' : 'change-down' }}">
                    {{ $report['trends']['exceptions']['change_percent'] > 0 ? '+' : '' }}{{ $report['trends']['exceptions']['change_percent'] }}% vs período anterior
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon yellow">⚙️</div>
                    <div class="card-title">Jobs Falhos</div>
                </div>
                <div class="card-value">{{ number_format($summary['failed_jobs']) }}</div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon purple">⏱️</div>
                    <div class="card-title">Tempo Médio</div>
                </div>
                <div class="card-value">{{ number_format($summary['avg_response_time_ms'], 0) }}<span style="font-size: 16px;">ms</span></div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon {{ $summary['error_rate_percent'] < 1 ? 'green' : ($summary['error_rate_percent'] < 5 ? 'yellow' : 'red') }}">📈</div>
                    <div class="card-title">Taxa de Erro</div>
                </div>
                <div class="card-value">{{ $summary['error_rate_percent'] }}%</div>
                <span class="status-badge {{ $statusClass }}">{{ $statusText }}</span>
            </div>
        </div>

        <!-- Performance Section -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <div class="section-icon">🚀</div>
                    Métricas de Performance
                </div>
            </div>

            @php $perf = $report['performance']; @endphp
            <div class="cards-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="card" style="padding: 20px;">
                    <div class="card-title">Apdex Score</div>
                    <div class="card-value" style="font-size: 36px; margin-top: 10px;">{{ $perf['apdex_score'] }}</div>
                    <span class="status-badge {{ $perf['apdex_score'] >= 0.94 ? 'status-healthy' : ($perf['apdex_score'] >= 0.7 ? 'status-warning' : 'status-danger') }}">
                        {{ $perf['apdex_rating'] }}
                    </span>
                </div>

                <div class="card" style="padding: 20px;">
                    <div class="card-title">Tempo Mínimo</div>
                    <div class="card-value" style="font-size: 28px; margin-top: 10px;">{{ number_format($perf['min_response_time_ms'], 0) }}ms</div>
                </div>

                <div class="card" style="padding: 20px;">
                    <div class="card-title">Tempo Máximo</div>
                    <div class="card-value" style="font-size: 28px; margin-top: 10px;">{{ number_format($perf['max_response_time_ms'], 0) }}ms</div>
                </div>

                <div class="card" style="padding: 20px;">
                    <div class="card-title">Queries Lentas</div>
                    <div class="card-value" style="font-size: 28px; margin-top: 10px; color: {{ $perf['slow_queries_count'] > 100 ? '#ef4444' : '#10b981' }};">
                        {{ number_format($perf['slow_queries_count']) }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Events by Type -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <div class="section-icon">📋</div>
                    Eventos por Tipo
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Quantidade</th>
                        <th>Percentual</th>
                        <th>Distribuição</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['by_type'] as $type)
                    <tr>
                        <td style="display: flex; align-items: center; gap: 8px;">
                            <span>{{ $type['label'] }}</span>
                        </td>
                        <td><strong>{{ number_format($type['count']) }}</strong></td>
                        <td>{{ $type['percentage'] }}%</td>
                        <td style="width: 40%;">
                            <div class="progress-bar">
                                <div class="progress-fill {{ $type['percentage'] > 50 ? 'blue' : ($type['percentage'] > 25 ? 'green' : 'yellow') }}"
                                     style="width: {{ $type['percentage'] }}%"></div>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Top Errors -->
        @if(!empty($report['top_errors']))
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <div class="section-icon">🐛</div>
                    Top 10 Erros (Não Resolvidos)
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Exceção</th>
                        <th>Ocorrências</th>
                        <th>Última Ocorrência</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['top_errors'] as $error)
                    <tr>
                        <td>
                            <span class="exception-class">{{ class_basename($error['exception_class']) }}</span>
                            <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">{{ $error['exception_class'] }}</div>
                        </td>
                        <td><strong style="color: #dc2626;">{{ number_format($error['count']) }}</strong></td>
                        <td>{{ \Carbon\Carbon::parse($error['last_occurrence'])->format('d/m/Y H:i') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <!-- Trends -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <div class="section-icon">📊</div>
                    Tendências vs Período Anterior
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                @foreach($report['trends'] as $key => $trend)
                @php $isPositive = ($key === 'exceptions' && $trend['trend'] === 'down') || ($key === 'requests' && $trend['trend'] === 'up'); @endphp
                <div class="trend-box">
                    <div>
                        <div class="trend-label">{{ ucfirst($key) }}</div>
                        <div class="trend-value">{{ number_format($trend['current']) }}</div>
                    </div>
                    <div style="margin-left: auto; text-align: right;">
                        <div class="trend-change {{ $isPositive ? 'change-up' : 'change-down' }}">
                            {{ $trend['trend'] === 'up' ? '📈' : '📉' }}
                            {{ $trend['change_percent'] > 0 ? '+' : '' }}{{ $trend['change_percent'] }}%
                        </div>
                        <div style="font-size: 12px; color: #9ca3af; margin-top: 4px;">
                            Anterior: {{ number_format($trend['previous']) }}
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Relatório gerado automaticamente pelo <strong>Monitoring for Laravel</strong></p>
            <p style="margin-top: 8px;">Para mais detalhes, acesse o <a href="{{ url('/monitoring') }}">painel de monitoramento</a></p>
        </div>
    </div>
</body>
</html>
