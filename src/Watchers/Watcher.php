<?php

namespace RiseTechApps\Monitoring\Watchers;

abstract class Watcher
{

    public $options = [];

    public function __construct(array $options = [])
    {

        $this->options = $options;
    }

    abstract public function register($app);
}
