<?php

namespace RiseTechApps\Monitoring\Services;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;

class FormatModel
{
    /**
     * Formate o modelo fornecido para uma string legível.
     *
     * @param  Model  $model
     * @return string
     */
    public static function given($model): string
    {
        if ($model instanceof Pivot && ! $model->incrementing) {
            $keys = [
                $model->getAttribute($model->getForeignKey()),
                $model->getAttribute($model->getRelatedKey()),
            ];
        } else {
            $keys = $model->getKey();
        }

        return get_class($model).':'.implode('_', array_map(function ($value) {
            return $value instanceof BackedEnum ? $value->value : $value;
        }, Arr::wrap($keys)));
    }
}
