<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;

class CacheWatcher extends Watcher
{
    /** Armazena o tempo de início da operação */
    private static ?float $operationStart = null;

    /**
     * Registra os ouvintes para eventos de cache.
     */
    public function register($app): void
    {
        // Eventos de hit/miss
        if ($this->options['track_hits'] ?? true) {
            $app['events']->listen(CacheHit::class, [$this, 'recordCacheHit']);
        }

        if ($this->options['track_misses'] ?? true) {
            $app['events']->listen(CacheMissed::class, [$this, 'recordCacheMiss']);
        }

        // Eventos de escrita e deleção
        $app['events']->listen(KeyWritten::class, [$this, 'recordCacheWrite']);
        $app['events']->listen(KeyForgotten::class, [$this, 'recordCacheDelete']);
    }

    /**
     * Registra um cache hit (chave encontrada).
     */
    public function recordCacheHit(CacheHit $event): void
    {
        if ($this->shouldIgnoreKey($event->key)) {
            return;
        }

        $entry = IncomingEntry::make([
            'operation' => 'hit',
            'key' => $event->key,
            'store' => $event->storeName ?? config('cache.default'),
            'hit' => true,
        ])->tags(['cache:hit', "cache_key:{$event->key}"]);

        Monitoring::recordCache($entry);
    }

    /**
     * Registra um cache miss (chave não encontrada).
     */
    public function recordCacheMiss(CacheMissed $event): void
    {
        if ($this->shouldIgnoreKey($event->key)) {
            return;
        }

        $entry = IncomingEntry::make([
            'operation' => 'miss',
            'key' => $event->key,
            'store' => $event->storeName ?? config('cache.default'),
            'hit' => false,
        ])->tags(['cache:miss', "cache_key:{$event->key}"]);

        Monitoring::recordCache($entry);
    }

    /**
     * Registra uma escrita no cache.
     */
    public function recordCacheWrite(KeyWritten $event): void
    {
        if ($this->shouldIgnoreKey($event->key)) {
            return;
        }

        $entry = IncomingEntry::make([
            'operation' => 'write',
            'key' => $event->key,
            'store' => $event->storeName ?? config('cache.default'),
            'ttl_seconds' => $event->seconds,
            'tags' => $event->tags ?? [],
        ])->tags(['cache:write', "cache_key:{$event->key}"]);

        Monitoring::recordCache($entry);
    }

    /**
     * Registra uma deleção do cache.
     */
    public function recordCacheDelete(KeyForgotten $event): void
    {
        if ($this->shouldIgnoreKey($event->key)) {
            return;
        }

        $entry = IncomingEntry::make([
            'operation' => 'delete',
            'key' => $event->key,
            'store' => $event->storeName ?? config('cache.default'),
        ])->tags(['cache:delete', "cache_key:{$event->key}"]);

        Monitoring::recordCache($entry);
    }

    /**
     * Verifica se uma chave deve ser ignorada.
     */
    private function shouldIgnoreKey(string $key): bool
    {
        $ignoreKeys = $this->options['ignore_keys'] ?? [];

        foreach ($ignoreKeys as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
