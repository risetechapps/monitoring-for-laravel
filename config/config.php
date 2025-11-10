<?php

/*
 * You can place your custom package configuration in here.
 */
return [

    'enabled' => env('MONITORING_ENABLED', true),

    'driver' => env('MONITORING_DRIVER', 'mysql'),

    'environments' => [
        'only' => array_filter(array_map('trim', explode(',', env('MONITORING_ENV_ONLY', '')))),
        'except' => array_filter(array_map('trim', explode(',', env('MONITORING_ENV_EXCEPT', '')))),
    ],

    'buffer_size' => (int) env('MONITORING_BUFFER_SIZE', 5),

    'response_macros' => env('MONITORING_RESPONSE_MACROS', true),

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
            ],
        ],
        \RiseTechApps\Monitoring\Watchers\EventWatcher::class => [
            'enabled' => true,
            'options' => [
                'ignore' => [
                    \RiseTechApps\Monitoring\Watchers\RequestWatcher::class,
                    \RiseTechApps\Monitoring\Watchers\EventWatcher::class,
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
    ],

    'drivers' => [
        'mysql' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
        ],
        'pgsql' => [
            'connection' => env('DB_CONNECTION', 'pgsql'),
        ],
        'http' => [
            'token' => env('MONITORING_HTTP_TOKEN', ''),
            'endpoint' => env('MONITORING_HTTP_ENDPOINT', 'https://monitoring.app.br/api/logs'),
            'timeout' => env('MONITORING_HTTP_TIMEOUT', 10),
            'retry' => [
                'times' => env('MONITORING_HTTP_RETRY_TIMES', 3),
                'sleep' => env('MONITORING_HTTP_RETRY_SLEEP', 100),
            ],
        ],
    ],
];
