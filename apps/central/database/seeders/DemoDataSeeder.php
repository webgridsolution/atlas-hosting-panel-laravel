<?php

namespace Database\Seeders;

use App\Domain\Licensing\IssueLicense;
use App\Enums\LicenseStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local/staging fixtures only. Never run in production.
 * Gives the UI real rows to render — no invented dashboard numbers in Blade.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $issueLicense = app(IssueLicense::class);

        $staff = User::updateOrCreate(
            ['email' => 'admin@atlas.test'],
            ['name' => 'Aarti Menon', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        $staff->syncRoles(['super_admin']);

        $owner = User::updateOrCreate(
            ['email' => 'owner@atlas.test'],
            ['name' => 'Rohit Sharma', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        $owner->syncRoles(['customer']);

        $ownerCustomer = Customer::updateOrCreate(
            ['user_id' => $owner->id],
            ['company_name' => 'Dhanbad Web Studio', 'phone' => '+91 90000 00000', 'country' => 'IN', 'gstin' => '20ABCDE1234F1Z5'],
        );

        $demoCustomers = [
            ['name' => 'Priya Nair', 'email' => 'priya@example.com', 'company' => 'Kochi Cloud', 'country' => 'IN'],
            ['name' => 'Daniel Okafor', 'email' => 'daniel@example.com', 'company' => 'Lagos Hosting Co', 'country' => 'NG'],
            ['name' => 'Meera Iyer', 'email' => 'meera@example.com', 'company' => null, 'country' => 'IN'],
        ];

        foreach ($demoCustomers as $row) {
            $user = User::updateOrCreate(
                ['email' => $row['email']],
                ['name' => $row['name'], 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );
            $user->syncRoles(['customer']);

            Customer::updateOrCreate(
                ['user_id' => $user->id],
                ['company_name' => $row['company'], 'country' => $row['country']],
            );
        }

        if ($ownerCustomer->licenses()->exists()) {
            return; // idempotent for repeated seeds
        }

        $professional = Plan::where('slug', 'professional')->firstOrFail();
        $personal = Plan::where('slug', 'personal')->firstOrFail();
        $business = Plan::where('slug', 'business')->firstOrFail();

        $primary = $issueLicense->handle($ownerCustomer, $professional, 'annual');
        $primary->update([
            'status' => LicenseStatus::Active,
            'activated_at' => now()->subMonths(2),
            'last_seen_at' => now()->subHours(3),
            'fingerprint_hash' => hash('sha256', 'demo-fingerprint-owner-primary'),
        ]);

        Server::create([
            'customer_id' => $ownerCustomer->id,
            'license_id' => $primary->id,
            'hostname' => 'panel.dhanbadweb.in',
            'ip_address' => '203.0.113.24',
            'os' => 'Ubuntu 24.04 LTS',
            'panel_version' => '0.2.0',
            'fingerprint_hash' => hash('sha256', 'demo-fingerprint-owner-primary'),
            'status' => 'online',
            'last_heartbeat_at' => now()->subHours(2),
        ]);

        $secondary = $issueLicense->handle($ownerCustomer, $personal, 'monthly');
        $secondary->update(['status' => LicenseStatus::PendingActivation]);

        // Realistic spread for staff views.
        $others = Customer::whereKeyNot($ownerCustomer->id)->get();

        if ($others->count() >= 3) {
            $a = $others[0]->licenses()->create([
                'plan_id' => $business->id,
                'key' => 'ATLS-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
                'status' => LicenseStatus::Active,
                'activated_at' => now()->subMonths(5),
                'expires_at' => now()->addDays(12),
                'last_seen_at' => now()->subDay(),
            ]);

            Server::create([
                'customer_id' => $others[0]->id,
                'license_id' => $a->id,
                'hostname' => 'cp.kochicloud.in',
                'ip_address' => '198.51.100.7',
                'os' => 'Ubuntu 24.04 LTS',
                'status' => 'online',
                'last_heartbeat_at' => now()->subHours(1),
            ]);

            $others[1]->licenses()->create([
                'plan_id' => $professional->id,
                'key' => 'ATLS-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
                'status' => LicenseStatus::Grace,
                'activated_at' => now()->subMonths(13),
                'expires_at' => now()->subDays(4),
                'last_seen_at' => now()->subDays(3),
            ]);

            $others[2]->licenses()->create([
                'plan_id' => $personal->id,
                'key' => 'ATLS-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
                'status' => LicenseStatus::Expired,
                'activated_at' => now()->subMonths(14),
                'expires_at' => now()->subDays(45),
                'last_seen_at' => now()->subDays(30),
            ]);
        }
    }
}
