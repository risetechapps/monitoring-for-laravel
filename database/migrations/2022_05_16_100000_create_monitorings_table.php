<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database schema.
     *
     * @var Builder
     */
    protected Builder $schema;

    /**
     * Create a new migration instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->schema = Schema::connection($this->getConnection());
    }

    /**
     * Get the migration connection name.
     *
     * @return string|null
     */
    public function getConnection(): ?string
    {
        return env('DB_CONNECTION', 'mysql');
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Verificar se a tabela já não existe
        if (!$this->schema->hasTable('monitorings')) {
            $this->schema->create('monitorings', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique(); // UUID para identificação única
                $table->uuid('batch_id'); // Referência ao batch
                $table->string('type', 20); // Tipo de monitoramento
                $table->json('content')->nullable(); // Conteúdo do monitoramento
                $table->json('tags')->nullable(); // Tags associadas ao monitoramento
                $table->json('device')->nullable(); // Device - Informações do dispositivo
                $table->timestamps(); // timestamps (created_at e updated_at)

                // Índices para otimizar consultas
                $table->index('created_at'); // Índice em created_at para consultas baseadas em tempo
                $table->index('batch_id'); // Índice em batch_id para otimizar consultas por batch_id
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remover a tabela se existir
        $this->schema->dropIfExists('monitorings');
    }
};
