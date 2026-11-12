<?php

namespace App\Policies;

use App\Models\License;
use App\Models\User;

class LicensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('licenses.view') || $user->can('portal.access');
    }

    public function view(User $user, License $license): bool
    {
        if ($user->can('licenses.view')) {
            return true;
        }

        return $license->customer?->user_id === $user->id;
    }

    public function issue(User $user): bool
    {
        return $user->can('licenses.issue');
    }

    public function suspend(User $user, License $license): bool
    {
        return $user->can('licenses.suspend');
    }

    public function revoke(User $user, License $license): bool
    {
        return $user->can('licenses.revoke');
    }

    public function transfer(User $user, License $license): bool
    {
        return $user->can('licenses.transfer');
    }
}
