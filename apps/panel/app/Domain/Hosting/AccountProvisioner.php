<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Creates and removes hosting accounts. This is the one service that touches
 * the machine and the database together, so it is deliberately explicit about
 * what happens when the two disagree: the database row is created first as
 * "pending", and only becomes "active" once the agent confirms.
 */
class AccountProvisioner
{
    public function __construct(
        private readonly Audit $audit,
        private readonly WebsiteManager $websites,
    ) {}

    public function create(
        string $username,
        string $domain,
        Package $package,
        ?User $owner = null,
        ?User $reseller = null,
        ?string $notes = null,
    ): Account {
        $account = DB::transaction(function () use ($username, $domain, $package, $owner, $reseller, $notes) {
            return Account::create([
                'user_id' => $owner?->id,
                'reseller_id' => $reseller?->id,
                'package_id' => $package->id,
                'username' => $username,
                'domain' => $domain,
                'status' => Account::STATUS_PENDING,
                'disk_mb' => $package->disk_mb,
                'inodes' => $package->inodes,
                'bandwidth_mb' => $package->bandwidth_mb,
                'php_version' => $package->php_version,
                'notes' => $notes,
            ]);
        });

        try {
            $this->audit->dispatch('account.create', [
                'username' => $account->username,
                'domain' => $account->domain,
                'disk_mb' => $account->disk_mb,
                'inodes' => $account->inodes,
                'bandwidth_mb' => $account->bandwidth_mb,
                'php_version' => $account->php_version,
            ], $account);

            $this->audit->dispatch('vhost.write', [
                'domain' => $account->domain,
                'username' => $account->username,
                'document_root' => $account->docRoot(),
                'aliases' => [],
                'php_version' => $account->php_version,
                'ssl' => false,
            ], $account);

            DB::transaction(function () use ($account, $domain) {
                $account->domains()->create([
                    'domain' => $domain,
                    'document_root' => $account->docRoot(),
                    'is_primary' => true,
                ]);

                $account->update(['status' => Account::STATUS_ACTIVE, 'provisioned_at' => now()]);
            });

            $this->websites->ensureZone($account, $domain);

            return $account->refresh();
        } catch (Throwable $e) {
            // The account exists on disk (or partly does) but was not opened to
            // the customer. Leave it visible and pending so an admin can repair
            // rather than silently orphan a Linux user.
            $account->update(['status' => Account::STATUS_PENDING, 'notes' => trim(($notes ?? '')."\nprovisioning failed: ".$e->getMessage())]);

            Log::error('account provisioning failed', ['account' => $account->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function suspend(Account $account, string $reason = ''): void
    {
        $this->audit->dispatch('account.suspend', ['username' => $account->username], $account);

        $account->update([
            'status' => Account::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'notes' => trim(($account->notes ?? '')."\nsuspended: ".$reason),
        ]);
    }

    public function unsuspend(Account $account): void
    {
        $this->audit->dispatch('account.unsuspend', ['username' => $account->username], $account);

        $account->update(['status' => Account::STATUS_ACTIVE, 'suspended_at' => null]);
    }

    /**
     * Removes the hosting account. Data is kept unless the caller explicitly
     * asks for deletion — expiry or an admin mistake must never destroy a
     * customer's files.
     */
    public function destroy(Account $account, bool $deleteData = false): void
    {
        if ($deleteData && $account->status !== Account::STATUS_SUSPENDED) {
            throw new RuntimeException('Suspend the account before deleting its data.');
        }

        $this->audit->dispatch('account.delete', [
            'username' => $account->username,
            'keep_data' => ! $deleteData,
        ], $account);

        $account->delete();
    }

    public function setQuota(Account $account, int $diskMb, int $inodes): void
    {
        $this->audit->dispatch('account.quota.set', [
            'username' => $account->username,
            'disk_mb' => $diskMb,
            'inodes' => $inodes,
        ], $account);

        $account->update(['disk_mb' => $diskMb, 'inodes' => $inodes]);
    }
}
