<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_action_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('resource_key', 120);
            $table->string('action_name', 120);
            $table->string('kind', 8);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('affected')->default(0);
            $table->unsignedInteger('missing')->default(0);
            $table->unsignedInteger('denied')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('affected_ids_json')->nullable();
            $table->json('payload_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['actor_id', 'created_at'], 'tap_actor_created_idx');
            $table->index(['resource_key', 'created_at'], 'tap_resource_created_idx');
            $table->index(['status', 'updated_at'], 'tap_status_updated_idx');
        });

        Log::debug('migration.tables_action_progress.up', ['table' => 'tables_action_progress']);
    }

    public function down(): void
    {
        Schema::dropIfExists('tables_action_progress');
    }
};
