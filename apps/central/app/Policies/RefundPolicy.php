<?php

namespace App\Policies;

use App\Models\User;

class RefundPolicy
{
    public function create(User $user): bool
    {
        return $user->can('payments.refund');
    }
}
