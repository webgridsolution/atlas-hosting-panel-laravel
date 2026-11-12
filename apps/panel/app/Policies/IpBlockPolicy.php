<?php

namespace App\Policies;

use App\Models\IpBlock;
use App\Models\User;

class IpBlockPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, IpBlock $block): bool
    {
        return $this->ownsAccount($user, $block->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, IpBlock $block): bool
    {
        return $this->ownsAccount($user, $block->account, true);
    }

    public function delete(User $user, IpBlock $block): bool
    {
        return $this->ownsAccount($user, $block->account, true);
    }
}
