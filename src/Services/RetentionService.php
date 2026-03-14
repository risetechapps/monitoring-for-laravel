<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Serviço de retenção de logs de monitoramento.
 *
 * Responsável por:
 * - Exportar logs antigos em JSON/CSV para o storage
 * - Remover do banco apenas após confirmação do backup
 * - Retornar estatísticas de execução
 */
class RetentionService
{
    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly MonitoringQueryService $queryService
    ) {}

    /**
     * Executa o ciclo completo de retenção:
     * 1. Exporta em lotes para o storage
     * 2. Remove do banco após confirmação
     *
     * @param  int    $retentionDays  Padrão: 90
     * @param  string $format         'json' ou 'csv'
     * @param  string $disk           disco do Storage (config)
     * @param  int    $chunkSize      Registros por lote (evita estouro de memória)
     *
     * @return array{exported: int, deleted: int, files: list<string>, errors: list<string>}
     */
    public function run(
        int $retentionDays = 90,
        string $format = 'json',
        string $disk = 'local',
        int $chunkSize = 500
    ): array {
        $stats = [
            'exported' => 0,
            'deleted'  => 0,
            'files'    => [],
            'errors'   => [],
        ];

        $cutoff     = Carbon::now()->subDays($retentionDays);
        $dateLabel  = $cutoff->format(self::DATE_FORMAT);
        $runLabel   = Carbon::now()->format('Ymd_His');
        $batchIndex = 0;

        $this->queryService->chunkForRetention(
            $retentionDays,
            $chunkSize,
            function (Collection $rows) use (
                &$stats, $format, $disk, $dateLabel, $runLabel, &$batchIndex
            ) {
                $batchIndex++;
                $ids = $rows->pluck('id')->toArray();

                // 1. Tenta exportar o lote
                $filename = "monitoring/retention/{$dateLabel}/{$runLabel}_batch{$batchIndex}.{$format}";

                try {
                    $content = $format === 'csv'
                        ? $this->toCsv($rows)
                        : $this->toJson($rows);

                    $written = Storage::disk($disk)->put($filename, $content);

                    if (!$written) {
                        $stats['errors'][] = "Falha ao gravar arquivo: {$filename}";
                        return; // Não remove se não gravou
                    }

                    $stats['files'][]   = $filename;
                    $stats['exported'] += count($ids);

                } catch (\Throwable $e) {
                    $stats['errors'][] = "Erro no lote {$batchIndex}: {$e->getMessage()}";
                    Log::error('[Monitoring Retention] Erro ao exportar lote', [
                        'batch'     => $batchIndex,
                        'error'     => $e->getMessage(),
                        'filename'  => $filename,
                    ]);
                    return; // Não remove se houve erro
                }

                // 2. Remove do banco somente após backup confirmado
                try {
                    $deleted = $this->queryService->deleteByIds($ids);
                    $stats['deleted'] += $deleted;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Erro ao remover lote {$batchIndex} do banco: {$e->getMessage()}";
                    Log::error('[Monitoring Retention] Erro ao remover lote do banco', [
                        'batch' => $batchIndex,
                        'ids'   => $ids,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        );

        return $stats;
    }

    // ---------------------------------------------------------------
    // Formatação de saída
    // ---------------------------------------------------------------

    private function toJson(Collection $rows): string
    {
        $data = $rows->map(fn ($r) => (array) $r)->toArray();
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function toCsv(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '';
        }

        $output  = fopen('php://temp', 'r+b');
        $headers = array_keys((array) $rows->first());
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            $values = array_map(
                fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v,
                (array) $row
            );
            fputcsv($output, $values);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
