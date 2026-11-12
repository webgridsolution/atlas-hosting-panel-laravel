<?php

namespace App\Policies;

use App\Models\DnsZone;
use App\Models\User;

class DnsZonePolicy
{
    use ChecksAccountAccess;

    public function view(User $user, DnsZone $zone): bool
    {
        return $this->ownsAccount($user, $zone->account);
    }

    public function update(User $user, DnsZone $zone): bool
    {
        return $this->ownsAccount($user, $zone->account, true);
    }
}
