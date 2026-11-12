<?php

namespace App\Policies;

use App\Models\Release;
use App\Models\User;

class ReleasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('releases.view');
    }

    public function create(User $user): bool
    {
        return $user->can('releases.publish');
    }

    public function update(User $user, Release $release): bool
    {
        return $user->can('releases.publish') && ! $release->is_published;
    }
}
