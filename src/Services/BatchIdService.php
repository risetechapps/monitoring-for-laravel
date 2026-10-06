<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Str;

/**
 * Batch corrente: agrupa as entradas de um mesmo request, job ou comando.
 */
class BatchIdService
{
    /**
     * Armazena o ID do lote atual.
     *
     * @var string|null
     */
    protected ?string $batchId;

    /** Quando o batch automático nasceu (microtime) — ver getBatchId(). */
    protected ?float $startedAt = null;

    /** true = definido por quem conhece a unidade de trabalho (job, request). */
    protected bool $explicit = false;

    /**
     * Construtor da classe.
     *
     * Inicializa a propriedade $batchId como null.
     */
    public function __construct()
    {
        $this->batchId = null;
    }

    /**
     * Define o ID do lote.
     *
     * Este método atribui um ID de lote à propriedade $batchId
     * somente se a propriedade estiver atualmente como null.
     *
     * @param string $batchId O ID do lote a ser definido.
     * @return void
     */
    public function setBatchId(string $batchId): void
    {
        // Define o ID do lote se ainda não estiver definido
        if (is_null($this->batchId)) {
            $this->batchId   = $batchId;
            $this->startedAt = microtime(true);
            $this->explicit  = true;
        }
    }

    /**
     * Obtém o ID do lote.
     *
     * Se não estiver definido, gera um novo. Um batch gerado aqui (não definido
     * por job/request) expira após `monitoring.batch_max_age_seconds`: num
     * processo de console de longa duração (ex.: listener de eventos) não há
     * fim de request para encerrá-lo, e todas as entradas do processo — por
     * dias — acabavam num batch só.
     *
     * @return string O ID do lote.
     */
    public function getBatchId(): ?string
    {
        if (!is_null($this->batchId) && !$this->explicit && $this->expired()) {
            $this->forceDelete();
        }

        // Se o ID do lote não estiver definido, gera um novo UUID
        if (is_null($this->batchId)) {
            $this->batchId   = (string) Str::orderedUuid();
            $this->startedAt = microtime(true);
            $this->explicit  = false;
        }

        return $this->batchId;
    }

    /**
     * Força a exclusão do ID do lote.
     *
     * Este método redefine a propriedade $batchId como null,
     * efetivamente removendo o ID do lote atual.
     *
     * @return void
     */
    public function forceDelete(): void
    {
        $this->batchId   = null;
        $this->startedAt = null;
        $this->explicit  = false;
    }

    protected function expired(): bool
    {
        $maxAge = (float) (function_exists('config') ? config('monitoring.batch_max_age_seconds', 300) : 300);

        return $maxAge > 0 && $this->startedAt !== null && (microtime(true) - $this->startedAt) >= $maxAge;
    }
}
