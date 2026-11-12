<?php

namespace Database\Seeders;

use App\Domain\Hosting\AccountProvisioner;
use App\Domain\Hosting\DatabaseManager;
use App\Domain\Hosting\DnsManager;
use App\Domain\Hosting\MailboxManager;
use App\Models\Account;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo content for the sandbox driver. It goes through the same services the
 * UI uses, so the data it produces is indistinguishable from real provisioning.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@atlas.test'],
            ['name' => 'Server administrator', 'password' => Hash::make('password'), 'is_active' => true, 'email_verified_at' => now()],
        );
        $admin->assignRole('admin');

        $reseller = User::firstOrCreate(
            ['email' => 'reseller@atlas.test'],
            ['name' => 'Priya (reseller)', 'password' => Hash::make('password'), 'is_active' => true, 'email_verified_at' => now()],
        );
        $reseller->assignRole('reseller');

        $package = Package::where('name', 'Business')->first() ?? Package::first();

        if (! $package) {
            return;
        }

        $provisioner = app(AccountProvisioner::class);
        $databases = app(DatabaseManager::class);
        $mailboxes = app(MailboxManager::class);
        $dns = app(DnsManager::class);

        // Direct customer.
        if (! Account::where('username', 'acme')->exists()) {
            $customer = User::firstOrCreate(
                ['email' => 'acme@atlas.test'],
                ['name' => 'Acme Traders', 'password' => Hash::make('password'), 'is_active' => true, 'email_verified_at' => now()],
            );
            $customer->assignRole('customer');

            $account = $provisioner->create('acme', 'acme.test', $package, $customer);

            $databases->create($account, 'acme_shop');
            $mailboxes->create($account, 'orders', 'Passw0rdDemo123', 1024);
            $mailboxes->create($account, 'support', 'Passw0rdDemo123', 2048);
            $dns->ensureZone($account, 'acme.test');
        }

        // Reseller-owned customer.
        if (! Account::where('username', 'dhanbad')->exists()) {
            $provisioner->create('dhanbad', 'dhanbad.in', $package, null, $reseller);
        }
    }
}
