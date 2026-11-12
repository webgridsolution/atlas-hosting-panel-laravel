<?php

namespace App\Policies;

use App\Models\HostingDatabase;
use App\Models\User;

class HostingDatabasePolicy
{
    use ChecksAccountAccess;

    public function view(User $user, HostingDatabase $database): bool
    {
        return $this->ownsAccount($user, $database->account);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('customer') || $user->hasRole('admin');
    }

    public function delete(User $user, HostingDatabase $database): bool
    {
        return $this->ownsAccount($user, $database->account, true);
    }
}
