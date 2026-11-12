<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\BackupManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Models\Backup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class BackupController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly BackupManager $backups) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.backups.index', [
            'account' => $account,
            'backups' => $account->backups()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireAccount();
        $account = $this->requireAccount();
        $data = $request->validate(['include_databases' => ['boolean']]);

        try {
            $backup = $this->backups->run($account, (bool) ($data['include_databases'] ?? true));
        } catch (Throwable $e) {
            return back()->with('error', 'The backup failed: '.$e->getMessage());
        }

        return back()->with('status', "Backup complete: {$backup->sizeLabel()}.");
    }

    public function restore(Backup $backup): RedirectResponse
    {
        $this->authorize('restore', $backup);

        try {
            $this->backups->restore($backup);
        } catch (Throwable $e) {
            return back()->with('error', 'The restore failed: '.$e->getMessage());
        }

        return back()->with('status', 'Backup restored. Review your website to confirm.');
    }
}
