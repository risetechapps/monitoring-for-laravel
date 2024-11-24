<?php

/*
 * You can place your custom package configuration in here.
 */
return [

    'driver' => env('MONITORING_DRIVER', 'mysql'),

    'drivers' => [
        'mysql' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
        ],
        'http' => [
            'url' => env('MONITORING_HTTP_URL', 'https://example.com/logs'),
            'token' => env('MONITORING_HTTP_TOKEN', ''),
        ],
    ],
];
