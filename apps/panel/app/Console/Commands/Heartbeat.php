<?php

namespace App\Console\Commands;

use App\Domain\Agent\Agent;
use App\Domain\Licensing\LicenseManager;
use App\Models\Backup;
use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Console\Command;
use Throwable;

/**
 * One small command the scheduler runs: refresh the licence, record the agent's
 * health, and flag certificates that are close to expiry.
 */
class Heartbeat extends Command
{
    protected $signature = 'atlas:heartbeat {--skip-licence : Do not contact the vendor}';

    protected $description = 'Refresh the licence, check the agent and flag expiring certificates';

    public function handle(LicenseManager $licences, Agent $agent): int
    {
        $this->line('Agent: '.($agent->healthy() ? 'up' : 'DOWN'));

        if (! $agent->healthy()) {
            Setting::put('agent_last_seen', null);
        } else {
            Setting::put('agent_last_seen', now()->toIso8601String());
        }

        if (! $this->option('skip-licence')) {
            $licences->refresh();
            $state = $licences->evaluate();
            $this->line('Licence: '.$state['state'].' — '.$state['reason']);
        }

        $expiring = Domain::where('ssl_status', Domain::SSL_ACTIVE)
            ->whereNotNull('ssl_expires_at')
            ->where('ssl_expires_at', '<', now()->addDays(14))
            ->count();

        if ($expiring > 0) {
            $this->warn("{$expiring} certificate(s) expire within 14 days.");
        }

        // Anything left "running" after a restart is stale: the agent call that
        // owned it is gone, so the row would otherwise stay spinning for ever.
        $stale = Backup::where('status', Backup::STATUS_RUNNING)
            ->where('created_at', '<', now()->subHours(2))
            ->update(['status' => Backup::STATUS_FAILED, 'detail' => 'Interrupted: the panel restarted during the backup.']);

        if ($stale > 0) {
            $this->warn("Marked {$stale} interrupted backup(s) as failed.");
        }

        try {
            cache()->forget('metrics:server');
        } catch (Throwable) {
            // Cache failures are never fatal here.
        }

        return self::SUCCESS;
    }
}
