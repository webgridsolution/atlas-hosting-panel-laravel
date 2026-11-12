<?php

namespace App\Policies;

use App\Models\FtpAccount;
use App\Models\User;

class FtpAccountPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, FtpAccount $ftp): bool
    {
        return $this->ownsAccount($user, $ftp->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, FtpAccount $ftp): bool
    {
        return $this->ownsAccount($user, $ftp->account, true);
    }

    public function delete(User $user, FtpAccount $ftp): bool
    {
        return $this->ownsAccount($user, $ftp->account, true);
    }
}
