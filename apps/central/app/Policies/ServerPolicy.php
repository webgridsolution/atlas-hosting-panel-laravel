<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('servers.view') || $user->can('portal.access');
    }

    public function view(User $user, Server $server): bool
    {
        if ($user->can('servers.view')) {
            return true;
        }

        return $server->customer?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('portal.access');
    }

    public function delete(User $user, Server $server): bool
    {
        if ($server->customer?->user_id === $user->id) {
            return true;
        }

        return $user->can('servers.deregister');
    }
}
