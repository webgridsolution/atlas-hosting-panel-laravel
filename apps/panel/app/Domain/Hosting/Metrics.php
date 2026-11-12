<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentException;
use App\Models\Account;
use Illuminate\Support\Facades\Cache;

/**
 * Machine and account metrics. Server stats are cached for 20 seconds because
 * a dashboard refresh should not mean a spawn of `statfs` per keystroke, and
 * the agent is the only thing allowed to read /proc anyway.
 */
class Metrics
{
    public function __construct(private readonly Agent $agent) {}

    /** @return array<string, mixed>|null */
    public function server(): ?array
    {
        return Cache::remember('metrics:server', 20, function () {
            try {
                return $this->agent->call('system.stats');
            } catch (AgentException) {
                return null;
            }
        });
    }

    /**
     * Per-account usage derived from what the panel already knows. Disk usage
     * beyond the quota is reported by the agent on the WHM screen; the panel
     * shows configured limits here so a customer always sees the same numbers
     * their cPanel expects.
     *
     * @return array<string, mixed>
     */
    public function accountUsage(Account $account): array
    {
        $usedDisk = Cache::remember('usage:'.$account->id.':disk', 120, function () use ($account) {
            try {
                $stats = $this->agent->call('system.stats');
                $perAccount = $stats['accounts_usage'] ?? [];

                return (int) ($perAccount[$account->username]['disk_mb'] ?? 0);
            } catch (AgentException) {
                return 0;
            }
        });

        return [
            'disk_used_mb' => $usedDisk,
            'disk_limit_mb' => $account->disk_mb,
            'disk_pct' => $account->disk_mb > 0 ? min(100, round($usedDisk / $account->disk_mb * 100)) : 0,
            'inodes_used' => (int) Cache::get('usage:'.$account->id.':inodes', 0),
            'inodes_limit' => $account->inodes,
            'bandwidth_mb' => $account->bandwidth_mb,
            'domains' => $account->domains()->count(),
            'databases' => $account->databases()->count(),
            'mailboxes' => $account->mailboxes()->count(),
        ];
    }

    public function flushAccount(Account $account): void
    {
        Cache::forget('usage:'.$account->id.':disk');
        Cache::forget('usage:'.$account->id.':inodes');
        Cache::forget('metrics:server');
    }
}
