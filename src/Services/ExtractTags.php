<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use ReflectionClass;
use stdClass;

class ExtractTags
{
    /**
     * Obtém as tags para o objeto fornecido.
     *
     * Este método verifica se o objeto tem tags explícitas. Se não,
     * obtém os modelos associados ao objeto e formata essas informações
     * para obter as tags.
     *
     * @param  mixed  $target O objeto do qual extrair as tags.
     * @return array As tags extraídas.
     */
    public static function from($target): array
    {
        if ($tags = static::explicitTags([$target])) {
            return $tags;
        }

        return static::modelsFor([$target])->map(function ($model) {
            return FormatModel::given($model);
        })->all();
    }

    /**
     * Determina as tags para o trabalho fornecido.
     *
     * Este método extrai tags explícitas do trabalho. Se não houver tags
     * explícitas, obtém os modelos associados aos alvos do trabalho e
     * formata essas informações para obter as tags.
     *
     * @param  mixed  $job O trabalho do qual extrair as tags.
     * @return array As tags extraídas.
     */
    public static function fromJob($job): array
    {
        if ($tags = static::extractExplicitTags($job)) {
            return $tags;
        }

        return static::modelsFor(static::targetsFor($job))->map(function ($model) {
            return FormatModel::given($model);
        })->all();
    }

    /**
     * Determina as tags para o array fornecido.
     *
     * Este método resolve cada valor no array para extrair possíveis tags,
     * e formata os modelos encontrados para obter as tags.
     *
     * @param  array  $data O array do qual extrair as tags.
     * @return array As tags extraídas.
     */
    public static function fromArray(array $data): array
    {
        return collect($data)->map(function ($value) {
            return static::resolveValue($value);
        })->collapse()->filter()->map(function ($model) {
            return FormatModel::given($model);
        })->all();
    }

    /**
     * Extrai tags explícitas do objeto de trabalho.
     *
     * Este método verifica se o trabalho é uma instância de `CallQueuedListener`
     * e, se for, obtém as tags associadas ao ouvinte. Caso contrário, extrai
     * tags explícitas dos alvos do trabalho.
     *
     * @param  mixed  $job O trabalho do qual extrair as tags explícitas.
     * @return array As tags explícitas extraídas.
     */
    protected static function extractExplicitTags($job): array
    {
        return $job instanceof CallQueuedListener
            ? static::tagsForListener($job)
            : static::explicitTags(static::targetsFor($job));
    }

    /**
     * Determina as tags para o ouvinte de trabalho fornecido.
     *
     * Este método extrai o ouvinte e o evento do trabalho e obtém tags
     * associadas a ambos.
     *
     * @param  mixed  $job O trabalho do qual extrair as tags para o ouvinte.
     * @return array As tags extraídas.
     */
    protected static function tagsForListener($job): array
    {
        return collect(
            [static::extractListener($job), static::extractEvent($job)]
        )->map(function ($job) {
            return static::from($job);
        })->collapse()->unique()->toArray();
    }

    /**
     * Determina tags explícitas para os alvos fornecidos.
     *
     * Este método verifica se os alvos fornecidos têm um método `tags()`.
     * Se tiver, obtém as tags desse método. Caso contrário, retorna um array vazio.
     *
     * @param  array  $targets Os alvos dos quais extrair tags explícitas.
     * @return array As tags explícitas extraídas.
     */
    protected static function explicitTags(array $targets): array
    {
        return collect($targets)->map(function ($target) {
            return method_exists($target, 'tags') ? $target->tags() : [];
        })->collapse()->unique()->all();
    }

    /**
     * Obtém os alvos reais para o trabalho fornecido.
     *
     * Este método determina o tipo do trabalho e retorna os alvos associados,
     * como evento, mailable ou notificação.
     *
     * @param  mixed  $job O trabalho do qual obter os alvos.
     * @return array Os alvos do trabalho.
     */
    protected static function targetsFor($job): array
    {
        switch (true) {
            case $job instanceof BroadcastEvent:
                return [$job->event];
            case $job instanceof CallQueuedListener:
                return [static::extractEvent($job)];
            case $job instanceof SendQueuedMailable:
                return [$job->mailable];
            case $job instanceof SendQueuedNotifications:
                return [$job->notification];
            default:
                return [$job];
        }
    }

    /**
     * Obtém os modelos do objeto fornecido.
     *
     * Este método usa reflexão para acessar propriedades privadas e protegidas
     * do objeto e retorna todos os modelos encontrados.
     *
     * @param  array  $targets Os alvos dos quais obter os modelos.
     * @return \Illuminate\Support\Collection Coleção de modelos encontrados.
     */
    protected static function modelsFor(array $targets)
    {
        return collect($targets)->map(function ($target) {
            return collect((new ReflectionClass($target))->getProperties())->map(function ($property) use ($target) {
                $property->setAccessible(true);

                if (PHP_VERSION_ID < 70400 || ! is_object($target) || $property->isInitialized($target)) {
                    return static::resolveValue($property->getValue($target));
                }
            })->collapse()->filter();
        })->collapse()->unique();
    }

    /**
     * Extrai o ouvinte de um trabalho enfileirado.
     *
     * Este método usa reflexão para criar uma nova instância do ouvinte
     * a partir da classe especificada no trabalho.
     *
     * @param  mixed  $job O trabalho do qual extrair o ouvinte.
     * @return mixed O ouvinte extraído.
     *
     * @throws \ReflectionException Se houver um erro ao criar uma nova instância.
     */
    protected static function extractListener($job)
    {
        return (new ReflectionClass($job->class))->newInstanceWithoutConstructor();
    }

    /**
     * Extrai o evento de um trabalho enfileirado.
     *
     * Este método verifica se o dado do trabalho contém um objeto e, se contiver,
     * retorna o primeiro objeto encontrado. Caso contrário, retorna uma nova instância
     * de `stdClass`.
     *
     * @param  mixed  $job O trabalho do qual extrair o evento.
     * @return mixed O evento extraído.
     */
    protected static function extractEvent($job)
    {
        return isset($job->data[0]) && is_object($job->data[0])
            ? $job->data[0]
            : new stdClass;
    }

    /**
     * Resolve o valor fornecido.
     *
     * Este método verifica o tipo do valor e retorna uma coleção contendo
     * o valor se for um modelo ou uma coleção de modelos.
     *
     * @param  mixed  $value O valor a ser resolvido.
     * @return \Illuminate\Support\Collection|null A coleção de modelos, ou null se não for aplicável.
     */
    protected static function resolveValue($value)
    {
        switch (true) {
            case $value instanceof Model:
                return collect([$value]);
            case $value instanceof Collection:
                return $value->flatten();
        }
    }
}
