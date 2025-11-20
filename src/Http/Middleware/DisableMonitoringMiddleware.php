<?php

namespace RiseTechApps\Monitoring\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RiseTechApps\Monitoring\Monitoring;

class DisableMonitoringMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try{
            app(Monitoring::class)->disable();
            return $next($request);
        }catch (\Exception $e){
            return $next($request);
        }

    }
}

