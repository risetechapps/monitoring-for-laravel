<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionException;

class ExtractProperties
{
    /** Limite de 32KB por propriedade */
    private const MAX_PROPERTY_SIZE = 32768;

    /** Profundidade máxima de objetos aninhados */
    private const MAX_DEPTH = 3;

    /**
     * @throws ReflectionException
     */
    public static function from($target): array
    {
        try {
            return collect((new ReflectionClass($target))->getProperties())
                ->mapWithKeys(function ($property) use ($target) {
                    $property->setAccessible(true);

                    if (PHP_VERSION_ID >= 70400 && ! $property->isInitialized($target)) {
                        return [];
                    }

                    try {
                        $value = $property->getValue($target);
                        return [$property->getName() => self::formatValue($value)];
                    } catch (\Throwable) {
                        return [$property->getName() => '_unreadable'];
                    }
                })->toArray();
        } catch (\Throwable $e) {
            return ['_error' => 'Could not extract properties: ' . $e->getMessage()];
        }
    }

    private static function formatValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '_max_depth_reached';
        }

        if ($value instanceof Model) {
            return FormatModel::given($value);
        }

        if (is_object($value)) {
            return [
                'class'      => get_class($value),
                'properties' => self::safeJsonSerialize($value),
            ];
        }

        return self::safeJsonSerialize($value);
    }

    private static function safeJsonSerialize(mixed $value): mixed
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json === false) {
                return ['_error' => 'Could not serialize'];
            }

            if (strlen($json) > self::MAX_PROPERTY_SIZE) {
                return ['_purged' => 'Value too large (' . round(strlen($json) / 1024, 1) . 'KB)'];
            }

            return json_decode($json, true);
        } catch (\Throwable $e) {
            return ['_error' => 'Serialization failed: ' . $e->getMessage()];
        }
    }
}
