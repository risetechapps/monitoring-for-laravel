<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use RiseTechApps\Monitoring\Http\Controllers\MonitoringController;

class Routes
{
    public static function register(array $options = []): void
    {

        $prefix = 'monitoring';

        $middleware = Arr::wrap($options['middleware'] ?? 'api');
        $middleware[] = 'monitoring.disable';

        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        // Autorização automática (opcional)
        if (isset($options['authorize'])) {
            $middleware[] = 'can:' . $options['authorize'];
        }

        // Rate limit opcional
        if (isset($options['rate_limit'])) {
            $middleware[] = 'throttle:' . $options['rate_limit'];
        }

        // Montando grupo final
        $group = array_merge($options, [
            'prefix'     => $prefix,
            'middleware' => $middleware,
            'as'         => $options['as'] ?? 'monitoring.',
        ]);

        unset($group['authorize'], $group['rate_limit']);

        Route::group($group, function () {

            Route::get('/', [MonitoringController::class, 'index'])->name('index');
            Route::get('/{id}', [MonitoringController::class, 'show'])->name('show');
            Route::get('/type/{type}', [MonitoringController::class, 'types'])->name('type');
            Route::post('/tags', [MonitoringController::class, 'tags'])->name('tags');
        });
    }
}
