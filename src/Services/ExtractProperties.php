<<<<<<< HEAD
<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionException;

class ExtractProperties
{
    /**
     * Extraia as propriedades do objeto fornecido em formato de array.
     *
     * A matriz fornecida está pronta para armazenamento.
     *
     * @param mixed $target
     * @return array
     * @throws ReflectionException
     */
    public static function from($target)
    {
        return collect((new ReflectionClass($target))->getProperties())
            ->mapWithKeys(function ($property) use ($target) {
                $property->setAccessible(true);

                if (PHP_VERSION_ID >= 70400 && ! $property->isInitialized($target)) {
                    return [];
                }

                if (($value = $property->getValue($target)) instanceof Model) {
                    return [$property->getName() => FormatModel::given($value)];
                } elseif (is_object($value)) {
                    return [
                        $property->getName() => [
                            'class' => get_class($value),
                            'properties' => json_decode(json_encode($value), true),
                        ],
                    ];
                } else {
                    return [$property->getName() => json_decode(json_encode($value), true)];
                }
            })->toArray();
    }
}
=======
<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionException;

class ExtractProperties
{
    /**
     * Extraia as propriedades do objeto fornecido em formato de array.
     *
     * A matriz fornecida está pronta para armazenamento.
     *
     * @param mixed $target
     * @return array
     * @throws ReflectionException
     */
    public static function from($target)
    {
        return collect((new ReflectionClass($target))->getProperties())
            ->mapWithKeys(function ($property) use ($target) {
                $property->setAccessible(true);

                if (PHP_VERSION_ID >= 70400 && ! $property->isInitialized($target)) {
                    return [];
                }

                if (($value = $property->getValue($target)) instanceof Model) {
                    return [$property->getName() => FormatModel::given($value)];
                } elseif (is_object($value)) {
                    return [
                        $property->getName() => [
                            'class' => get_class($value),
                            'properties' => json_decode(json_encode($value), true),
                        ],
                    ];
                } else {
                    return [$property->getName() => json_decode(json_encode($value), true)];
                }
            })->toArray();
    }
}
>>>>>>> origin/main
