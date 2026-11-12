<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;

class DomainPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, Domain $domain): bool
    {
        return $this->ownsAccount($user, $domain->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, Domain $domain): bool
    {
        return $this->ownsAccount($user, $domain->account, true);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $this->ownsAccount($user, $domain->account, true);
    }
}
