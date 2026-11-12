<?php

namespace App\Policies;

use App\Models\RemoteMysqlHost;
use App\Models\User;

/**
 * Unlike the other hosting records this one is not created by the customer
 * directly — it is written when a database user is granted remote access — so
 * there is no `create` ability, only view and revoke.
 */
class RemoteMysqlHostPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, RemoteMysqlHost $host): bool
    {
        return $this->ownsAccount($user, $host->account);
    }

    public function delete(User $user, RemoteMysqlHost $host): bool
    {
        return $this->ownsAccount($user, $host->account, true);
    }
}
