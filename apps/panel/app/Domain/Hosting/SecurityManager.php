<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Models\Account;
use App\Models\DirectoryProtection;
use App\Models\IpBlock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IP blocking and directory privacy.
 *
 * Both are written inside the account's own document root (.htaccess), so a
 * customer cannot affect another tenant. Every path is resolved by
 * absolutePath(), which refuses anything that climbs out.
 */
class SecurityManager
{
    public function __construct(private readonly Agent $agent) {}

    public function blockIp(Account $account, string $cidr, ?string $reason, $expiresAt): IpBlock
    {
        $this->agent->call('ip.block.write', [
            'username' => $account->username,
            'cidr' => $cidr,
        ]);

        return DB::transaction(fn () => IpBlock::create([
            'account_id' => $account->id,
            'cidr' => $cidr,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]));
    }

    public function unblockIp(IpBlock $block): void
    {
        $this->agent->call('ip.block.remove', [
            'username' => $block->account->username,
            'cidr' => $block->cidr,
        ]);

        $block->delete();
    }

    public function protect(Account $account, string $relativePath, string $realm, string $username, string $password): DirectoryProtection
    {
        $absolute = $this->absolutePath($account, $relativePath);

        $this->agent->call('dir.protect.write', [
            'username' => $account->username,
            'path' => $absolute,
            'realm' => $realm,
            'user' => $username,
            'password' => $password,
        ]);

        return DB::transaction(fn () => DirectoryProtection::updateOrCreate(
            ['account_id' => $account->id, 'path' => $relativePath],
            ['realm' => $realm, 'username' => $username, 'status' => 'active'],
        ));
    }

    public function unprotect(DirectoryProtection $protection): void
    {
        $this->agent->call('dir.protect.remove', [
            'username' => $protection->account->username,
            'path' => $this->absolutePath($protection->account, $protection->path),
        ]);

        $protection->delete();
    }

    /**
     * A leading slash means "relative to your home directory", which is how the
     * form reads, so /public_html/admin becomes /home/acme/public_html/admin.
     * `..` is the only way out and is refused.
     */
    public function absolutePath(Account $account, string $relative): string
    {
        $clean = '/'.trim(preg_replace('#/+#', '/', $relative), '/');

        if (str_contains($clean, '..')) {
            throw new RuntimeException('The path may not point outside your account.');
        }

        return rtrim($account->home(), '/').$clean;
    }
}
