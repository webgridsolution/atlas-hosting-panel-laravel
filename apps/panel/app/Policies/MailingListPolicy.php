<?php

namespace App\Policies;

use App\Models\MailingList;
use App\Models\User;

class MailingListPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, MailingList $list): bool
    {
        return $this->ownsAccount($user, $list->account);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'reseller', 'customer']);
    }

    public function update(User $user, MailingList $list): bool
    {
        return $this->ownsAccount($user, $list->account, true);
    }

    public function delete(User $user, MailingList $list): bool
    {
        return $this->ownsAccount($user, $list->account, true);
    }
}
