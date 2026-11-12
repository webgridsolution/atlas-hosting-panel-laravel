<?php

namespace App\Policies;

use App\Models\DirectoryProtection;
use App\Models\User;

class DirectoryProtectionPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, DirectoryProtection $protection): bool
    {
        return $this->ownsAccount($user, $protection->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, DirectoryProtection $protection): bool
    {
        return $this->ownsAccount($user, $protection->account, true);
    }

    public function delete(User $user, DirectoryProtection $protection): bool
    {
        return $this->ownsAccount($user, $protection->account, true);
    }
}
