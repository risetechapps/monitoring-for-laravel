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
        if ($this->schema->hasTable('monitoring')) {
            $this->schema->table('monitoring', function (Blueprint $table) {
                // Adiciona campo para marcar quando a exceção foi resolvida
                $table->timestamp('resolved_at')->nullable()->after('device');
                // Adiciona campo para rastrear quem resolveu (user_id ou nome)
                $table->string('resolved_by')->nullable()->after('resolved_at');
                // Adiciona índice para consultas rápidas
                $table->index('resolved_at');
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
        if ($this->schema->hasTable('monitoring')) {
            $this->schema->table('monitoring', function (Blueprint $table) {
                $table->dropIndex(['resolved_at']);
                $table->dropColumn(['resolved_at', 'resolved_by']);
            });
        }
    }
};
