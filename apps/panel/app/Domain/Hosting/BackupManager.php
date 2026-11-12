<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use App\Models\Backup;
use Throwable;

class BackupManager
{
    public function __construct(private readonly Audit $audit) {}

    public function run(Account $account, bool $includeDatabases = true): Backup
    {
        $backup = $account->backups()->create([
            'kind' => $includeDatabases ? 'account' : 'account',
            'target' => $account->username,
            'status' => Backup::STATUS_RUNNING,
        ]);

        try {
            $result = $this->audit->dispatch('backup.run', [
                'username' => $account->username,
                'destination' => config('atlas.backups.destination'),
                'include_databases' => $includeDatabases,
            ], $account);

            $backup->update([
                'archive' => $result['archive'] ?? null,
                'bytes' => $result['bytes'] ?? null,
                'status' => Backup::STATUS_COMPLETE,
            ]);
        } catch (Throwable $e) {
            $backup->update(['status' => Backup::STATUS_FAILED, 'detail' => $e->getMessage()]);

            throw $e;
        }

        return $backup->refresh();
    }

    public function restore(Backup $backup): void
    {
        if ($backup->status !== Backup::STATUS_COMPLETE || ! $backup->archive) {
            throw new \RuntimeException('Only a completed backup can be restored.');
        }

        $this->audit->dispatch('backup.restore', [
            'username' => $backup->account->username,
            'archive' => $backup->archive,
        ], $backup->account);

        $backup->update(['restored_at' => now()]);
    }
}
