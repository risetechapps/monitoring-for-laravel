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
        // Índice funcional na coluna JSON tags para filtro por user_id
        // Suporte a PostgreSQL e MySQL/MariaDB
        $driver = DB::connection($this->getConnection())->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: índice GIN para consultas JSON eficientes
            DB::connection($this->getConnection())->statement(
                'CREATE INDEX IF NOT EXISTS monitoring_tags_gin_idx ON monitoring USING GIN (tags)'
            );

            // Índice de expressão para user_id dentro do JSON
            DB::connection($this->getConnection())->statement(
                "CREATE INDEX IF NOT EXISTS monitoring_tags_user_id_idx
                 ON monitoring ((tags->>'user_id'))"
            );
        } elseif (in_array($driver, ['mysql', 'mariadb'])) {
            // MySQL 5.7+ / MariaDB: índice gerado virtual para user_id no JSON
            DB::connection($this->getConnection())->statement(
                "ALTER TABLE monitoring
                 ADD COLUMN IF NOT EXISTS tags_user_id VARCHAR(36)
                 GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(tags, '$.user_id'))) VIRTUAL"
            );
            DB::connection($this->getConnection())->statement(
                'CREATE INDEX IF NOT EXISTS monitoring_tags_user_id_idx ON monitoring (tags_user_id)'
            );
        }

        // Índice composto para retenção: type + created_at
        $this->schema->table('monitoring', function (Blueprint $table) {
            // Evita erro se já existir
            $sm = Schema::connection($this->getConnection())->getConnection()->getDoctrineSchemaManager();
            $indexes = array_keys($sm->listTableIndexes('monitoring'));

            if (!in_array('monitoring_type_created_at_idx', $indexes)) {
                $table->index(['type', 'created_at'], 'monitoring_type_created_at_idx');
            }
        });
    }

    public function down(): void
    {
        $driver = DB::connection($this->getConnection())->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection($this->getConnection())
                ->statement('DROP INDEX IF EXISTS monitoring_tags_gin_idx');
            DB::connection($this->getConnection())
                ->statement('DROP INDEX IF EXISTS monitoring_tags_user_id_idx');
        } elseif (in_array($driver, ['mysql', 'mariadb'])) {
            DB::connection($this->getConnection())
                ->statement('DROP INDEX IF EXISTS monitoring_tags_user_id_idx ON monitoring');
            DB::connection($this->getConnection())
                ->statement('ALTER TABLE monitoring DROP COLUMN IF EXISTS tags_user_id');
        }

        $this->schema->table('monitoring', function (Blueprint $table) {
            $table->dropIndex('monitoring_type_created_at_idx');
        });
    }
};
