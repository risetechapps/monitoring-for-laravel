<?php

namespace RiseTechApps\Monitoring\Traits\HasLoggly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;

trait HasLoggly
{
    protected static function bootHasLoggly(): void
    {
        static::eventsToBeRecorded()->each(function ($eventName) {
            if ($eventName === 'updated') {
                static::updating(function (Model $model) {
                    $oldValues = $model->getRawOriginal();
                    $newValues = $model->getAttributes();

                    $action = static::isRestoring($oldValues) ? 'Restored record.' : 'Updated record.';
                    Monitoring::recordModel(IncomingEntry::make([
                            'model' => get_class($model),
                            'oldValues' => $oldValues,
                            'newValues' => $newValues,
                            'action' => $action,
                        ]
                    ));
                });
            }

            static::$eventName(function (Model $model) use ($eventName) {
                if($eventName !== 'updated') {
                    $action = static::getActionForEvent($eventName, $model);
                    Monitoring::recordModel(IncomingEntry::make([
                            'model' => get_class($model),
                            'newValues' => $model->getAttributes(),
                            'action' => $action,
                        ]
                    ));
                }
            });
        });
    }

    protected static function eventsToBeRecorded(): Collection
    {
        $events = collect(['created', 'updated', 'deleted']);

        if (static::usesSoftDeletes()) {
            $events->push('restored');
        }

        return $events;
    }

    protected static function usesSoftDeletes(): bool
    {
        return collect(class_uses_recursive(static::class))->contains(SoftDeletes::class);
    }

    protected static function isRestoring(array $oldValues): bool
    {
        return isset($oldValues['deleted_at']);
    }

    protected static function getActionForEvent(string $eventName, Model $model): string
    {

        if ($eventName === 'deleted') {
            return static::usesSoftDeletes() ? ($model->isForceDeleting() ?
                'Force deleted record.' : 'Deleted record.') : 'Deleted record.';

        }

        return ucfirst($eventName) . ' record.';
    }
}

