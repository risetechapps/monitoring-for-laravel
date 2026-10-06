<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove dois índices de tags que só custavam escrita (PostgreSQL).
 *
 * A tabela `monitoring` só recebe INSERT e cada índice é atualizado em todos:
 *
 *  - `monitoring_tags_user_id_idx` ((tags->>'user_id')): a busca usava
 *    `tags->>? = ?` com a chave como parâmetro, que não casa com índice de
 *    expressão. Agora a busca por tags usa contenção jsonb
 *    (`tags::jsonb @> ...`), atendida pelo `monitoring_tags_gin_idx`, que fica.
 *  - `monitoring_tags_trgm_idx` (GIN trigram de tags::text): só servia ao
 *    ILIKE em tags do /search, que roda numa janela de dias já recortada pelo
 *    índice de created_at. O trigram de content (o que importa na busca) fica.
 */
return new class extends Migration
{
    // DROP INDEX CONCURRENTLY não roda dentro de transação.
    public $withinTransaction = false;

    public function getConnection(): ?string
    {
        return config('monitoring.drivers.database.connection');
    }

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('DROP INDEX CONCURRENTLY IF EXISTS monitoring_tags_user_id_idx');
        $connection->statement('DROP INDEX CONCURRENTLY IF EXISTS monitoring_tags_trgm_idx');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS monitoring_tags_user_id_idx ON monitoring ((tags->>'user_id'))"
        );

        try {
            $connection->statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS monitoring_tags_trgm_idx ON monitoring USING GIN ((tags::text) gin_trgm_ops)'
            );
        } catch (\Throwable) {
            // pg_trgm ausente: a migration original também o pulava.
        }
    }
};
