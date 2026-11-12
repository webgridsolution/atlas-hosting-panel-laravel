<?php

namespace App\Policies;

use App\Models\Redirect;
use App\Models\User;

class RedirectPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, Redirect $redirect): bool
    {
        return $this->ownsAccount($user, $redirect->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, Redirect $redirect): bool
    {
        return $this->ownsAccount($user, $redirect->account, true);
    }

    public function delete(User $user, Redirect $redirect): bool
    {
        return $this->ownsAccount($user, $redirect->account, true);
    }
}
