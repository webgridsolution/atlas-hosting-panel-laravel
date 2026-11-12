<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\Metrics;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Models\Backup;
use Illuminate\View\View;

class StatsController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly Metrics $metrics) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        return view('panel.stats.index', [
            'account' => $account,
            'usage' => $this->metrics->accountUsage($account),
            'server' => $this->metrics->server(),
            'lastBackup' => $account->backups()->where('status', Backup::STATUS_COMPLETE)->latest()->first(),
        ]);
    }
}
