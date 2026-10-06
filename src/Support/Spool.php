<?php

declare(strict_types=1);

namespace RiseTechApps\Monitoring\Support;

/**
 * Rascunho em disco das entradas ainda não gravadas no banco.
 *
 * O buffer do Monitoring vive na memória até o fim do request/job. Se o
 * processo morrer antes (SIGKILL, OOM killer, timeout do PHP-FPM, deploy), a
 * memória vai junto. Por isso cada entrada também é anexada, no momento em que
 * é registrada, a um arquivo .jsonl exclusivo do processo:
 *
 *  - gravou no banco      → committed(): o arquivo é zerado;
 *  - o banco falhou       → abandon(): fsync, solta a trava, o arquivo fica;
 *  - o processo morreu    → a trava (flock) some com ele, o arquivo fica.
 *
 * O `monitoring:spool-recover` (agendado a cada minuto) grava no banco todo
 * arquivo sem dono — trava livre = processo morto ou arquivo abandonado. A
 * gravação ignora `uuid` repetido, então reprocessar não duplica.
 *
 * Invariante: o arquivo aberto contém exatamente as entradas do buffer em
 * memória (as duas coisas são esvaziadas juntas no flush).
 */
final class Spool
{
    /** @var resource|null */
    private static $handle = null;

    private static ?string $file = null;

    private static int $bytes = 0;

    public static function enabled(): bool
    {
        return (bool) config('monitoring.spool.enabled', true)
            && config('monitoring.driver') === 'database';
    }

    public static function directory(): string
    {
        $path = config('monitoring.spool.path') ?: storage_path('monitoring/spool');

        return rtrim((string) $path, '/\\');
    }

    /** Anexa uma entrada (já serializada por IncomingEntry::toArray()). */
    public static function append(array $row): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            $handle = self::handle();

            if ($handle === null) {
                return;
            }

            $maxBytes = (int) config('monitoring.spool.max_file_mb', 50) * 1024 * 1024;

            if ($maxBytes > 0 && self::$bytes >= $maxBytes) {
                // Teto de disco: segue só na memória (o banco ainda recebe no
                // flush). Só chega aqui com o flush falhando há muito tempo.
                return;
            }

            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";

            if (fwrite($handle, $line) !== false) {
                // fflush entrega ao sistema operacional: a partir daqui um
                // SIGKILL no PHP não apaga a linha.
                fflush($handle);
                self::$bytes += strlen($line);
            }
        } catch (\Throwable) {
            // O rascunho é proteção extra: falhar nele não pode derrubar o log.
        }
    }

    /** Tudo o que estava no arquivo foi gravado no banco. */
    public static function committed(): void
    {
        if (self::$handle === null || self::$bytes === 0) {
            return;
        }

        try {
            ftruncate(self::$handle, 0);
            rewind(self::$handle);
            self::$bytes = 0;
        } catch (\Throwable) {
            // Sem truncar, o arquivo seria regravado pelo recover: abandona
            // (o uuid único impede duplicar o que já entrou).
            self::abandon();
        }
    }

    /**
     * O flush falhou (ou o processo vai encerrar com o buffer cheio): garante o
     * arquivo em disco e o entrega ao recover. A próxima entrada abre outro.
     */
    public static function abandon(): void
    {
        if (self::$handle === null) {
            return;
        }

        $handle = self::$handle;
        $file = self::$file;
        $empty = self::$bytes === 0;

        self::$handle = null;
        self::$file = null;
        self::$bytes = 0;

        try {
            fflush($handle);
            if (!$empty && function_exists('fsync')) {
                fsync($handle);
            }
            flock($handle, LOCK_UN);
            fclose($handle);

            if ($empty && $file !== null) {
                @unlink($file);
            }
        } catch (\Throwable) {
        }
    }

    /** Fim do processo: remove o arquivo se vazio; se não, deixa para o recover. */
    public static function shutdown(): void
    {
        self::abandon();
    }

    /** @return resource|null */
    private static function handle()
    {
        if (self::$handle !== null) {
            return self::$handle;
        }

        $directory = self::directory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        // Nome único por processo (pid se repete em container; o sufixo não).
        $host = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) gethostname()) ?: 'host';
        $file = sprintf('%s%s%s-%d-%s.jsonl', $directory, DIRECTORY_SEPARATOR, $host, getmypid(), bin2hex(random_bytes(4)));

        // a+ : escrita sempre no fim; leitura permitida (inspeção/diagnóstico).
        $handle = @fopen($file, 'a+b');

        if ($handle === false) {
            return null;
        }

        // Trava pela vida do processo: é o que diz ao recover que o arquivo
        // tem dono. O sistema operacional a solta se o processo morrer.
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        self::$handle = $handle;
        self::$file = $file;
        self::$bytes = 0;

        return $handle;
    }
}
