<?php

namespace App\Policies;

use App\Models\EmailForwarder;
use App\Models\User;

class EmailForwarderPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, EmailForwarder $forwarder): bool
    {
        return $this->ownsAccount($user, $forwarder->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, EmailForwarder $forwarder): bool
    {
        return $this->ownsAccount($user, $forwarder->account, true);
    }

    public function delete(User $user, EmailForwarder $forwarder): bool
    {
        return $this->ownsAccount($user, $forwarder->account, true);
    }
}
