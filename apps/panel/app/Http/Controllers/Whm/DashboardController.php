<?php

namespace App\Http\Controllers\Whm;

use App\Domain\Hosting\Metrics;
use App\Domain\Licensing\LicenseManager;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Domain;
use App\Models\HostingDatabase;
use App\Models\Mailbox;
use App\Models\Operation;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly LicenseManager $licences,
    ) {}

    public function index(): View
    {
        return view('whm.dashboard', [
            'server' => $this->metrics->server(),
            'licence' => $this->licences->evaluate(),
            'counts' => [
                'accounts' => Account::count(),
                'suspended' => Account::where('status', Account::STATUS_SUSPENDED)->count(),
                'domains' => Domain::count(),
                'databases' => HostingDatabase::count(),
                'mailboxes' => Mailbox::count(),
            ],
            'recent' => Operation::with(['user', 'account'])->recent()->limit(8)->get(),
        ]);
    }
}
