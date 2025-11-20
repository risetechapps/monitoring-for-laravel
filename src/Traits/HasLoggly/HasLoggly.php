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

            /**
             * Caso especial: UPDATED
             * — Deve LOGAR somente quando NÃO for restore.
             */
            if ($eventName === 'updated') {
                static::updating(function (Model $model) {

                    // se é restore → não registra aqui
                    if (static::isRestoringAction($model)) {
                        return;
                    }

                    $modified = [];
                    foreach ($model->getDirty() as $key => $new) {
                        $modified[$key] = [
                            'old' => $model->getOriginal($key),
                            'new' => $new,
                        ];
                    }

                    Monitoring::recordModel(
                        IncomingEntry::make([
                            'model'     => get_class($model),
                            'oldValues' => $model->getRawOriginal(),
                            'newValues' => $model->getAttributes(),
                            'modified'  => $modified,
                            'action'    => 'Updated record.',
                        ])
                    );
                });

                return;
            }

            /**
             * Evento RESTORED
             * — Agora o único responsável por log de restore.
             */
            if ($eventName === 'restored') {
                static::restored(function (Model $model) {

                    Monitoring::recordModel(
                        IncomingEntry::make([
                            'model'     => get_class($model),
                            'newValues' => $model->getAttributes(),
                            'action'    => 'Restored record.',
                        ])
                    );
                });

                return;
            }

            /**
             * Eventos genéricos: created, deleted
             */
            static::registerModelEvent($eventName, function (Model $model) use ($eventName) {
                Monitoring::recordModel(
                    IncomingEntry::make([
                        'model'     => get_class($model),
                        'newValues' => $model->getAttributes(),
                        'action'    => static::getActionForEvent($eventName, $model),
                    ])
                );
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
        return collect(class_uses_recursive(static::class))
            ->contains(SoftDeletes::class);
    }

    /**
     * Detecta se o UPDATE é na verdade um RESTORE.
     */
    protected static function isRestoringAction(Model $model): bool
    {
        return static::usesSoftDeletes()
            && $model->isDirty('deleted_at')
            && is_null($model->deleted_at); // voltou do soft delete
    }

    protected static function getActionForEvent(string $eventName, Model $model): string
    {
        if ($eventName === 'deleted') {
            if (static::usesSoftDeletes()) {
                return $model->isForceDeleting()
                    ? 'Force deleted record.'
                    : 'Deleted record.';
            }

            return 'Deleted record.';
        }

        return ucfirst($eventName) . ' record.';
    }
}
