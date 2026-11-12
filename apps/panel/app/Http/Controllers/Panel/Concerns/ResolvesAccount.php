<?php

namespace App\Http\Controllers\Panel\Concerns;

use App\Models\Account;

/**
 * Every customer-panel screen needs the sender's account, and every one of
 * them must behave sensibly when the account is missing (a login created but
 * not yet provisioned).
 */
trait ResolvesAccount
{
    protected function account(): ?Account
    {
        return request()->user()?->account();
    }

    protected function requireAccount(): Account
    {
        $account = $this->account();

        abort_if($account === null, 403, 'No hosting account is attached to this login.');

        return $account;
    }
}
