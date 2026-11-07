<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // صدا زدن سیدر لوکیشن‌ها برای تزریق اطلاعات استان، شهر و بخش
        $this->call([
                SiteSeeder::class,

            // AdminSeeder::class,
            // PlanSeeder::class,
//             HozeSeeder::class,
// FieldOfStudySeeder::class,
// MantSeeder::class,

        ]);
    }
}
