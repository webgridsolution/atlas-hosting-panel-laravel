<?php

namespace App\Policies;

use App\Models\CronJob;
use App\Models\User;

class CronJobPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, CronJob $job): bool
    {
        return $this->ownsAccount($user, $job->account);
    }

    public function delete(User $user, CronJob $job): bool
    {
        return $this->ownsAccount($user, $job->account, true);
    }
}
