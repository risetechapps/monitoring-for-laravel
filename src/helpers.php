<?php

declare(strict_types=1);

if (!function_exists('loggly')) {
    function loggly(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class);
    }
}

if (!function_exists('logglyInfo')) {
    function logglyInfo(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::INFO);
    }
}

if (!function_exists('logglyEmergency')) {
    function logglyEmergency(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::EMERGENCY);
    }
}

if (!function_exists('logglyAlert')) {
    function logglyAlert(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::ALERT);
    }
}

if (!function_exists('logglyCritical')) {
    function logglyCritical(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::CRITICAL);
    }
}

if (!function_exists('logglyError')) {
    function logglyError(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::ERROR);
    }
}

if (!function_exists('logglyWarning')) {
    function logglyWarning(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::WARNING);
    }
}

if (!function_exists('logglyNotice')) {
    function logglyNotice(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::NOTICE);
    }
}

if (!function_exists('logglyModel')) {
    function logglyModel(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::MODEL);
    }
}

if (!function_exists('logglyDebug')) {
    function logglyDebug(): RiseTechApps\Monitoring\Loggly\Loggly
    {
        return app(\RiseTechApps\Monitoring\Loggly\Loggly::class)->level(\RiseTechApps\Monitoring\Loggly\Loggly::DEBUG);
    }
}

if (! function_exists('monitoring')) {
    /**
     * Retorna a instância do Monitoring para registrar métricas.
     *
     * Exemplos:
     * monitoring()->gauge('pedidos_pendentes', 42);
     * monitoring()->increment('checkout_concluido');
     * monitoring()->histogram('tempo_api', 150);
     * monitoring()->timer('processamento', fn() => $this->processar());
     */
    function monitoring(): \RiseTechApps\Monitoring\Monitoring
    {
        return app('monitoring');
    }
}
