<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Serviço de exportação de relatórios de monitoramento.
 *
 * Gera arquivos CSV (padrão) estruturados com metadados importantes.
 * O CSV é escolhido como formato principal por ser universalmente
 * compatível (Excel, Google Sheets, LibreOffice, etc.).
 */
class ExportService
{
    /** Colunas do relatório e seus labels amigáveis */
    private const COLUMNS = [
        'uuid'       => 'ID',
        'batch_id'   => 'Batch ID',
        'type'       => 'Tipo de Evento',
        'status'     => 'Status HTTP',
        'method'     => 'Método',
        'uri'        => 'URI / Descrição',
        'user_id'    => 'Usuário (ID)',
        'user_email' => 'Usuário (E-mail)',
        'tags_raw'   => 'Tags (JSON)',
        'created_at' => 'Data/Hora',
    ];

    public function __construct(
        private readonly MonitoringQueryService $queryService
    ) {}

    /**
     * Gera CSV a partir dos filtros fornecidos.
     *
     * @param  array{
     *     type?: string,
     *     user_id?: string,
     *     batch_id?: string,
     *     from?: string,
     *     to?: string,
     *     tags?: array,
     *     expand_batch?: bool
     * } $filters
     *
     * @return array{content: string, filename: string, mime: string, count: int}
     */
    public function exportCsv(array $filters = []): array
    {
        $rows    = $this->fetchRows($filters);
        $content = $this->buildCsv($rows);
        $ts      = Carbon::now()->format('Ymd_His');

        return [
            'content'  => $content,
            'filename' => "monitoring_export_{$ts}.csv",
            'mime'     => 'text/csv',
            'count'    => $rows->count(),
        ];
    }

    /**
     * Gera JSON estruturado a partir dos filtros fornecidos.
     *
     * @param  array  $filters  (mesmos parâmetros de exportCsv)
     * @return array{content: string, filename: string, mime: string, count: int}
     */
    public function exportJson(array $filters = []): array
    {
        $rows = $this->fetchRows($filters);

        $payload = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters'      => $filters,
            'total'        => $rows->count(),
            'data'         => $rows->map(fn ($r) => $this->formatRow($r))->values()->toArray(),
        ];

        $ts = Carbon::now()->format('Ymd_His');

        return [
            'content'  => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'filename' => "monitoring_export_{$ts}.json",
            'mime'     => 'application/json',
            'count'    => $rows->count(),
        ];
    }

    // ---------------------------------------------------------------
    // Helpers internos
    // ---------------------------------------------------------------

    private function fetchRows(array $filters): Collection
    {
        // Se expand_batch estiver ativo e user_id informado, usa expansão recursiva
        if (!empty($filters['expand_batch']) && !empty($filters['user_id'])) {
            return $this->queryService->getByUserId($filters['user_id']);
        }

        // Caso tags gerais com expansão
        if (!empty($filters['expand_batch']) && !empty($filters['tags'])) {
            return $this->queryService->getByTagsWithBatchExpansion($filters['tags']);
        }

        return $this->queryService->queryForExport($filters)->get();
    }

    private function buildCsv(Collection $rows): string
    {
        $output = fopen('php://temp', 'r+b');

        // Cabeçalho com BOM UTF-8 para compatibilidade com Excel
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_values(self::COLUMNS), ';');

        foreach ($rows as $row) {
            fputcsv($output, array_values($this->formatRow($row)), ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Transforma uma linha bruta do banco em array com as colunas do relatório.
     */
    private function formatRow(object $row): array
    {
        $content = $this->decodeJson($row->content ?? null);
        $tags    = $this->decodeJson($row->tags ?? null);
        $user    = $this->decodeJson($row->user ?? null);

        // Tenta extrair status e URI do content (compatível com RequestWatcher)
        $status = $content['response_status'] ?? $content['status'] ?? null;
        $uri    = $content['uri']
            ?? $content['command']
            ?? $content['job']
            ?? $content['description']
            ?? null;
        $method = $content['method'] ?? null;

        // user_id pode estar no user ou nas tags
        $userId    = $user['id'] ?? $tags['user_id'] ?? null;
        $userEmail = $user['email'] ?? null;

        return [
            'uuid'       => $row->uuid ?? '',
            'batch_id'   => $row->batch_id ?? '',
            'type'       => $this->humanizeType($row->type ?? ''),
            'status'     => $status ?? '',
            'method'     => $method ?? '',
            'uri'        => $uri ?? '',
            'user_id'    => $userId ?? '',
            'user_email' => $userEmail ?? '',
            'tags_raw'   => is_array($tags) ? json_encode($tags, JSON_UNESCAPED_UNICODE) : ($tags ?? ''),
            'created_at' => $row->created_at ?? '',
        ];
    }

    private function humanizeType(string $type): string
    {
        return match ($type) {
            'request'        => 'Request HTTP',
            'exception'      => 'Exceção',
            'job'            => 'Job / Queue',
            'command'        => 'Comando Artisan',
            'event'          => 'Evento',
            'mail'           => 'E-mail',
            'notification'   => 'Notificação',
            'gate'           => 'Gate / Autorização',
            'schedule'       => 'Tarefa Agendada',
            'client_request' => 'Requisição HTTP Saída',
            'model'          => 'Operação em Model',
            'log'            => 'Log',
            'query'          => 'Query SQL',
            default          => ucfirst($type),
        };
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}
