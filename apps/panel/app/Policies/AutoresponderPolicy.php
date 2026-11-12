<?php

namespace App\Policies;

use App\Models\Autoresponder;
use App\Models\User;

class AutoresponderPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, Autoresponder $autoresponder): bool
    {
        return $this->ownsAccount($user, $autoresponder->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, Autoresponder $autoresponder): bool
    {
        return $this->ownsAccount($user, $autoresponder->account, true);
    }

    public function delete(User $user, Autoresponder $autoresponder): bool
    {
        return $this->ownsAccount($user, $autoresponder->account, true);
    }
}
