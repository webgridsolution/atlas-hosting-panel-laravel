<?php

namespace App\Policies;

use App\Models\DatabaseUser;
use App\Models\User;

class DatabaseUserPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, DatabaseUser $dbUser): bool
    {
        return $this->ownsAccount($user, $dbUser->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, DatabaseUser $dbUser): bool
    {
        return $this->ownsAccount($user, $dbUser->account, true);
    }

    public function delete(User $user, DatabaseUser $dbUser): bool
    {
        return $this->ownsAccount($user, $dbUser->account, true);
    }
}
