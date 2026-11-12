<?php

namespace App\Policies;

use App\Models\InstalledApplication;
use App\Models\User;

class InstalledApplicationPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, InstalledApplication $application): bool
    {
        return $this->ownsAccount($user, $application->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, InstalledApplication $application): bool
    {
        return $this->ownsAccount($user, $application->account, true);
    }

    public function delete(User $user, InstalledApplication $application): bool
    {
        return $this->ownsAccount($user, $application->account, true);
    }
}
