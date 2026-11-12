<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view') || $user->can('portal.access');
    }

    public function cancel(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.cancel') || $subscription->customer?->user_id === $user->id;
    }
}
