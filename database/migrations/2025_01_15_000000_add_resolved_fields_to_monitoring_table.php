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
        return config('monitoring.drivers.database.connection');
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (! $this->schema->hasTable('monitoring')) {
            return;
        }

        // Idempotente: tenants que já receberam as colunas (migração parcial anterior)
        // não podem estourar "Duplicate column". Adiciona só o que falta.
        $this->schema->table('monitoring', function (Blueprint $table) {
            if (! $this->schema->hasColumn('monitoring', 'resolved_at')) {
                // Marca quando a exceção foi resolvida
                $table->timestamp('resolved_at')->nullable()->after('device');
            }

            if (! $this->schema->hasColumn('monitoring', 'resolved_by')) {
                // Rastreia quem resolveu (user_id ou nome)
                $table->string('resolved_by')->nullable()->after('resolved_at');
            }
        });

        // Índice para consultas rápidas — só cria se ainda não existir
        if (! $this->schema->hasIndex('monitoring', ['resolved_at'])) {
            $this->schema->table('monitoring', function (Blueprint $table) {
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
        if (! $this->schema->hasTable('monitoring')) {
            return;
        }

        if ($this->schema->hasIndex('monitoring', ['resolved_at'])) {
            $this->schema->table('monitoring', function (Blueprint $table) {
                $table->dropIndex(['resolved_at']);
            });
        }

        $columns = array_values(array_filter(
            ['resolved_at', 'resolved_by'],
            fn (string $column) => $this->schema->hasColumn('monitoring', $column)
        ));

        if ($columns !== []) {
            $this->schema->table('monitoring', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
