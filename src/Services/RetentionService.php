<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RiseTechApps\Monitoring\Entry\EntryType;

/**
 * Serviço de retenção de logs de monitoramento.
 *
 * Responsável por:
 * - Exportar logs antigos em JSON/CSV para o storage
 * - Remover do banco apenas após confirmação do backup
 * - Retornar estatísticas de execução
 * - Suporte a políticas granulares por tipo
 */
class RetentionService
{
    private const DATE_FORMAT = 'Y-m-d';

    /** Mapeamento de tipos para configuração granular */
    private const TYPE_MAPPING = [
        EntryType::EXCEPTION => 'exceptions',
        EntryType::REQUEST   => 'requests',
        EntryType::JOB       => 'jobs',
        EntryType::QUERY     => 'queries',
        EntryType::CACHE     => 'cache',
        EntryType::METRIC    => 'metrics',
    ];

    public function __construct(
        private readonly MonitoringQueryService $queryService
    ) {}

    /**
     * Executa o ciclo completo de retenção com políticas granulares.
     *
     * @param  int    $retentionDays  Padrão global (fallback)
     * @param  string $format         'json' ou 'csv'
     * @param  string $disk           disco do Storage (config)
     * @param  int    $chunkSize      Registros por lote
     * @param  bool   $keepUnresolved Manter exceções não resolvidas
     *
     * @return array{exported: int, deleted: int, files: list<string>, errors: list<string>, by_type: array}
     */
    public function run(
        int $retentionDays = 90,
        string $format = 'json',
        string $disk = 'local',
        int $chunkSize = 500,
        bool $keepUnresolved = true
    ): array {
        $stats = [
            'exported'   => 0,
            'deleted'    => 0,
            'files'      => [],
            'errors'     => [],
            'by_type'    => [],
        ];

        // Obtém políticas granulares
        $granularConfig = config('monitoring.retention.granular', []);

        // Processa cada tipo separadamente
        foreach (self::TYPE_MAPPING as $entryType => $configKey) {
            $days = $granularConfig[$configKey] ?? $retentionDays;

            $typeStats = $this->processType(
                $entryType,
                $days,
                $format,
                $disk,
                $chunkSize,
                $keepUnresolved
            );

            $stats['exported'] += $typeStats['exported'];
            $stats['deleted'] += $typeStats['deleted'];
            $stats['files'] = array_merge($stats['files'], $typeStats['files']);
            $stats['errors'] = array_merge($stats['errors'], $typeStats['errors']);
            $stats['by_type'][$entryType] = $typeStats;
        }

        return $stats;
    }

    /**
     * Processa um tipo específico de evento.
     */
    private function processType(
        string $entryType,
        int $days,
        string $format,
        string $disk,
        int $chunkSize,
        bool $keepUnresolved
    ): array {
        $stats = [
            'exported' => 0,
            'deleted'  => 0,
            'files'    => [],
            'errors'   => [],
        ];

        $cutoff     = Carbon::now()->subDays($days);
        $dateLabel  = $cutoff->format(self::DATE_FORMAT);
        $runLabel   = Carbon::now()->format('Ymd_His');
        $batchIndex = 0;

        $this->queryService->chunkForRetentionByType(
            $entryType,
            $days,
            $chunkSize,
            $keepUnresolved,
            function (Collection $rows) use (
                &$stats, $format, $disk, $dateLabel, $runLabel, &$batchIndex, $entryType
            ) {
                $batchIndex++;
                $ids = $rows->pluck('id')->toArray();

                // 1. Tenta exportar o lote
                $filename = "monitoring/retention/{$entryType}/{$dateLabel}/{$runLabel}_batch{$batchIndex}.{$format}";

                try {
                    $content = $format === 'csv'
                        ? $this->toCsv($rows)
                        : $this->toJson($rows);

                    $written = Storage::disk($disk)->put($filename, $content);

                    if (!$written) {
                        $stats['errors'][] = "Falha ao gravar arquivo: {$filename}";
                        return;
                    }

                    $stats['files'][]   = $filename;
                    $stats['exported'] += count($ids);

                } catch (\Throwable $e) {
                    $stats['errors'][] = "Erro no lote {$batchIndex}: {$e->getMessage()}";
                    Log::error('[Monitoring Retention] Erro ao exportar lote', [
                        'batch'     => $batchIndex,
                        'type'      => $entryType,
                        'error'     => $e->getMessage(),
                        'filename'  => $filename,
                    ]);
                    return;
                }

                // 2. Remove do banco somente após backup confirmado
                try {
                    $deleted = $this->queryService->deleteByIds($ids);
                    $stats['deleted'] += $deleted;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Erro ao remover lote {$batchIndex} do banco: {$e->getMessage()}";
                    Log::error('[Monitoring Retention] Erro ao remover lote do banco', [
                        'batch' => $batchIndex,
                        'type'  => $entryType,
                        'ids'   => $ids,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        );

        return $stats;
    }

    /**
     * Executa retenção padrão (compatibilidade com versão anterior).
     *
     * @deprecated Use run() com configuração granular
     */
    public function runLegacy(
        int $retentionDays = 90,
        string $format = 'json',
        string $disk = 'local',
        int $chunkSize = 500
    ): array {
        return $this->run($retentionDays, $format, $disk, $chunkSize, true);
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
