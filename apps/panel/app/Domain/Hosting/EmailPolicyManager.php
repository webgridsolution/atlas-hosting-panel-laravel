<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\Autoresponder;
use App\Models\Domain;
use App\Models\EmailCatchall;
use App\Models\EmailFilter;
use App\Models\EmailForwarder;
use App\Models\EmailSetting;
use App\Models\MailingList;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens to a message after it is accepted: forwarding,
 * automatic replies, the catch-all, spam policy, DKIM keys, server-side filter
 * rules and mailing lists.
 *
 * Filters are compiled into one Sieve script per mailbox, so the whole rule set
 * is rewritten whenever one rule changes — that is why saveFilters() takes the
 * complete list rather than a single rule.
 */
class EmailPolicyManager
{
    public function __construct(private readonly Agent $agent) {}

    public function createForwarder(Account $account, Domain $domain, string $source, array $destinations, bool $keepCopy): EmailForwarder
    {
        $destinations = array_values(array_filter(array_map('trim', $destinations)));

        $this->agent->call('mail.forwarder.write', [
            'domain' => $domain->domain,
            'source' => $source,
            'destinations' => $destinations,
            'keep_copy' => $keepCopy,
        ]);

        return DB::transaction(fn () => EmailForwarder::updateOrCreate(
            ['domain_id' => $domain->id, 'source' => $source],
            [
                'account_id' => $account->id,
                'destination' => implode(', ', $destinations),
                'keep_copy' => $keepCopy,
                'is_active' => true,
            ],
        ));
    }

    public function deleteForwarder(EmailForwarder $forwarder): void
    {
        $this->agent->call('mail.forwarder.remove', [
            'domain' => $forwarder->domain->domain,
            'source' => $forwarder->source,
        ]);

        $forwarder->delete();
    }

    public function saveAutoresponder(Account $account, Domain $domain, array $data): Autoresponder
    {
        $this->agent->call('mail.autoresponder.write', [
            'domain' => $domain->domain,
            'mailbox' => $data['mailbox'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'starts_at' => $data['starts_at'] ?? '',
            'ends_at' => $data['ends_at'] ?? '',
        ]);

        return DB::transaction(fn () => Autoresponder::updateOrCreate(
            ['domain_id' => $domain->id, 'mailbox' => $data['mailbox']],
            [
                'account_id' => $account->id,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => true,
            ],
        ));
    }

    public function deleteAutoresponder(Autoresponder $autoresponder): void
    {
        $this->agent->call('mail.autoresponder.remove', [
            'domain' => $autoresponder->domain->domain,
            'mailbox' => $autoresponder->mailbox,
        ]);

        $autoresponder->delete();
    }

    public function setCatchall(Account $account, Domain $domain, string $destination): EmailCatchall
    {
        $this->agent->call('mail.catchall.write', [
            'domain' => $domain->domain,
            'destination' => $destination,
        ]);

        return DB::transaction(fn () => EmailCatchall::updateOrCreate(
            ['domain_id' => $domain->id],
            ['account_id' => $account->id, 'destination' => $destination],
        ));
    }

    public function removeCatchall(Domain $domain): void
    {
        $this->agent->call('mail.catchall.remove', ['domain' => $domain->domain]);

        EmailCatchall::where('domain_id', $domain->id)->delete();
    }

    public function saveSettings(Account $account, Domain $domain, array $data): EmailSetting
    {
        $this->agent->call('mail.spam.write', [
            'domain' => $domain->domain,
            'spam_threshold' => (string) $data['spam_threshold'],
            'spam_action' => $data['spam_action'],
            'dkim_enabled' => (bool) ($data['dkim_enabled'] ?? false),
            'spf_enabled' => (bool) ($data['spf_enabled'] ?? false),
            'greylisting' => (bool) ($data['greylisting'] ?? false),
            'mailbox_quota_mb' => (int) ($data['mailbox_quota_mb'] ?? 1024),
        ]);

        return DB::transaction(fn () => EmailSetting::updateOrCreate(
            ['domain_id' => $domain->id],
            [
                'account_id' => $account->id,
                'spam_threshold' => $data['spam_threshold'],
                'spam_action' => $data['spam_action'],
                'dkim_enabled' => (bool) ($data['dkim_enabled'] ?? false),
                'spf_enabled' => (bool) ($data['spf_enabled'] ?? false),
                'greylisting' => (bool) ($data['greylisting'] ?? false),
                'mailbox_quota_mb' => (int) ($data['mailbox_quota_mb'] ?? 1024),
            ],
        ));
    }

    /**
     * Generates a key and hands back the TXT record the customer has to publish.
     * Nothing is stored: the agent owns the private key.
     *
     * @return array<string, mixed>
     */
    public function ensureDkim(Domain $domain): array
    {
        return $this->agent->call('mail.dkim.ensure', ['domain' => $domain->domain]);
    }

    public function createList(Account $account, Domain $domain, string $name, ?string $title, array $members): MailingList
    {
        $members = array_values(array_filter(array_map('trim', $members)));

        $this->agent->call('mail.list.write', [
            'domain' => $domain->domain,
            'name' => $name,
            'title' => $title ?? $name,
            'members' => $members,
        ]);

        return DB::transaction(fn () => MailingList::updateOrCreate(
            ['domain_id' => $domain->id, 'name' => $name],
            [
                'account_id' => $account->id,
                'title' => $title,
                'members' => implode(', ', $members),
                'subscribers' => count($members),
                'status' => 'active',
            ],
        ));
    }

    public function deleteList(MailingList $list): void
    {
        $this->agent->call('mail.list.remove', [
            'domain' => $list->domain->domain,
            'name' => $list->name,
        ]);

        $list->delete();
    }

    public function addFilter(Account $account, Domain $domain, array $data): EmailFilter
    {
        $priority = (int) EmailFilter::where('domain_id', $domain->id)
            ->where('mailbox', $data['mailbox'])
            ->max('priority') + 10;

        return DB::transaction(function () use ($account, $domain, $data, $priority) {
            $filter = EmailFilter::create([
                'account_id' => $account->id,
                'domain_id' => $domain->id,
                'mailbox' => $data['mailbox'],
                'field' => $data['field'],
                'operator' => $data['operator'],
                'value' => $data['value'],
                'action' => $data['action'],
                'action_arg' => $data['action_arg'] ?? null,
                'priority' => $data['priority'] ?? $priority,
                'is_active' => true,
            ]);

            $this->pushFilters($domain, $data['mailbox']);

            return $filter;
        });
    }

    public function deleteFilter(EmailFilter $filter): void
    {
        DB::transaction(function () use ($filter) {
            $domain = $filter->domain;
            $mailbox = $filter->mailbox;

            $filter->delete();

            $this->pushFilters($domain, $mailbox);
        });
    }

    /** Rewrites the mailbox's whole Sieve script from the rows that remain. */
    public function pushFilters(Domain $domain, string $mailbox): void
    {
        $rules = EmailFilter::where('domain_id', $domain->id)
            ->where('mailbox', $mailbox)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get()
            ->map(fn (EmailFilter $f) => [
                'field' => $f->field,
                'operator' => $f->operator,
                'value' => $f->value,
                'action' => $f->action,
                'action_arg' => $f->action_arg,
            ])
            ->all();

        $this->agent->call('mail.filter.write', [
            'domain' => $domain->domain,
            'mailbox' => $mailbox,
            'rules' => $rules,
        ]);
    }
}
