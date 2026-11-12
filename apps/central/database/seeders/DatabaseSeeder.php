<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TaxRateSeeder::class,
            RoleAndPermissionSeeder::class,
            FeatureSeeder::class,
            PlanSeeder::class,
            DemoDataSeeder::class,
            CommerceDemoSeeder::class,
        ]);
    }
}
