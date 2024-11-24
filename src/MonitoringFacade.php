<?php

namespace RiseTechApps\Monitoring;

use Illuminate\Support\Facades\Facade;

/**
 * @see \RiseTechApps\Monitoring\Skeleton\SkeletonClass
 */
class MonitoringFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'monitoring';
    }
}
