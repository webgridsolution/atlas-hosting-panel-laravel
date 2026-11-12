<?php

namespace App\Http\View\Composers;

use App\Domain\Licensing\LicenseManager;
use App\Models\Account;
use App\Models\BandwidthDay;
use App\Models\LoginEvent;
use Illuminate\View\View;

/**
 * The strip across the top of the customer panel: who you are, which domain is
 * primary, where the files live, and the four counters a hosting customer opens
 * the panel to see.
 *
 * It is a composer rather than a block in the layout because the layout renders
 * on every screen, and the numbers cost four queries. Doing it here keeps them
 * to one place that can be optimised — and, more importantly, makes it
 * impossible for a screen to show one set of numbers while the counters show
 * another.
 */
class PanelBarComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();

        // WHM and the reseller area have their own headers; the strip is the
        // customer's view of their own account.
        if (! $user || $user->hasRole('admin') || $user->hasRole('reseller')) {
            return;
        }

        $account = $user->account();

        if (! $account instanceof Account) {
            return;
        }

        $today = now()->format('Y-m');
        $monthMb = (int) BandwidthDay::where('account_id', $account->id)
            ->where('day', 'like', $today.'%')
            ->sum(\DB::raw('http_mb + mail_mb + ftp_mb'));

        $lastLogin = LoginEvent::where('account_id', $account->id)
            ->where('successful', true)
            ->latest('id')
            ->first();

        $view->with('panelBar', [
            'username' => $account->username,
            'primary_domain' => $account->primaryDomain()?->domain ?? $account->domain,
            'ip' => config('atlas.server.ip') ?: request()->server('SERVER_ADDR') ?: request()->getHost(),
            // The account's home, not its document root: cPanel's info bar shows
            // where the shell lands, and the document root is on the Websites screen.
            'home' => $account->home(),
            'last_login_at' => $lastLogin?->created_at,
            'last_login_ip' => $lastLogin?->ip,
            'domains' => $account->domains()->count(),
            'databases' => $account->databases()->count(),
            'bandwidth_mb' => $monthMb,
            'quota_mb' => (int) $account->disk_mb,
            'licence' => app(LicenseManager::class)->evaluate()['state'],
        ]);
    }
}
