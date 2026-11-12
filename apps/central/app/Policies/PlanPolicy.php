<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(?User $user): bool
    {
        // The public pricing page does not use this policy; the staff console does.
        return $user?->can('plans.view') ?? false;
    }

    public function view(?User $user, Plan $plan): bool
    {
        return $plan->is_active || (bool) $user?->can('plans.view');
    }

    public function create(User $user): bool
    {
        return $user->can('plans.manage');
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage');
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage') && ! $plan->licenses()->exists();
    }
}
