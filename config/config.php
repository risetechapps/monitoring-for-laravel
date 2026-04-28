<?php

/*
|--------------------------------------------------------------------------
| Monitoring for Laravel — Configuração
|--------------------------------------------------------------------------
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Habilitar / Desabilitar o monitoramento
    |--------------------------------------------------------------------------
    */
    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Driver de armazenamento: 'database' | 'single'
    |--------------------------------------------------------------------------
    */
    'driver' => env('MONITORING_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Tamanho máximo do buffer antes de persistir
    |--------------------------------------------------------------------------
    */
    'buffer_size' => (int) env('MONITORING_BUFFER_SIZE', 5),

    /*
    |--------------------------------------------------------------------------
    | Watchers ativos
    |--------------------------------------------------------------------------
    */
    'watchers' => [
        \RiseTechApps\Monitoring\Watchers\RequestWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore_http_methods' => ['options'],
                'ignore_status_codes' => [],
                'ignore_paths'        => ['telescope', 'telescope-api', 'up'],
                'size_limit'          => (int) env('MONITORING_RESPONSE_SIZE_LIMIT_KB', 32),
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\EventWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore' => [],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\ExceptionWatcher::class => [
            'enabled' => true,
            'options' => [
                // Ignorar exceções específicas (classes completas)
                'ignore_exceptions' => [
                    // \Illuminate\Validation\ValidationException::class,
                    // \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
                ],
                // Ignorar exceções cujas mensagens contenham estes textos
                'ignore_messages_containing' => [
                    // 'password',
                    // 'sensitive_data',
                ],
                // Ignorar exceções de arquivos que contenham estes caminhos
                'ignore_files_containing' => [
                    // '/vendor/',
                ],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\CommandWatcher::class => [
            'enabled' => true,
            'options' => [
                // Comandos sempre ignorados (além dos defaults internos)
                'ignore' => [
                    // 'my:custom-command',
                ],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\GateWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore_abilities' => [],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\JobWatcher::class => [
            'enabled' => true,
            'options' => [
                // Namespaces de jobs que devem ser ignorados
                'ignore_namespaces' => [
                    // 'App\Jobs\Internal\',
                ],
                // Jobs específicos que devem ser ignorados (classe completa)
                'ignore_jobs' => [
                    // \App\Jobs\HeavyLoggingJob::class,
                ],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\QueueWatcher::class        => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\ScheduleWatcher::class => [
            'enabled' => true,
            'options' => [
                // Tarefas agendadas específicas que devem ser ignoradas
                'ignore_commands' => [
                    // 'my:scheduled-command',
                ],
                // Ignorar closures (geralmente usadas para testes)
                'ignore_closures' => false,
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\NotificationWatcher::class => [
            'enabled' => true,
            'options' => [
                // Classes de notificação que devem ser ignoradas
                'ignore_notifications' => [
                    // \App\Notifications\TestNotification::class,
                ],
                // Canais que devem ser ignorados
                'ignore_channels' => [
                    // 'broadcast',
                ],
                // Ignorar notificações anônimas (AnonymousNotifiable)
                'ignore_anonymous' => false,
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\MailWatcher::class => [
            'enabled' => true,
            'options' => [
                // Classes Mailable que devem ser ignoradas
                'ignore_mailables' => [
                    // \App\Mail\TestMail::class,
                ],
                // Ignorar e-mails cujo assunto contenha estes textos
                'ignore_subjects_containing' => [
                    // '[Test]',
                    // '[Local]',
                ],
                // Ignorar e-mails de remetentes específicos
                'ignore_from_addresses' => [
                    // 'noreply@example.com',
                ],
                // Ignorar e-mails para destinatários específicos
                'ignore_to_addresses' => [
                    // 'test@example.com',
                ],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\ClientRequestWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore_hosts' => [],
                'size_limit'   => 64,
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\QueryWatcher::class => [
            'enabled' => true,
            'options' => [
                // Threshold em ms para considerar query lenta
                'slow_query_threshold_ms' => (int) env('MONITORING_SLOW_QUERY_MS', 100),
                // Padrões de SQL que devem ser ignorados
                'ignore_patterns' => ['information_schema', 'migrations', 'telescope'],
                // Logar bindings das queries
                'log_bindings' => true,
                // Tamanho máximo do SQL antes de truncar
                'max_sql_length' => 5000,
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\CacheWatcher::class => [
            'enabled' => true,
            'options' => [
                // Registrar cache hits
                'track_hits' => true,
                // Registrar cache misses
                'track_misses' => true,
                // Chaves de cache que devem ser ignoradas
                'ignore_keys' => ['config', 'routes', 'telescope'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers de armazenamento
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'pgsql'),
        ],
        'single' => [
            'path' => storage_path('logs/monitoring.log'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Política de Retenção
    |--------------------------------------------------------------------------
    |
    | auto_schedule: Ativa o agendamento automático no Scheduler do Laravel.
    | days:          Logs mais antigos que este número de dias serão exportados
    |                e removidos do banco.
    | format:        Formato do arquivo de backup — 'json' ou 'csv'.
    | disk:          Disco do Storage configurado no filesystems.php.
    | time:          Horário de execução diária (formato HH:MM).
    | chunk_size:    Registros processados por lote (evita estouro de memória).
    |
    */
    'retention' => [
        'auto_schedule' => env('MONITORING_RETENTION_AUTO_SCHEDULE', false),
        'days'          => (int) env('MONITORING_RETENTION_DAYS', 90),
        'format'        => env('MONITORING_RETENTION_FORMAT', 'json'),
        'disk'          => env('MONITORING_RETENTION_DISK', 'local'),
        'time'          => env('MONITORING_RETENTION_TIME', '02:00'),
        'chunk_size'    => (int) env('MONITORING_RETENTION_CHUNK', 500),

        // Política de retenção granular por tipo
        'granular' => [
            'exceptions' => (int) env('MONITORING_RETENTION_EXCEPTIONS', 90),
            'requests'   => (int) env('MONITORING_RETENTION_REQUESTS', 30),
            'jobs'       => (int) env('MONITORING_RETENTION_JOBS', 60),
            'queries'    => (int) env('MONITORING_RETENTION_QUERIES', 7),
            'cache'      => (int) env('MONITORING_RETENTION_CACHE', 7),
            'metrics'    => (int) env('MONITORING_RETENTION_METRICS', 30),
        ],
        // Manter exceções não resolvidas além do prazo
        'keep_unresolved' => env('MONITORING_KEEP_UNRESOLVED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sistema de Alertas
    |--------------------------------------------------------------------------
    |
    | Configure notificações para eventos críticos.
    |
    */
    'alerts' => [
        'enabled' => env('MONITORING_ALERTS_ENABLED', false),

        // Webhooks
        'slack_webhook'   => env('MONITORING_SLACK_WEBHOOK'),
        'discord_webhook' => env('MONITORING_DISCORD_WEBHOOK'),

        // Email
        'email' => [
            'enabled'  => env('MONITORING_ALERTS_EMAIL_ENABLED', false),
            'to'       => explode(',', env('MONITORING_ALERTS_EMAIL_TO', '')),
            'from'     => env('MONITORING_ALERTS_EMAIL_FROM', config('mail.from.address')),
        ],

        // Thresholds para disparar alertas
        'thresholds' => [
            // Exceções por minuto
            'exceptions_per_minute' => (int) env('MONITORING_ALERT_EXCEPTIONS_PER_MINUTE', 10),
            // Jobs falhos por hora
            'failed_jobs_per_hour'  => (int) env('MONITORING_ALERT_FAILED_JOBS_PER_HOUR', 5),
            // Requisição lenta (ms)
            'slow_request_ms'       => (int) env('MONITORING_ALERT_SLOW_REQUEST_MS', 5000),
            // Queries lentas por minuto
            'slow_queries_per_minute' => (int) env('MONITORING_ALERT_SLOW_QUERIES_PER_MIN', 10),
            // Taxa de erro (%)
            'error_rate_percent'    => (float) env('MONITORING_ALERT_ERROR_RATE', 5.0),
        ],

        // Cooldown entre alertas (evitar spam)
        'cooldown_minutes' => (int) env('MONITORING_ALERT_COOLDOWN', 5),

        /*
        |--------------------------------------------------------------------------
        | Handlers Customizados de Alerta
        |--------------------------------------------------------------------------
        |
        | Configure handlers adicionais para envio de alertas. Cada handler deve
        | implementar AlertHandlerInterface e ser registrado via:
        | AlertService::registerHandler('nome', new HandlerClass());
        |
        | Exemplo:
        | 'telegram' => [
        |     'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        |     'chat_id'   => env('TELEGRAM_CHAT_ID'),
        | ],
        */
        'custom_handlers' => [
            // 'telegram' => [
            //     'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            //     'chat_id'   => env('TELEGRAM_CHAT_ID'),
            // ],
            // 'pagerduty' => [
            //     'routing_key' => env('PAGERDUTY_KEY'),
            //     'webhook'     => env('PAGERDUTY_WEBHOOK'),
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Configurações para tracking avançado de performance.
    |
    */
    'performance' => [
        // Track memory peak por requisição
        'track_memory_peaks' => env('MONITORING_TRACK_MEMORY', true),
        // Track número de conexões DB
        'track_db_connections' => env('MONITORING_TRACK_DB_CONNECTIONS', true),
        // Track cache hit/miss rate
        'track_cache_hits' => env('MONITORING_TRACK_CACHE_HITS', true),

        // Apdex thresholds (satisfação do usuário)
        'apdex' => [
            // ms - satisfatório
            'threshold' => (int) env('MONITORING_APDEX_THRESHOLD', 500),
            // ms - tolerável
            'tolerable' => (int) env('MONITORING_APDEX_TOLERABLE', 2000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relatórios Automáticos
    |--------------------------------------------------------------------------
    |
    | Configure relatórios periódicos (diário, semanal, mensal) que serão
    | gerados e enviados automaticamente via email, Slack ou Discord.
    |
    */
    'reports' => [
        'auto_schedule' => env('MONITORING_REPORTS_AUTO_SCHEDULE', false),
        'time' => env('MONITORING_REPORTS_TIME', '08:00'),
        'timezone' => env('MONITORING_REPORTS_TIMEZONE', 'America/Sao_Paulo'),

        // Configurações por período
        'daily' => [
            'enabled' => env('MONITORING_REPORT_DAILY_ENABLED', true),
            'send_at' => '08:00', // Horário do relatório diário
        ],
        'weekly' => [
            'enabled' => env('MONITORING_REPORT_WEEKLY_ENABLED', true),
            'send_at' => '08:00', // Segunda-feira
            'day' => 'monday',
        ],
        'monthly' => [
            'enabled' => env('MONITORING_REPORT_MONTHLY_ENABLED', true),
            'send_at' => '08:00', // Dia 1 do mês
        ],

        // Canais de envio
        'channels' => [
            'email' => [
                'enabled' => env('MONITORING_REPORT_EMAIL_ENABLED', true),
                'to' => explode(',', env('MONITORING_REPORT_EMAIL_TO', '')),
                'from' => env('MONITORING_REPORT_EMAIL_FROM', config('mail.from.address')),
                'subject_prefix' => env('MONITORING_REPORT_SUBJECT_PREFIX', '[MONITORING]'),
            ],
            'slack' => [
                'enabled' => env('MONITORING_REPORT_SLACK_ENABLED', false),
            ],
            'discord' => [
                'enabled' => env('MONITORING_REPORT_DISCORD_ENABLED', false),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Handlers Customizados de Relatório
        |--------------------------------------------------------------------------
        |
        | Configure handlers adicionais para envio de relatórios. Cada handler
        | deve implementar ReportHandlerInterface e ser registrado via:
        | ReportService::registerHandler('nome', new HandlerClass());
        |
        | Quando handlers customizados estão registrados, você tem 100% de controle
        | sobre como o relatório é enviado. Use o evento ReportGenerated ou
        | desabilite notificações padrão com ReportService::disableDefaultNotifications()
        |
        | Exemplo:
        | 'custom_handlers' => [
        |     'telegram' => [
        |         'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        |         'chat_id'   => env('TELEGRAM_CHAT_ID'),
        |     ],
        |     'custom_email' => [
        |         'provider' => 'sendgrid',
        |         'api_key'  => env('SENDGRID_KEY'),
        |     ],
        | ],
        */
        'custom_handlers' => [
            // 'telegram' => [
            //     'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            //     'chat_id'   => env('TELEGRAM_CHAT_ID'),
            // ],
        ],
    ],

];
