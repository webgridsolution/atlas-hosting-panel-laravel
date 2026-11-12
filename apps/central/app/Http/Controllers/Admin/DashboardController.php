<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LicenseStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\License;
use App\Models\Plan;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $licenseCounts = License::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $activeLicenses = License::query()->active()->with('plan')->get();

        return view('admin.dashboard', [
            'customers' => Customer::count(),
            'licensesTotal' => License::count(),
            'licenseCounts' => $licenseCounts,
            'serversOnline' => Server::where('status', 'online')->count(),
            'serversTotal' => Server::count(),
            'mrrMinor' => $this->monthlyRecurringRevenue($activeLicenses),
            'recentLicenses' => License::with(['customer.user', 'plan'])->latest()->limit(6)->get(),
            'recentServers' => Server::with('customer.user')->latest()->limit(6)->get(),
            'planBreakdown' => $this->planBreakdown(),
            'expiringSoon' => License::query()
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(30)])
                ->whereNotIn('status', [LicenseStatus::Revoked->value, LicenseStatus::Suspended->value])
                ->with(['customer.user', 'plan'])
                ->orderBy('expires_at')
                ->limit(5)
                ->get(),
        ]);
    }

    /** Monthly-equivalent INR revenue across active licences (annual = /12, etc.). */
    private function monthlyRecurringRevenue(Collection $activeLicenses): int
    {
        return (int) $activeLicenses->sum(function (License $license) {
            $price = $license->plan->prices->firstWhere(fn ($p) => $p->currency === 'INR' && $p->interval === 'monthly');

            if ($price) {
                return $price->amount_minor;
            }

            $annual = $license->plan->prices->firstWhere(fn ($p) => $p->currency === 'INR' && $p->interval === 'annual');

            return $annual ? (int) round($annual->amount_minor / 12) : 0;
        });
    }

    private function planBreakdown(): Collection
    {
        return Plan::withCount('licenses')
            ->active()
            ->get()
            ->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'count' => $plan->licenses_count,
            ]);
    }
}
