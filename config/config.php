<?php

/*
 * You can place your custom package configuration in here.
 */
return [

    'enabled' => env('MONITORING_ENABLED', true),

    'driver' => env('MONITORING_DRIVER', 'single'),

    'buffer_size' => (int)env('MONITORING_BUFFER_SIZE', 5),

    'watchers' => [
        \RiseTechApps\Monitoring\Watchers\RequestWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore_http_methods' => [
                    'options',
                ],
                'ignore_status_codes' => [],
                'ignore_paths' => [
                    'telescope',
                    'telescope-api',
                ],
                'size_limit' => (int) env('MONITORING_RESPONSE_SIZE_LIMIT_KB', 32),
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\EventWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore' => [

                ],
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\ExceptionWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\CommandWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\GateWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\JobWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\QueueWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\ScheduleWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\NotificationWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\MailWatcher::class => ['enabled' => true],
        \RiseTechApps\Monitoring\Watchers\ClientRequestWatcher::class => ['enabled' => true],
    ],

    'drivers' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'pgsql'),
        ],
        'http' => [
            'token'   => env('MONITORING_HTTP_TOKEN', ''),
            'timeout' => (int) env('MONITORING_HTTP_TIMEOUT', 5),
            'retry'   => [
                'times' => (int) env('MONITORING_HTTP_RETRY_TIMES', 0),
                'sleep' => (int) env('MONITORING_HTTP_RETRY_SLEEP', 0),
            ],
        ],

        'single' => [
            'path' => storage_path('logs/monitoring.log'),
        ]
    ],
];
