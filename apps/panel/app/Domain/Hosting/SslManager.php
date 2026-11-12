<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\Domain;
use App\Models\SslCertificate;
use Illuminate\Support\Facades\DB;

/**
 * Certificates. The panel stores the public certificate and a *path* to the key
 * the agent wrote — never key material — so a database dump cannot be used to
 * impersonate the site.
 */
class SslManager
{
    public function __construct(private readonly Agent $agent) {}

    /** @return array<string, mixed> */
    public function csr(Domain $domain, ?string $country = null, ?string $organisation = null): array
    {
        return $this->agent->call('ssl.csr', [
            'domain' => $domain->domain,
            'country' => $country ?: 'IN',
            'organisation' => $organisation ?: $domain->domain,
        ]);
    }

    public function install(Account $account, Domain $domain, string $certPem, ?string $keyPem, ?string $caPem, ?string $keyPath): SslCertificate
    {
        $result = $this->agent->call('ssl.install', [
            'domain' => $domain->domain,
            'username' => $account->username,
            'cert_pem' => $certPem,
            'key_pem' => $keyPem ?? '',
            'key_path' => $keyPath ?? '',
            'ca_pem' => $caPem ?? '',
        ]);

        $expires = isset($result['expires_at']) ? now()->parse($result['expires_at']) : null;

        return DB::transaction(function () use ($account, $domain, $certPem, $keyPath, $result, $expires) {
            $row = SslCertificate::create([
                'account_id' => $account->id,
                'domain_id' => $domain->id,
                'common_name' => $domain->domain,
                'kind' => 'custom',
                'cert_pem' => $certPem,
                'key_path' => $keyPath ?? ($result['key_path'] ?? null),
                'issuer' => $result['issuer'] ?? 'imported',
                'issued_at' => now(),
                'expires_at' => $expires,
                'status' => 'active',
            ]);

            $domain->update([
                'ssl_status' => Domain::SSL_ACTIVE,
                'ssl_expires_at' => $expires,
                'ssl_issuer' => $result['issuer'] ?? 'imported',
            ]);

            return $row;
        });
    }

    /** Issues through the agent's ACME client. */
    public function autoIssue(Account $account, Domain $domain): SslCertificate
    {
        $result = $this->agent->call('ssl.issue', [
            'username' => $account->username,
            'domain' => $domain->domain,
            'aliases' => ['www.'.$domain->domain],
        ]);

        $expires = isset($result['expires_at']) ? now()->parse($result['expires_at']) : now()->addDays(90);

        return DB::transaction(function () use ($account, $domain, $result, $expires) {
            $row = SslCertificate::create([
                'account_id' => $account->id,
                'domain_id' => $domain->id,
                'common_name' => $domain->domain,
                'kind' => 'letsencrypt',
                'san' => $domain->domain."\nwww.".$domain->domain,
                'key_path' => $result['key_path'] ?? null,
                'issuer' => $result['issuer'] ?? "Let's Encrypt",
                'issued_at' => now(),
                'expires_at' => $expires,
                'status' => 'active',
            ]);

            $domain->update([
                'ssl_status' => Domain::SSL_ACTIVE,
                'ssl_expires_at' => $expires,
                'ssl_issuer' => $result['issuer'] ?? "Let's Encrypt",
            ]);

            return $row;
        });
    }

    /** @return array<string, mixed> */
    public function inspect(Domain $domain): array
    {
        return $this->agent->call('ssl.info', ['domain' => $domain->domain]);
    }

    public function remove(SslCertificate $certificate): void
    {
        if ($certificate->domain) {
            $certificate->domain->update([
                'ssl_status' => Domain::SSL_NONE,
                'ssl_expires_at' => null,
                'ssl_issuer' => null,
            ]);
        }

        $certificate->delete();
    }
}
