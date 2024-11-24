<?php

namespace RiseTechApps\Monitoring\Services;

use Illuminate\Support\Str;

class BatchIdService
{
    /**
     * Armazena o ID do lote atual.
     *
     * @var string|null
     */
    protected ?string $batchId;

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
            $this->batchId = $batchId;
        }
    }

    /**
     * Obtém o ID do lote.
     *
     * Este método retorna o ID do lote se estiver definido.
     * Caso contrário, gera um novo UUID para o lote e o define.
     *
     * @return string O ID do lote.
     */
    public function getBatchId(): ?string
    {
        // Se o ID do lote não estiver definido, gera um novo UUID
        if (is_null($this->batchId)) {
            $this->batchId = (string) Str::orderedUuid();
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
        $this->batchId = null;
    }
}
