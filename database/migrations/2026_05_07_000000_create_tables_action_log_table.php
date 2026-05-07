<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_action_log', function (Blueprint $table) {
            $table->id();
            $table->string('resource_key', 120);
            $table->string('action_name', 120);
            $table->string('kind', 8);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->json('subjects_json')->nullable();
            $table->json('result_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['resource_key', 'id'], 'tal_resource_id_idx');
            $table->index('actor_id', 'tal_actor_idx');
        });

        Log::debug('migration.tables_action_log.up', ['table' => 'tables_action_log']);
    }

    public function down(): void
    {
        Schema::dropIfExists('tables_action_log');
    }
};
