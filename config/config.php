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
                'ignore_paths'        => ['telescope', 'telescope-api'],
                'size_limit'          => (int) env('MONITORING_RESPONSE_SIZE_LIMIT_KB', 32),
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\EventWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore' => [],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\ExceptionWatcher::class    => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\CommandWatcher::class      => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\GateWatcher::class         => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\JobWatcher::class          => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\QueueWatcher::class        => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\ScheduleWatcher::class     => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\NotificationWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\MailWatcher::class         => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\ClientRequestWatcher::class => ['enabled' => true],
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
    ],

];
