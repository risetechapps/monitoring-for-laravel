<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Str;
use Throwable;

class ExceptionContext
{
    /**
     * Obtém o contexto de uma exceção.
     *
     * Este método tenta obter o contexto da exceção a partir do código
     * onde a exceção ocorreu. Ele primeiro verifica se a exceção é gerada
     * a partir de código avaliado e, se não, obtém o contexto do arquivo
     * onde a exceção ocorreu.
     *
     * @param Throwable $exception A exceção da qual obter o contexto.
     * @return array O contexto da exceção.
     */
    public static function get(Throwable $exception): array
    {
        return static::getEvalContext($exception) ??
            static::getFileContext($exception);
    }

    /**
     * Obtém o contexto de uma exceção gerada a partir de código avaliado.
     *
     * Este método verifica se a exceção ocorreu em código avaliado (usando `eval`).
     * Se for o caso, retorna um contexto indicando que a exceção ocorreu em
     * "eval()'d code" e a linha onde a exceção ocorreu.
     *
     * @param Throwable $exception A exceção da qual obter o contexto.
     * @return array|null O contexto da exceção se for de código avaliado, null caso contrário.
     */
    protected static function getEvalContext(Throwable $exception): ?array
    {
        // Verifica se o arquivo da exceção contém a string "eval()'d code"
        if (Str::contains($exception->getFile(), "eval()'d code")) {
            return [
                $exception->getLine() => "eval()'d code",
            ];
        }

        return [];
    }

    /**
     * Obtém o contexto do arquivo onde a exceção ocorreu.
     *
     * Este método retorna uma fatia do código-fonte do arquivo onde a exceção
     * ocorreu, centrado ao redor da linha onde a exceção ocorreu.
     *
     * @param Throwable $exception A exceção da qual obter o contexto.
     * @return array O contexto do arquivo.
     */
    protected static function getFileContext(Throwable $exception): array
    {
        // Lê o conteúdo do arquivo onde a exceção ocorreu e obtém uma fatia
        // das linhas ao redor da linha onde a exceção ocorreu
        return collect(explode("\n", file_get_contents($exception->getFile())))
            ->slice($exception->getLine() - 10, 20) // Obtém 10 linhas antes e 10 depois da linha da exceção
            ->mapWithKeys(function ($value, $key) {
                return [$key + 1 => $value]; // Mapeia as linhas para o formato linha => conteúdo
            })->all();
    }
}
