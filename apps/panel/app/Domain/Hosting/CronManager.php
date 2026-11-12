<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use App\Models\CronJob;

/**
 * The agent installs the account's whole crontab in one call, so every change
 * re-sends the complete list. That keeps the machine and the panel from
 * drifting, which is the usual failure mode of per-line cron editing.
 */
class CronManager
{
    public function __construct(private readonly Audit $audit) {}

    public function add(Account $account, string $schedule, string $command, ?string $description = null): CronJob
    {
        $job = $account->cronJobs()->create([
            'schedule' => trim($schedule),
            'command' => trim($command),
            'description' => $description,
        ]);

        $this->sync($account);

        return $job;
    }

    public function delete(CronJob $job): void
    {
        $account = $job->account;
        $job->delete();
        $this->sync($account);
    }

    public function sync(Account $account): void
    {
        $entry = $account->cronJobs()->orderBy('id')->get()
            ->map(fn (CronJob $j) => $j->schedule.' '.$j->command)
            ->implode("\n");

        $this->audit->dispatch('cron.set', [
            'username' => $account->username,
            'entry' => $entry,
        ], $account);
    }
}
