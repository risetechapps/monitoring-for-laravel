<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

/**
 * Índice de busca por substring (LIKE '%termo%') na tabela monitoring.
 *
 * PostgreSQL: usa a extensão pg_trgm, que torna ILIKE '%...%' indexável por
 * um índice GIN gin_trgm_ops — sem ela, a busca é sempre sequential scan.
 * A extensão é criada via tpetry (Schema::createExtensionIfNotExists), tolerante
 * a falta de privilégio: quando não dá, a busca continua funcionando (mais
 * lenta), já limitada pela janela temporal em MonitoringQueryService::search.
 *
 * MySQL/MariaDB: FULLTEXT não se aplica a LIKE '%x%' com curinga à esquerda,
 * então não há índice a criar aqui. A janela temporal é o que contém o custo.
 *
 * Os índices são criados CONCURRENTLY para não travar escritas numa tabela
 * grande — por isso o statement roda fora de transação. maintenance_work_mem é
 * elevado só nesta sessão para acelerar o build do GIN (o default de 64MB torna
 * o build de índice trgm em tabela grande bem mais lento).
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY não pode rodar dentro de uma transação.
     */
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

        try {
            Schema::connection($this->getConnection())->createExtensionIfNotExists('pg_trgm');
        } catch (\Throwable) {
            // Sem privilégio para criar a extensão — segue sem os índices trgm.
            return;
        }

        // Acelera o build do GIN nesta sessão (não altera a config global do
        // servidor; reverte ao fechar a conexão). Ajuste conforme a RAM do banco.
        try {
            $connection->statement("SET maintenance_work_mem = '256MB'");
        } catch (\Throwable) {
            // Sem permissão para o SET — segue com o default do servidor.
        }

        $connection->statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS monitoring_content_trgm_idx
             ON monitoring USING GIN ((content::text) gin_trgm_ops)'
        );

        $connection->statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS monitoring_tags_trgm_idx
             ON monitoring USING GIN ((tags::text) gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('DROP INDEX CONCURRENTLY IF EXISTS monitoring_content_trgm_idx');
        $connection->statement('DROP INDEX CONCURRENTLY IF EXISTS monitoring_tags_trgm_idx');
    }
};
