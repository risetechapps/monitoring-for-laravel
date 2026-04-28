<?php

namespace RiseTechApps\Monitoring\Entry;

class EntryType
{
    /** @context  Constante de tipo de dados */
    public const CACHE = 'cache';
    public const COMMAND = 'command';
    public const EVENT = 'event';
    public const EXCEPTION = 'exception';
    public const JOB = 'job';
    public const LOG = 'log';
    public const MAIL = 'mail';
    public const METRIC = 'metric';
    public const MODEL = 'model';
    public const NOTIFICATION = 'notification';
    public const QUERY = 'query';
    public const REQUEST = 'request';
    public const SCHEDULED_TASK = 'schedule';
    public const CLIENT_REQUEST = 'client_request';
    public const GATE = 'gate';

    public static function getTypes(): array
    {
        return [
            self::CACHE,
            self::COMMAND,
            self::EVENT,
            self::EXCEPTION,
            self::JOB,
            self::LOG,
            self::MAIL,
            self::METRIC,
            self::MODEL,
            self::NOTIFICATION,
            self::QUERY,
            self::REQUEST,
            self::SCHEDULED_TASK,
            self::CLIENT_REQUEST,
            self::GATE,
        ];
    }
}

