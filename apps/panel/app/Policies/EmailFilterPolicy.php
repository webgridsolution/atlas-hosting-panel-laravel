<?php

namespace App\Policies;

use App\Models\EmailFilter;
use App\Models\User;

class EmailFilterPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, EmailFilter $filter): bool
    {
        return $this->ownsAccount($user, $filter->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, EmailFilter $filter): bool
    {
        return $this->ownsAccount($user, $filter->account, true);
    }

    public function delete(User $user, EmailFilter $filter): bool
    {
        return $this->ownsAccount($user, $filter->account, true);
    }
}
