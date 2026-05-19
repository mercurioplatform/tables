<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->string('status');
            $table->decimal('total', 10, 2)->default(0);
            $table->string('customer');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_orders');
    }
};
