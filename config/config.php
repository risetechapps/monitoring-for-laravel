<?php

/*
 * You can place your custom package configuration in here.
 */
return [

    'enabled' => env('MONITORING_ENABLED', true),

    'driver' => env('MONITORING_DRIVER', 'mysql'),

    'drivers' => [
        'mysql' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
        ],
        'pgsql' => [
            'connection' => env('DB_CONNECTION', 'pgsql'),
        ],
        'http' => [
            'token' => env('MONITORING_HTTP_TOKEN', ''),
        ],
    ],
];
