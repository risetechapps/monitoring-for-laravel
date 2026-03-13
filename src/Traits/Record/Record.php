<?php

namespace RiseTechApps\Monitoring\Traits\Record;

use Exception;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Entry\EntryType;
use RiseTechApps\Monitoring\Entry\IncomingEntry;

trait Record
{
    public static function recordRequest(IncomingEntry $entry): void
    {
        static::record(EntryType::REQUEST, $entry);
    }

    public static function recordQuery(IncomingEntry $entry): void
    {
        static::record(EntryType::QUERY, $entry);
    }

    public static function recordEvent(IncomingEntry $entry): void
    {
        static::record(EntryType::EVENT, $entry);
    }

    public static function recordClientRequest(IncomingEntry $entry): void
    {
        static::record(EntryType::CLIENT_REQUEST, $entry);
    }

    public static function recordException(IncomingEntry $entry): void
    {
        static::record(EntryType::EXCEPTION, $entry);
    }


    public static function recordCommand(IncomingEntry $entry): void
    {
        static::record(EntryType::COMMAND, $entry);
    }

    public static function recordGate(IncomingEntry $entry): void
    {
        static::record(EntryType::GATE, $entry);
    }

    public static function recordJob(IncomingEntry $entry): void
    {
        static::record(EntryType::JOB, $entry);
    }

    public static function recordScheduledCommand(IncomingEntry $entry): void
    {
        static::record(EntryType::SCHEDULED_TASK, $entry);
    }

    public static function recordNotification($entry): void
    {
        static::record(EntryType::NOTIFICATION, $entry);
    }

    public static function recordModel(IncomingEntry $entry): void
    {
        static::record(EntryType::MODEL, $entry);
    }

    public static function recordMail($entry):void
    {
        static::record(EntryType::MAIL, $entry);
    }

    public static function recordLoggly(IncomingEntry $entry): void
    {
        static::record(EntryType::LOG, $entry);
    }
}
