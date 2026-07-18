<?php

namespace RiseTechApps\Monitoring\Watchers;

abstract class Watcher
{

    public function __construct(public array $options = [])
    {
    }

    abstract public function register($app);
}
