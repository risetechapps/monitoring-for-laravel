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
        if (!$this->schema->hasTable('monitorings')) {
            $this->schema->create('monitorings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid');
                $table->uuid('batch_id');
                $table->string('type', 20);
                $table->json('content')->nullable();
                $table->json('tags')->nullable();
                $table->timestamps();
                $table->unique('uuid');
                $table->index('created_at');
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
        $this->schema->dropIfExists('monitorings');
    }
};
