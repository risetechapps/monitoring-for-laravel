<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Monitoring\Support\Redactor;

/**
 * Aplica a ocultação (Support\Redactor) aos registros JÁ gravados.
 *
 * Antes da 4.1 o monitoring gravava senha digitada (Loggly::withRequest),
 * access_token da resposta do login, old_password/recovery_code do payload,
 * segredos de OAuth do HTTP de saída. Este comando reescreve o `content` dessas
 * linhas com os valores ocultados. É irreversível — rode primeiro com --dry-run.
 *
 * Uso:
 *   php artisan monitoring:redact --dry-run
 *   php artisan monitoring:redact --force
 *   php artisan monitoring:redact --days=30 --force
 */
class MonitoringRedactCommand extends Command
{
    protected $signature = 'monitoring:redact
                            {--days=     : Só registros dos últimos N dias (padrão: todos)}
                            {--chunk=500 : Registros lidos por lote}
                            {--dry-run   : Só conta o que seria alterado}
                            {--force     : Executa sem pedir confirmação}';

    protected $description = 'Oculta senhas, tokens e segredos nos registros já gravados do monitoring (irreversível)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$this->option('force') && !$this->confirm('Reescrever o content dos registros com dado sensível? Não há como desfazer.')) {
            return self::SUCCESS;
        }

        $connection = DB::connection(config('monitoring.drivers.database.connection'));
        $chunk      = max(1, (int) $this->option('chunk'));
        $days       = $this->option('days');

        $query = $connection->table('monitoring')->select(['id', 'content']);

        if ($days !== null && $days !== '') {
            $query->where('created_at', '>=', Carbon::now()->subDays((int) $days)->toDateTimeString());
        }

        $scanned = 0;
        $changed = 0;

        $query->chunkById($chunk, function ($rows) use ($connection, $dryRun, &$scanned, &$changed) {
            foreach ($rows as $row) {
                $scanned++;

                $original = is_string($row->content) ? $row->content : json_encode($row->content);
                $decoded  = json_decode((string) $original, true);

                if (!is_array($decoded)) {
                    continue;
                }

                $redacted = Redactor::redact($decoded);

                if ($redacted === $decoded) {
                    continue;
                }

                $changed++;

                if (!$dryRun) {
                    $connection->table('monitoring')->where('id', $row->id)->update([
                        'content' => json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    ]);
                }
            }
        }, 'id');

        $this->info(sprintf(
            '%d registro(s) lido(s), %d %s.',
            $scanned,
            $changed,
            $dryRun ? 'seriam alterados (dry-run)' : 'alterado(s)'
        ));

        return self::SUCCESS;
    }
}
