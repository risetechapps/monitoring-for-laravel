<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RiseTechApps\Monitoring\Services\ExportService;

/**
 * Comando Artisan para exportar logs de monitoramento filtrados.
 *
 * Uso:
 *   php artisan monitoring:export
 *   php artisan monitoring:export --type=request --format=csv --output=local
 *   php artisan monitoring:export --user-id=uuid-aqui --expand-batch
 *   php artisan monitoring:export --from=2025-01-01 --to=2025-01-31
 */
class MonitoringExportCommand extends Command
{
    protected $signature = 'monitoring:export
                            {--type=           : Filtro por tipo de evento (request, exception, job, etc.)}
                            {--user-id=        : Filtro por user_id nas tags}
                            {--batch-id=       : Filtro por batch_id}
                            {--from=           : Data inicial (Y-m-d)}
                            {--to=             : Data final (Y-m-d)}
                            {--format=csv      : Formato de saída — csv ou json}
                            {--output=local    : Disco do Storage para salvar o arquivo}
                            {--expand-batch    : Expande o resultado para incluir todos os logs do mesmo batch}
                            {--stdout          : Imprime o conteúdo na saída padrão em vez de gravar no Storage}';

    protected $description = 'Exporta logs de monitoramento filtrados para CSV ou JSON';

    public function __construct(private readonly ExportService $exportService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $format      = strtolower((string) $this->option('format'));
        $disk        = (string) $this->option('output');
        $expandBatch = (bool)   $this->option('expand-batch');
        $stdout      = (bool)   $this->option('stdout');

        if (!in_array($format, ['csv', 'json'], true)) {
            $this->error("Formato inválido: {$format}. Use 'csv' ou 'json'.");
            return self::FAILURE;
        }

        $filters = array_filter([
            'type'         => $this->option('type') ?: null,
            'user_id'      => $this->option('user-id') ?: null,
            'batch_id'     => $this->option('batch-id') ?: null,
            'from'         => $this->option('from') ?: null,
            'to'           => $this->option('to') ?: null,
            'expand_batch' => $expandBatch ?: null,
        ]);

        $this->info('Gerando exportação...');

        $result = match ($format) {
            'json'  => $this->exportService->exportJson($filters),
            default => $this->exportService->exportCsv($filters),
        };

        if ($result['count'] === 0) {
            $this->warn('Nenhum registro encontrado com os filtros informados.');
            return self::SUCCESS;
        }

        if ($stdout) {
            $this->line($result['content']);
            return self::SUCCESS;
        }

        $path    = 'monitoring/exports/' . $result['filename'];
        $written = Storage::disk($disk)->put($path, $result['content']);

        if (!$written) {
            $this->error("Falha ao gravar o arquivo em [{$disk}]: {$path}");
            return self::FAILURE;
        }

        $this->info("✔  Exportação concluída.");
        $this->table(
            ['Detalhe', 'Valor'],
            [
                ['Registros exportados', number_format($result['count'])],
                ['Formato',              strtoupper($format)],
                ['Disco',                $disk],
                ['Arquivo',              $path],
            ]
        );

        return self::SUCCESS;
    }
}
