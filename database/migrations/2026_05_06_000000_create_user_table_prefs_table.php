<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_user_table_prefs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('resource_key', 120);
            $table->json('prefs_json');
            $table->timestamps();

            $table->unique(['user_id', 'resource_key'], 'tutp_user_resource_unique');
            $table->index('user_id', 'tutp_user_idx');
            $table->index('resource_key', 'tutp_resource_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('tables_user_table_prefs');
    }
};
