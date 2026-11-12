<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adding and removing domains.
 *
 * Order matters and is deliberate: the vhost is written first, the database row
 * second. A row without a vhost is a domain the panel believes in and the web
 * server has never heard of, which is worse than no row at all.
 */
class DomainManager
{
    public function __construct(private readonly Agent $agent) {}

    public function addSubdomain(Account $account, Domain $parent, string $label, bool $ownRoot, ?string $phpVersion): Domain
    {
        $fqdn = $label.'.'.$parent->domain;

        // The name is assembled from a label and its parent, so the request
        // never sees the full hostname. Without this the duplicate reaches the
        // unique index and the customer gets a database error instead of a
        // sentence.
        if (Domain::where('domain', $fqdn)->exists()) {
            throw new RuntimeException("{$fqdn} is already on this account.");
        }

        $docroot = $ownRoot
            ? $account->home().'/'.$fqdn
            : $parent->document_root;

        $this->agent->call('domain.subdomain.create', [
            'username' => $account->username,
            'domain' => $parent->domain,
            'label' => $label,
            'document_root' => $docroot,
            'php_version' => $phpVersion ?: $account->php_version,
            'mail_enabled' => false,
        ]);

        return DB::transaction(fn () => Domain::create([
            'account_id' => $account->id,
            'type' => Domain::TYPE_SUBDOMAIN,
            'parent_id' => $parent->id,
            'domain' => $fqdn,
            'label' => $label,
            'document_root' => $docroot,
            'is_primary' => false,
            'php_version' => $phpVersion ?: $account->php_version,
            'status' => 'active',
            'provisioned_at' => now(),
        ]));
    }

    public function addAddonDomain(Account $account, string $domain, ?string $phpVersion, bool $withMail = true): Domain
    {
        if (Domain::where('domain', $domain)->exists()) {
            throw new RuntimeException("{$domain} is already on this account.");
        }

        $docroot = $account->home().'/'.$domain;

        $this->agent->call('vhost.write', [
            'domain' => $domain,
            'username' => $account->username,
            'document_root' => $docroot,
            'php_version' => $phpVersion ?: $account->php_version,
            'mail_enabled' => $withMail,
            'ssl' => false,
        ]);

        return DB::transaction(fn () => Domain::create([
            'account_id' => $account->id,
            'type' => Domain::TYPE_ADDON,
            'domain' => $domain,
            'document_root' => $docroot,
            'is_primary' => false,
            'php_version' => $phpVersion ?: $account->php_version,
            'mail_enabled' => $withMail,
            'status' => 'active',
            'provisioned_at' => now(),
        ]));
    }

    public function parkDomain(Account $account, Domain $target, string $alias): Domain
    {
        if (Domain::where('domain', $alias)->exists()) {
            throw new RuntimeException("{$alias} is already on this account.");
        }

        $this->agent->call('domain.alias.create', [
            'username' => $account->username,
            'domain' => $target->domain,
            'aliases' => [$alias],
        ]);

        return DB::transaction(fn () => Domain::create([
            'account_id' => $account->id,
            'type' => Domain::TYPE_PARKED,
            'parent_id' => $target->id,
            'domain' => $alias,
            'document_root' => $target->document_root,
            'is_primary' => false,
            'php_version' => $target->php_version,
            'status' => 'active',
            'provisioned_at' => now(),
        ]));
    }

    /**
     * Removes the vhost, then the row. Files stay on disk on purpose: deleting
     * a customer's site content as a side effect of removing a name is not a
     * decision this class is allowed to make.
     */
    public function remove(Account $account, Domain $domain): void
    {
        if ($domain->is_primary) {
            throw new RuntimeException('The primary domain cannot be removed.');
        }

        $this->agent->call('domain.remove', [
            'username' => $account->username,
            'domain' => $domain->domain,
        ]);

        $domain->delete();
    }
}
