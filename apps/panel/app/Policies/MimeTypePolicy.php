<?php

namespace App\Policies;

use App\Models\MimeType;
use App\Models\User;

class MimeTypePolicy
{
    use ChecksAccountAccess;

    public function view(User $user, MimeType $mime): bool
    {
        return $this->ownsAccount($user, $mime->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, MimeType $mime): bool
    {
        return $this->ownsAccount($user, $mime->account, true);
    }

    public function delete(User $user, MimeType $mime): bool
    {
        return $this->ownsAccount($user, $mime->account, true);
    }
}
