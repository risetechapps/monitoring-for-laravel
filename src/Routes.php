<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use RiseTechApps\Monitoring\Http\Controllers\MonitoringController;

class Routes
{
    public static function register(array $options = []): void
    {
        $prefix     = 'monitoring';
        $middleware = Arr::wrap($options['middleware'] ?? 'api');
        $middleware[] = 'monitoring.disable';

        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        if (isset($options['authorize'])) {
            $middleware[] = 'can:' . $options['authorize'];
        }

        if (isset($options['rate_limit'])) {
            $middleware[] = 'throttle:' . $options['rate_limit'];
        }

        $group = array_merge($options, [
            'prefix'     => $prefix,
            'middleware' => $middleware,
            'as'         => $options['as'] ?? 'monitoring.',
        ]);

        unset($group['authorize'], $group['rate_limit']);

        Route::group($group, function () {
            Route::get('/',                               [MonitoringController::class, 'index'])->name('index');
            Route::get('/health',                         [MonitoringController::class, 'health'])->name('health');
            Route::get('/search',                         [MonitoringController::class, 'search'])->name('search');
            Route::get('/compare',                        [MonitoringController::class, 'compare'])->name('compare');
            Route::get('/type/{type}',                    [MonitoringController::class, 'types'])->name('type');
            Route::post('/tags',                          [MonitoringController::class, 'tags'])->name('tags');
            Route::get('/user/{userId}',                  [MonitoringController::class, 'byUser'])->name('user');
            Route::post('/export',                        [MonitoringController::class, 'export'])->name('export');

            // Rotas de resolução de exceções
            Route::get('/exceptions/unresolved',          [MonitoringController::class, 'unresolvedExceptions'])->name('exceptions.unresolved');
            Route::post('/resolve-exception',             [MonitoringController::class, 'resolveExceptionType'])->name('exceptions.resolve-type');
            Route::post('/{id}/resolve',                  [MonitoringController::class, 'resolve'])->name('resolve');
            Route::post('/{id}/unresolve',                [MonitoringController::class, 'unresolve'])->name('unresolve');

            // Timeline por tag
            Route::get('/timeline/{tag}/{value}',         [MonitoringController::class, 'timeline'])->name('timeline');

            // GET /{id} deve vir por último para não conflitar com as rotas acima
            Route::get('/{id}',                           [MonitoringController::class, 'show'])->name('show');
        });
    }
}
