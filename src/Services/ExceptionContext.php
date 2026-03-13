<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Str;
use Throwable;

/**
 * CORREÇÃO APLICADA:
 *
 * Bug de lógica: getEvalContext() retornava `[]` (array vazio) para exceções
 * normais (não-eval). O operador `??` de PHP NÃO considera array vazio como
 * "null", então getFileContext() NUNCA era chamado para exceções comuns.
 * Resultado: line_preview era sempre `[]` (vazio) exceto para exceções em eval.
 *
 * Fix: getEvalContext() agora retorna `null` quando a exceção não é de eval,
 * permitindo que o `??` acione getFileContext() corretamente.
 *
 * OTIMIZAÇÃO: getFileContext() agora lê apenas as linhas necessárias com
 * SplFileObject para evitar carregar arquivos inteiros de vendor na memória.
 */
class ExceptionContext
{
    /** Número de linhas de contexto antes e depois da linha da exceção */
    private const CONTEXT_LINES = 10;

    /** Tamanho máximo de cada linha de contexto (evita linhas minificadas enormes) */
    private const MAX_LINE_LENGTH = 200;

    public static function get(Throwable $exception): array
    {
        return static::getEvalContext($exception)
            ?? static::getFileContext($exception);
    }

    /**
     * Retorna contexto para exceções em código eval().
     * Retorna NULL (não array vazio) quando não é eval, para que ?? funcione.
     */
    protected static function getEvalContext(Throwable $exception): ?array
    {
        if (Str::contains($exception->getFile(), "eval()'d code")) {
            return [
                $exception->getLine() => "eval()'d code",
            ];
        }

        // CORRIGIDO: era `return []` — o operador ?? não detectava como "ausente".
        return null;
    }

    /**
     * Lê apenas as linhas necessárias do arquivo usando SplFileObject,
     * evitando carregar o arquivo inteiro na memória.
     */
    protected static function getFileContext(Throwable $exception): array
    {
        $file = $exception->getFile();
        $line = $exception->getLine();

        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        try {
            $spl   = new \SplFileObject($file);
            $start = max(0, $line - self::CONTEXT_LINES - 1);
            $end   = $line + self::CONTEXT_LINES;

            $result = [];
            $spl->seek($start);

            for ($i = $start; $i < $end && !$spl->eof(); $i++) {
                $content = rtrim($spl->current());
                // Trunca linhas muito longas (minificadas, geradas, etc.)
                if (strlen($content) > self::MAX_LINE_LENGTH) {
                    $content = substr($content, 0, self::MAX_LINE_LENGTH) . '…';
                }
                $result[$i + 1] = $content;
                $spl->next();
            }

            // Libera o file handle imediatamente
            $spl = null;

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }
}
