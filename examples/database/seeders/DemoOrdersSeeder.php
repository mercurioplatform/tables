<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tables demo: seeder для SqlSourceDemo.
 *
 * Заполняет таблицу `tables_demo_orders` 44 фиксированными строками.
 * Распределение покрывает все savedViews и summary-карточки:
 *   status: 6 new / 9 packing / 13 shipping / 11 delivered / 5 cancelled
 *   payment_status: 27 paid / 8 pending / 6 cod / 3 refunded
 *
 * Запуск: `php artisan db:seed --class=DemoOrdersSeeder`
 */
class DemoOrdersSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tables_demo_orders')->truncate();

        $now = now();
        $cities = [
            'Москва', 'Санкт-Петербург', 'Казань', 'Новосибирск', 'Екатеринбург',
            'Краснодар', 'Самара', 'Воронеж', 'Уфа', 'Челябинск',
        ];
        $rows = [
            // 6 new
            ['number' => 'ORD-2026-0001', 'status' => 'new', 'payment_status' => 'pending', 'total' => 1290.00],
            ['number' => 'ORD-2026-0002', 'status' => 'new', 'payment_status' => 'pending', 'total' => 4580.50],
            ['number' => 'ORD-2026-0003', 'status' => 'new', 'payment_status' => 'paid', 'total' => 12390.00],
            ['number' => 'ORD-2026-0004', 'status' => 'new', 'payment_status' => 'pending', 'total' => 720.00],
            ['number' => 'ORD-2026-0005', 'status' => 'new', 'payment_status' => 'pending', 'total' => 3150.00],
            ['number' => 'ORD-2026-0006', 'status' => 'new', 'payment_status' => 'paid', 'total' => 8990.00],

            // 9 packing
            ['number' => 'ORD-2026-0007', 'status' => 'packing', 'payment_status' => 'paid', 'total' => 5400.00],
            ['number' => 'ORD-2026-0008', 'status' => 'packing', 'payment_status' => 'paid', 'total' => 11800.00],
            ['number' => 'ORD-2026-0009', 'status' => 'packing', 'payment_status' => 'cod', 'total' => 2350.00],
            ['number' => 'ORD-2026-0010', 'status' => 'packing', 'payment_status' => 'paid', 'total' => 19990.00],
            ['number' => 'ORD-2026-0011', 'status' => 'packing', 'payment_status' => 'pending', 'total' => 4290.00],
            ['number' => 'ORD-2026-0012', 'status' => 'packing', 'payment_status' => 'paid', 'total' => 7600.00],
            ['number' => 'ORD-2026-0013', 'status' => 'packing', 'payment_status' => 'cod', 'total' => 1850.00],
            ['number' => 'ORD-2026-0014', 'status' => 'packing', 'payment_status' => 'paid', 'total' => 6320.00],
            ['number' => 'ORD-2026-0015', 'status' => 'packing', 'payment_status' => 'paid', 'total' => 14500.00],

            // 13 shipping
            ['number' => 'ORD-2026-0016', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 9800.00],
            ['number' => 'ORD-2026-0017', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 3450.00],
            ['number' => 'ORD-2026-0018', 'status' => 'shipping', 'payment_status' => 'cod', 'total' => 2780.00],
            ['number' => 'ORD-2026-0019', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 17200.00],
            ['number' => 'ORD-2026-0020', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 5600.00],
            ['number' => 'ORD-2026-0021', 'status' => 'shipping', 'payment_status' => 'pending', 'total' => 8900.00],
            ['number' => 'ORD-2026-0022', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 4250.00],
            ['number' => 'ORD-2026-0023', 'status' => 'shipping', 'payment_status' => 'cod', 'total' => 6700.00],
            ['number' => 'ORD-2026-0024', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 22500.00],
            ['number' => 'ORD-2026-0025', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 3300.00],
            ['number' => 'ORD-2026-0026', 'status' => 'shipping', 'payment_status' => 'cod', 'total' => 4870.00],
            ['number' => 'ORD-2026-0027', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 11200.00],
            ['number' => 'ORD-2026-0028', 'status' => 'shipping', 'payment_status' => 'paid', 'total' => 7950.00],

            // 11 delivered
            ['number' => 'ORD-2026-0029', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 12800.00],
            ['number' => 'ORD-2026-0030', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 5450.00],
            ['number' => 'ORD-2026-0031', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 9990.00],
            ['number' => 'ORD-2026-0032', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 3120.00],
            ['number' => 'ORD-2026-0033', 'status' => 'delivered', 'payment_status' => 'cod', 'total' => 6500.00],
            ['number' => 'ORD-2026-0034', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 18750.00],
            ['number' => 'ORD-2026-0035', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 4400.00],
            ['number' => 'ORD-2026-0036', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 7800.00],
            ['number' => 'ORD-2026-0037', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 2350.00],
            ['number' => 'ORD-2026-0038', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 15600.00],
            ['number' => 'ORD-2026-0039', 'status' => 'delivered', 'payment_status' => 'paid', 'total' => 5900.00],

            // 5 cancelled
            ['number' => 'ORD-2026-0040', 'status' => 'cancelled', 'payment_status' => 'refunded', 'total' => 8200.00],
            ['number' => 'ORD-2026-0041', 'status' => 'cancelled', 'payment_status' => 'pending', 'total' => 1450.00],
            ['number' => 'ORD-2026-0042', 'status' => 'cancelled', 'payment_status' => 'refunded', 'total' => 12300.00],
            ['number' => 'ORD-2026-0043', 'status' => 'cancelled', 'payment_status' => 'pending', 'total' => 3700.00],
            ['number' => 'ORD-2026-0044', 'status' => 'cancelled', 'payment_status' => 'refunded', 'total' => 6900.00],
        ];

        $records = [];
        foreach ($rows as $i => $row) {
            $records[] = [
                'number' => $row['number'],
                'city' => $cities[$i % count($cities)],
                'status' => $row['status'],
                'payment_status' => $row['payment_status'],
                'total' => $row['total'],
                'created_at' => $now->copy()->subDays(44 - $i),
                'updated_at' => $now->copy()->subDays(44 - $i),
            ];
        }

        DB::table('tables_demo_orders')->insert($records);
    }
}
