<?php

namespace App\Http\Controllers\Reseller;

use App\Domain\Licensing\LicenseManager;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly LicenseManager $licences) {}

    public function index(): View
    {
        $accounts = Account::visibleTo(request()->user());

        return view('reseller.dashboard', [
            'licence' => $this->licences->evaluate(),
            'stats' => [
                'accounts' => (clone $accounts)->count(),
                'active' => (clone $accounts)->where('status', Account::STATUS_ACTIVE)->count(),
                'suspended' => (clone $accounts)->where('status', Account::STATUS_SUSPENDED)->count(),
                'disk_mb' => (int) (clone $accounts)->sum('disk_mb'),
            ],
            'recent' => (clone $accounts)->with(['package', 'domains'])->latest()->limit(8)->get(),
        ]);
    }
}
