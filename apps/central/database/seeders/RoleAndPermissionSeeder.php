<?php

namespace Database\Seeders;

use App\Support\PermissionRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionRegistry::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (PermissionRegistry::ROLES as $role => $permissions) {
            $model = Role::findOrCreate($role, 'web');
            $model->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
