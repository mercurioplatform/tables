<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_saved_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('resource_key', 120);
            $table->string('key', 60);
            $table->string('name', 120);
            $table->string('color', 20)->nullable();
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->text('state_json');
            $table->timestamps();

            $table->index('user_id', 'tsv_user_idx');
            $table->index('resource_key', 'tsv_resource_idx');
            $table->unique(['resource_key', 'key', 'user_id'], 'tsv_resource_key_user_unique');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('tables_saved_views');
    }
};
