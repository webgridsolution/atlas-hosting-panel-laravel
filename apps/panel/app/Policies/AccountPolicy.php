<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    /** Admins see every account; resellers see their own; customers see theirs. */
    public function view(User $user, Account $account): bool
    {
        return $user->hasRole('admin')
            || ($user->hasRole('reseller') && $account->reseller_id === $user->id)
            || $account->user_id === $user->id;
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasRole('admin') || $account->user_id === $user->id;
    }

    public function manage(User $user, Account $account): bool
    {
        return $user->hasRole('admin')
            || ($user->hasRole('reseller') && $account->reseller_id === $user->id);
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->hasRole('admin')
            || ($user->hasRole('reseller') && $account->reseller_id === $user->id);
    }

    /** Only an admin may delete data on disk. */
    public function purge(User $user, Account $account): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('reseller');
    }
}
