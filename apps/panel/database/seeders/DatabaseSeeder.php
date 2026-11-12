<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PackageSeeder::class,
        ]);

        Setting::put('server_id', 'SRV-DEMO-0001');
        Setting::put('server_hostname', gethostname() ?: 'atlas-demo');
        Setting::put('server_ip', '203.0.113.10');
        Setting::put('nameservers', 'ns1.atlas.test,ns2.atlas.test');
        Setting::put('server_fingerprint', hash('sha256', (string) config('app.key')));

        $this->call(DemoSeeder::class);

        // Populate every hosting-panel screen so none of them is an empty table.
        $this->call(HostingFeatureSeeder::class);

        // A locally signed demo licence, so the UI exercises the real Ed25519
        // verification path instead of a hard-coded "active".
        $this->call(DemoLicenceSeeder::class);
    }
}
