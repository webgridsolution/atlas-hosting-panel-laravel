<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, Backup $backup): bool
    {
        return $this->ownsAccount($user, $backup->account);
    }

    public function restore(User $user, Backup $backup): bool
    {
        return $this->ownsAccount($user, $backup->account, true);
    }
}
