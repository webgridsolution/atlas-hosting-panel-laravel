<?php

namespace App\Listeners;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class ProvisionCustomerAccount
{
    public function handle(Registered $event): void
    {
        /** @var User $user */
        $user = $event->user;

        DB::transaction(function () use ($user) {
            Customer::firstOrCreate(
                ['user_id' => $user->id],
                ['country' => 'IN', 'status' => 'active'],
            );

            if (! $user->hasAnyRole(['super_admin', 'admin', 'finance', 'support', 'content_editor'])) {
                // findOrCreate keeps registration working on a freshly migrated DB
                // where the RBAC seeder has not run yet.
                $user->assignRole(Role::findOrCreate('customer', 'web'));
            }
        });
    }
}
