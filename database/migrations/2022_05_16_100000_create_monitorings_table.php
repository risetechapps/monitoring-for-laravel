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
        return config('monitoring.drivers.db_connection');
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        // Verificar se a tabela já não existe
        if (!$this->schema->hasTable('monitoring')) {
            $this->schema->create('monitoring', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('uuid')->unique(); // UUID para identificação única
                $table->uuid('batch_id'); // Referência ao batch
                $table->string('type', 20);
                $table->json('content')->nullable();
                $table->json('tags')->nullable();
                $table->json('user')->nullable();
                $table->json('device')->nullable();
                $table->timestamps();

                $table->index('created_at');
                $table->index('batch_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        // Remover a tabela se existir
        $this->schema->dropIfExists('monitoring');
    }
};
