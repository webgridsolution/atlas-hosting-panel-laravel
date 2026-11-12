<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Starter', 'description' => 'One website, one database, 5 GB',
                'disk_mb' => 5120, 'inodes' => 100000, 'bandwidth_mb' => 50000,
                'max_domains' => 1, 'max_databases' => 1, 'max_mailboxes' => 5,
                'php_version' => '8.3', 'price_paise' => 24900,
            ],
            [
                'name' => 'Business', 'description' => 'Five websites, ten databases, 20 GB',
                'disk_mb' => 20480, 'inodes' => 400000, 'bandwidth_mb' => 200000,
                'max_domains' => 5, 'max_databases' => 10, 'max_mailboxes' => 25,
                'php_version' => '8.3', 'price_paise' => 79900,
            ],
            [
                'name' => 'Agency', 'description' => 'Unlimited sites, 50 GB',
                'disk_mb' => 51200, 'inodes' => 1000000, 'bandwidth_mb' => 500000,
                'max_domains' => 50, 'max_databases' => 50, 'max_mailboxes' => 100,
                'php_version' => '8.4', 'price_paise' => 199900,
            ],
        ];

        foreach ($packages as $package) {
            Package::updateOrCreate(['name' => $package['name']], $package + ['is_active' => true]);
        }
    }
}
