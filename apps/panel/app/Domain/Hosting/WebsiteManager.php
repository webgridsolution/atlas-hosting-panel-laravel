<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use App\Models\DnsZone;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;

class WebsiteManager
{
    public function __construct(
        private readonly Audit $audit,
        private readonly DnsManager $dns,
    ) {}

    /**
     * Adds a domain to an account: vhost on the machine, row in the panel, and
     * a DNS zone when the panel manages DNS.
     */
    public function addDomain(Account $account, string $domain, ?string $documentRoot = null): Domain
    {
        $documentRoot ??= $account->docRoot();

        $this->audit->dispatch('vhost.write', [
            'domain' => $domain,
            'username' => $account->username,
            'document_root' => $documentRoot,
            'aliases' => [],
            'php_version' => $account->php_version,
            'ssl' => false,
        ], $account);

        $row = DB::transaction(fn () => $account->domains()->create([
            'domain' => $domain,
            'document_root' => $documentRoot,
            'is_primary' => $account->domains()->count() === 0,
        ]));

        $this->ensureZone($account, $domain);

        return $row;
    }

    public function removeDomain(Domain $domain): void
    {
        $this->audit->dispatch('vhost.remove', ['domain' => $domain->domain], $domain->account);

        $zone = DnsZone::where('domain', $domain->domain)->first();

        $domain->delete();

        // A zone with records for a domain nobody hosts any more is a support
        // ticket waiting to happen; remove it with the website.
        $zone?->records()->delete();
        $zone?->delete();
    }

    /**
     * Issues an ACME certificate and re-renders the vhost with TLS on.
     */
    public function issueCertificate(Domain $domain): Domain
    {
        $account = $domain->account;

        $domain->update(['ssl_status' => Domain::SSL_PENDING]);

        try {
            $result = $this->audit->dispatch('ssl.issue', [
                'domain' => $domain->domain,
                'username' => $account->username,
            ], $account);

            $this->audit->dispatch('vhost.write', [
                'domain' => $domain->domain,
                'username' => $account->username,
                'document_root' => $domain->document_root,
                'aliases' => [],
                'php_version' => $account->php_version,
                'ssl' => true,
            ], $account);

            $domain->update([
                'ssl_status' => Domain::SSL_ACTIVE,
                'ssl_expires_at' => isset($result['expires_at']) ? now()->parse($result['expires_at']) : now()->addDays(90),
                'ssl_issuer' => $result['issuer'] ?? 'ACME',
            ]);
        } catch (\Throwable $e) {
            $domain->update(['ssl_status' => Domain::SSL_FAILED]);

            throw $e;
        }

        return $domain->refresh();
    }

    public function ensureZone(Account $account, string $domain): void
    {
        if (! config('atlas.dns.enabled')) {
            return;
        }

        $this->dns->ensureZone($account, $domain);
    }
}
