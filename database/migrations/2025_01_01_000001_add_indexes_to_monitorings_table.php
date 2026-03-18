<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected Builder $schema;

    public function __construct()
    {
        $this->schema = Schema::connection($this->getConnection());
    }

    public function getConnection(): ?string
    {
        return config('monitoring.drivers.database.connection');
    }

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $driver = $connection->getDriverName();
        $schema = Schema::connection($this->getConnection());

        if ($driver === 'pgsql') {
            // 🔥 GIN index com cast para jsonb (resolve seu erro)
            $connection->statement(
                'CREATE INDEX IF NOT EXISTS monitoring_tags_gin_idx
                 ON monitoring USING GIN ((tags::jsonb))'
            );

            // Índice para busca por user_id dentro do JSON
            $connection->statement(
                "CREATE INDEX IF NOT EXISTS monitoring_tags_user_id_idx
                 ON monitoring ((tags->>'user_id'))"
            );

        } elseif (in_array($driver, ['mysql', 'mariadb'])) {
            // Coluna virtual para indexar user_id dentro do JSON
            $connection->statement(
                "ALTER TABLE monitoring
                 ADD COLUMN IF NOT EXISTS tags_user_id VARCHAR(36)
                 GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(tags, '$.user_id'))) VIRTUAL"
            );

            $connection->statement(
                'CREATE INDEX IF NOT EXISTS monitoring_tags_user_id_idx
                 ON monitoring (tags_user_id)'
            );
        }

        // Índice composto (type + created_at)
        $schema->table('monitoring', function ( $table) use ($schema) {
            // O Laravel agora tem métodos nativos para checar índices sem Doctrine
            $hasIndex = collect($schema->getIndexes('monitoring'))
                ->pluck('name')
                ->contains('monitoring_type_created_at_idx');

            if (!$hasIndex) {
                $table->index(['type', 'created_at'], 'monitoring_type_created_at_idx');
            }
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $connection->statement('DROP INDEX IF EXISTS monitoring_tags_gin_idx');
            $connection->statement('DROP INDEX IF EXISTS monitoring_tags_user_id_idx');

        } elseif (in_array($driver, ['mysql', 'mariadb'])) {
            $connection->statement(
                'DROP INDEX monitoring_tags_user_id_idx ON monitoring'
            );

            $connection->statement(
                'ALTER TABLE monitoring DROP COLUMN IF EXISTS tags_user_id'
            );
        }

        $this->schema->table('monitoring', function ( $table) {
            $table->dropIndex('monitoring_type_created_at_idx');
        });
    }
};
