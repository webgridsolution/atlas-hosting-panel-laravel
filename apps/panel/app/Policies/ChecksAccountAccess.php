<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Shared ownership rules for models that belong to an account: domains,
 * databases, mailboxes, DNS records, cron jobs and backups all inherit the
 * visibility of the account they belong to.
 */
trait ChecksAccountAccess
{
    protected function ownsAccount(User $user, ?Account $account, bool $write = false): bool
    {
        if (! $account) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('reseller')) {
            return $write
                ? $account->reseller_id === $user->id
                : $account->reseller_id === $user->id;
        }

        return $write
            ? $account->user_id === $user->id && $account->status === Account::STATUS_ACTIVE
            : $account->user_id === $user->id;
    }
}
