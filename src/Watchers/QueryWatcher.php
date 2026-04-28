<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Entry\IncomingEntry;
use RiseTechApps\Monitoring\Monitoring;

class QueryWatcher extends Watcher
{
    /**
     * Registra o ouvinte para eventos de query executada.
     */
    public function register($app): void
    {
        $app['events']->listen(QueryExecuted::class, [$this, 'recordQuery']);
    }

    /**
     * Registra uma query executada no banco de dados.
     */
    public function recordQuery(QueryExecuted $event): void
    {
        try {
            if (!Monitoring::isEnabled()) {
                return;
            }

            $executionTime = $event->time; // em milissegundos

            // Verifica se a query está acima do threshold de lentidão
            $threshold = $this->options['slow_query_threshold_ms'] ?? 100;
            if ($executionTime < $threshold) {
                return;
            }

            // Verifica se deve ignorar este padrão de query
            if ($this->shouldIgnore($event)) {
                return;
            }

            $sql = $this->formatSql($event);
            $bindings = $this->options['log_bindings'] ?? true
                ? $this->formatBindings($event->bindings)
                : null;

            // Extrair informação de onde a query foi chamada
            $caller = $this->getCallerFromStackTrace();

            $entry = IncomingEntry::make([
                'connection' => $event->connectionName,
                'sql' => $sql,
                'bindings' => $bindings,
                'time_ms' => $executionTime,
                'slow' => true,
                'threshold_ms' => $threshold,
                'caller_file' => $caller['file'] ?? null,
                'caller_line' => $caller['line'] ?? null,
            ])->tags([
                'query:slow',
                "connection:{$event->connectionName}",
            ]);

            Monitoring::recordQuery($entry);
        } catch (\Exception $exception) {
            loggly()->to('file')
                ->performedOn(self::class)
                ->exception($exception)
                ->level('error')
                ->log('Erro ao registrar query no monitoring');
        }
    }

    /**
     * Verifica se a query deve ser ignorada baseado nos padrões configurados.
     */
    private function shouldIgnore(QueryExecuted $event): bool
    {
        $ignorePatterns = $this->options['ignore_patterns'] ?? [];
        $sql = strtolower($event->sql);

        foreach ($ignorePatterns as $pattern) {
            if (str_contains($sql, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Formata a query SQL para armazenamento.
     */
    private function formatSql(QueryExecuted $event): string
    {
        $sql = $event->sql;

        // Trunca queries muito longas
        $maxLength = $this->options['max_sql_length'] ?? 5000;
        if (strlen($sql) > $maxLength) {
            $sql = substr($sql, 0, $maxLength) . '... [truncated]';
        }

        return $sql;
    }

    /**
     * Formata os bindings para armazenamento seguro.
     */
    private function formatBindings(array $bindings): array
    {
        // Limita tamanho dos bindings
        $formatted = [];
        foreach ($bindings as $key => $value) {
            $strValue = is_string($value) ? $value : json_encode($value);
            if (strlen($strValue) > 1000) {
                $strValue = substr($strValue, 0, 1000) . '...[truncated]';
            }
            $formatted[$key] = $strValue;
        }

        return $formatted;
    }

    /**
     * Extrai informação de onde a query foi chamada no código da aplicação.
     */
    private function getCallerFromStackTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            // Ignora frames do próprio framework e do pacote
            $file = $frame['file'];
            if (str_contains($file, 'vendor') || str_contains($file, 'src/Watchers')) {
                continue;
            }

            return [
                'file' => $file,
                'line' => $frame['line'] ?? 0,
            ];
        }

        return ['file' => null, 'line' => null];
    }
}
