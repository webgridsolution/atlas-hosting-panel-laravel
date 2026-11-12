<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'whm.access', 'accounts.manage', 'packages.manage', 'services.restart',
            'settings.manage', 'licence.manage', 'audit.view',
            'reseller.access',
            'site.manage', 'database.manage', 'mail.manage', 'dns.manage',
            'ssl.manage', 'cron.manage', 'backup.manage', 'file.manage',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions($permissions);

        $reseller = Role::findOrCreate('reseller', 'web');
        $reseller->syncPermissions([
            'reseller.access', 'accounts.manage', 'site.manage', 'database.manage',
            'mail.manage', 'dns.manage', 'ssl.manage', 'cron.manage', 'backup.manage', 'file.manage',
        ]);

        $customer = Role::findOrCreate('customer', 'web');
        $customer->syncPermissions([
            'site.manage', 'database.manage', 'mail.manage', 'dns.manage',
            'ssl.manage', 'cron.manage', 'backup.manage', 'file.manage',
        ]);
    }
}
