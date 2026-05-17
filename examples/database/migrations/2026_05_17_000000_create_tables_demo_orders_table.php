<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables demo: миграция для SqlSourceDemo.
 *
 * Создаёт префиксированную таблицу `tables_demo_orders`, чтобы demo-набор не
 * конфликтовал с возможной хост-таблицей `orders`. Запускается обычным
 * `php artisan migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_demo_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('city', 64);
            $table->string('status', 16);
            $table->string('payment_status', 16);
            $table->decimal('total', 12, 2);
            $table->timestamps();

            $table->index('status');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables_demo_orders');
    }
};
