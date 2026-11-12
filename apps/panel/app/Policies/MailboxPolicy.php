<?php

namespace App\Policies;

use App\Models\Mailbox;
use App\Models\User;

class MailboxPolicy
{
    use ChecksAccountAccess;

    public function view(User $user, Mailbox $mailbox): bool
    {
        return $this->ownsAccount($user, $mailbox->account);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('customer') || $user->hasRole('admin');
    }

    public function delete(User $user, Mailbox $mailbox): bool
    {
        return $this->ownsAccount($user, $mailbox->account, true);
    }
}
