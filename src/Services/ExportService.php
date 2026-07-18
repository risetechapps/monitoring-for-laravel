<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Carbon;

/**
 * Serviço de exportação de relatórios de monitoramento.
 *
 * Gera arquivos CSV (padrão) ou JSON de forma STREAMING: as linhas são lidas do
 * banco por cursor e escritas direto no destino, sem materializar o resultado
 * inteiro na memória. Um export sem filtros percorre a tabela toda — que cresce
 * sem limite — então bufferizar tudo derrubaria o processo.
 *
 * - streamCsv/streamJson escrevem num handle de stream e retornam a contagem.
 * - exportCsv/exportJson mantêm a assinatura antiga (retornam o conteúdo), mas
 *   agora bufferizam via php://temp com teto de memória, caindo para disco
 *   quando o conteúdo cresce. Preferir os métodos stream para grandes volumes.
 */
class ExportService
{
    /** Colunas do relatório e seus labels amigáveis */
    private const array COLUMNS = [
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

    /** Flags de json_encode reutilizadas em todo o serviço */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Memória usada pelo buffer php://temp dos wrappers antes de cair para disco.
     * 8 MB cobre exports pequenos sem tocar o disco e limita o pico dos grandes.
     */
    private const TEMP_MEMORY_LIMIT = 8 * 1024 * 1024;

    public function __construct(
        private readonly MonitoringQueryService $queryService
    ) {}

    // ---------------------------------------------------------------
    // API streaming (preferencial)
    // ---------------------------------------------------------------

    /**
     * Escreve o CSV no handle de stream. Retorna o número de linhas de dados.
     *
     * @param  resource  $handle
     */
    public function streamCsv(array $filters, $handle): int
    {
        // BOM UTF-8 para o Excel reconhecer acentuação.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values(self::COLUMNS), ';', escape: '\\');

        $count = 0;
        foreach ($this->lazyRows($filters) as $row) {
            fputcsv($handle, array_values($this->formatRow($row)), ';', escape: '\\');
            $count++;
        }

        return $count;
    }

    /**
     * Escreve o JSON no handle de stream. Retorna o número de registros.
     *
     * O documento é montado incrementalmente ({...,"data":[ ... ]}), então
     * `total` fica no fim — é o único ponto em que a contagem é conhecida sem
     * um segundo passo pelo banco.
     *
     * @param  resource  $handle
     */
    public function streamJson(array $filters, $handle): int
    {
        fwrite($handle, '{"generated_at":' . json_encode(Carbon::now()->toIso8601String(), self::JSON_FLAGS));
        fwrite($handle, ',"filters":' . json_encode((object) $filters, self::JSON_FLAGS));
        fwrite($handle, ',"data":[');

        $count = 0;
        foreach ($this->lazyRows($filters) as $row) {
            fwrite($handle, ($count > 0 ? ',' : '') . json_encode($this->formatRow($row), self::JSON_FLAGS));
            $count++;
        }

        fwrite($handle, '],"total":' . $count . '}');

        return $count;
    }

    /**
     * Escreve o formato pedido no handle. Retorna a contagem.
     *
     * @param  resource  $handle
     */
    public function streamTo(string $format, array $filters, $handle): int
    {
        return $format === 'json'
            ? $this->streamJson($filters, $handle)
            : $this->streamCsv($filters, $handle);
    }

    /**
     * Indica se há ao menos um registro para os filtros, sem materializar linhas.
     * Usado para decidir 404 antes de começar a escrever a resposta streaming.
     */
    public function hasResults(array $filters): bool
    {
        // Caminho comum: um SELECT 1 ... LIMIT 1, sem trazer nenhuma linha.
        if (empty($filters['expand_batch'])) {
            return $this->queryService->queryForExport($filters)->exists();
        }

        // Expansão de batch precisa do conjunto de batch_ids; para no 1º registro.
        foreach ($this->lazyRows($filters) as $_) {
            return true;
        }

        return false;
    }

    public function filenameFor(string $format): string
    {
        $ts  = Carbon::now()->format('Ymd_His');
        $ext = $format === 'json' ? 'json' : 'csv';

        return "monitoring_export_{$ts}.{$ext}";
    }

    public function mimeFor(string $format): string
    {
        return $format === 'json' ? 'application/json' : 'text/csv';
    }

    // ---------------------------------------------------------------
    // API antiga (mantida por compatibilidade)
    // ---------------------------------------------------------------

    /**
     * @return array{content: string, filename: string, mime: string, count: int}
     */
    public function exportCsv(array $filters = []): array
    {
        return $this->bufferedExport('csv', $filters);
    }

    /**
     * @return array{content: string, filename: string, mime: string, count: int}
     */
    public function exportJson(array $filters = []): array
    {
        return $this->bufferedExport('json', $filters);
    }

    // ---------------------------------------------------------------
    // Helpers internos
    // ---------------------------------------------------------------

    /**
     * Escreve o export num buffer php://temp (cai para disco após TEMP_MEMORY_LIMIT)
     * e devolve o conteúdo. Existe para os chamadores da assinatura antiga; para
     * grandes volumes, prefira streamTo() direto no destino.
     *
     * @return array{content: string, filename: string, mime: string, count: int}
     */
    private function bufferedExport(string $format, array $filters): array
    {
        $handle = fopen('php://temp/maxmemory:' . self::TEMP_MEMORY_LIMIT, 'r+b');

        try {
            $count = $this->streamTo($format, $filters, $handle);
            rewind($handle);
            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return [
            'content'  => $content,
            'filename' => $this->filenameFor($format),
            'mime'     => $this->mimeFor($format),
            'count'    => $count,
        ];
    }

    /**
     * Iterador preguiçoso das linhas do export.
     *
     * Sem expansão de batch, usa cursor() — uma linha por vez, memória constante
     * independente do tamanho do resultado. Com expansão, recai nas consultas
     * limitadas do repositório (o conjunto de batch_ids precisa ser resolvido
     * antes, então não há como transmitir em fluxo puro).
     */
    private function lazyRows(array $filters): iterable
    {
        if (!empty($filters['expand_batch']) && !empty($filters['user_id'])) {
            return $this->queryService->getByUserId($filters['user_id']);
        }

        if (!empty($filters['expand_batch']) && !empty($filters['tags'])) {
            return $this->queryService->getByTagsWithBatchExpansion($filters['tags']);
        }

        return $this->queryService->queryForExport($filters)->cursor();
    }

    /**
     * Transforma uma linha bruta do banco em array com as colunas do relatório.
     */
    private function formatRow(object $row): array
    {
        $content = $this->decodeJson($row->content ?? null);
        $tags    = $this->decodeJson($row->tags ?? null);
        $user    = $this->decodeJson($row->user ?? null);

        $content = is_array($content) ? $content : [];
        $tags    = is_array($tags) ? $tags : [];
        $user    = is_array($user) ? $user : [];

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
            'tags_raw'   => !empty($tags) ? json_encode($tags, JSON_UNESCAPED_UNICODE) : '',
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
            'cache'          => 'Cache',
            'metric'         => 'Métrica',
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
