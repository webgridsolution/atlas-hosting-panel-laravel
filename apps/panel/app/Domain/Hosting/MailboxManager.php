<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use App\Models\Mailbox;
use RuntimeException;

class MailboxManager
{
    public function __construct(private readonly Audit $audit) {}

    public function create(Account $account, string $localPart, string $password, int $quotaMb, ?string $domain = null): Mailbox
    {
        $domain ??= $account->domains()->where('is_primary', true)->value('domain')
            ?? $account->domains()->value('domain');

        if (! $domain) {
            throw new RuntimeException('Add a website domain before creating mailboxes.');
        }

        $address = strtolower($localPart).'@'.$domain;

        $this->audit->dispatch('mail.mailbox.create', [
            'domain' => $domain,
            'local_part' => strtolower($localPart),
            'password' => $password,
            'quota_mb' => $quotaMb,
        ], $account);

        return $account->mailboxes()->create([
            'address' => $address,
            'local_part' => strtolower($localPart),
            'domain' => $domain,
            'quota_mb' => $quotaMb,
        ]);
    }

    public function destroy(Mailbox $mailbox): void
    {
        $this->audit->dispatch('mail.mailbox.delete', [
            'domain' => $mailbox->domain,
            'local_part' => $mailbox->local_part,
        ], $mailbox->account);

        $mailbox->delete();
    }

    public function resetPassword(Mailbox $mailbox, string $password): void
    {
        // Re-creating the mailbox through the agent updates the Dovecot
        // password hash while leaving the maildir untouched.
        $this->audit->dispatch('mail.mailbox.create', [
            'domain' => $mailbox->domain,
            'local_part' => $mailbox->local_part,
            'password' => $password,
            'quota_mb' => $mailbox->quota_mb,
        ], $mailbox->account);
    }
}
