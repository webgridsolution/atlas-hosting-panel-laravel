<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentException;
use App\Domain\Licensing\LicenseManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Models\BandwidthDay;
use App\Models\LoginEvent;
use Illuminate\View\View;

/**
 * The customer's landing page: what they can do, grouped the way the sidebar
 * is, with a live badge on each tile, plus the server state alongside.
 *
 * A tile is only offered when the module behind it is enabled — no dead
 * buttons — and the badge is a real count, not decoration.
 */
class DashboardController extends Controller
{
    use ResolvesAccount;

    public function __construct(
        private readonly Agent $agent,
        private readonly LicenseManager $licence,
    ) {}

    public function index(): View
    {
        $account = $this->account();

        if ($account === null) {
            return view('panel.no-account');
        }

        $mailOn = (bool) config('atlas.mail.enabled');
        $dnsOn = (bool) config('atlas.dns.enabled');

        $counts = [
            'domains' => $account->domains()->count(),
            'databases' => $account->databases()->count(),
            'db_users' => $account->databaseUsers()->count(),
            'mailboxes' => $mailOn ? $account->mailboxes()->count() : 0,
            'ftp' => $account->ftpAccounts()->count(),
            'cron' => $account->cronJobs()->count(),
            'backups' => $account->backups()->count(),
            'apps' => $account->applications()->count(),
        ];

        $cert = $account->certificates()->orderByDesc('expires_at')->first();
        $expiring = $account->certificates()
            ->where('expires_at', '<=', now()->addDays(14))
            ->where('expires_at', '>', now())
            ->get();

        $tiles = $this->tiles($counts, $cert, $mailOn, $dnsOn);

        return view('panel.dashboard', [
            'account' => $account,
            'licence' => $this->licence->evaluate(),
            'counts' => $counts,
            'tiles' => $tiles,
            'expiring' => $expiring,
            'usage' => $this->usage($account),
            'bandwidth' => $this->bandwidthSeries($account),
            'peak' => $this->peak($account),
            'serverInfo' => $this->serverInfo(),
            'recentLogins' => LoginEvent::where('account_id', $account->id)
                ->orderByDesc('created_at')->limit(5)->get(),
        ]);
    }

    /**
     * Group label => list of tiles. `when` gates a tile on a module.
     *
     * @param  array<string, int>  $counts
     * @return array<string, list<array<string, mixed>>>
     */
    private function tiles(array $counts, $cert, bool $mailOn, bool $dnsOn): array
    {
        return [
            'Domain' => [
                ['route' => 'panel.domains.index', 'label' => 'Domains', 'icon' => 'globe', 'badge' => (string) $counts['domains']],
                ['route' => 'panel.redirects.index', 'label' => 'Redirects', 'icon' => 'arrow-right'],
                ['route' => 'panel.dns.index', 'label' => 'DNS zone', 'icon' => 'network', 'when' => $dnsOn],
                ['route' => 'panel.websites.index', 'label' => 'Websites', 'icon' => 'server'],
            ],
            'Database' => [
                ['route' => 'panel.databases.index', 'label' => 'Databases', 'icon' => 'database', 'badge' => $counts['databases'].''],
                ['route' => 'panel.databases.users', 'label' => 'DB users', 'icon' => 'user-key', 'badge' => $counts['db_users'].''],
            ],
            'SSL' => [
                ['route' => 'panel.ssl.index', 'label' => 'Certificates', 'icon' => 'lock',
                    'badge' => $cert?->expires_at ? 'exp '.$cert->expires_at->format('j M') : null],
            ],
            'FTP' => [
                ['route' => 'panel.ftp.index', 'label' => 'FTP accounts', 'icon' => 'plug', 'badge' => $counts['ftp'].''],
            ],
            'Email' => [
                ['route' => 'panel.email.index', 'label' => 'Mailboxes', 'icon' => 'mail', 'badge' => $counts['mailboxes'].'', 'when' => $mailOn],
                ['route' => 'panel.email.policy', 'label' => 'Routing', 'icon' => 'forward', 'when' => $mailOn],
                ['route' => 'panel.email.filters', 'label' => 'Filters', 'icon' => 'filter', 'when' => $mailOn],
                ['route' => 'webmail.inbox', 'label' => 'Webmail', 'icon' => 'mail-open', 'when' => $mailOn],
            ],
            'Files and backup' => [
                ['route' => 'panel.files.index', 'label' => 'File manager', 'icon' => 'folder'],
                ['route' => 'panel.backups.index', 'label' => 'Backups', 'icon' => 'archive', 'badge' => $counts['backups'].''],
                ['route' => 'panel.dev.index', 'label' => 'Git deploy', 'icon' => 'git'],
            ],
            'Configuration' => [
                ['route' => 'panel.config.php', 'label' => 'PHP', 'icon' => 'php', 'badge' => PHP_VERSION],
                ['route' => 'panel.config.mime', 'label' => 'MIME types', 'icon' => 'file'],
                ['route' => 'panel.config.errors', 'label' => 'Error pages', 'icon' => 'alert'],
                ['route' => 'panel.cron.index', 'label' => 'Cron', 'icon' => 'clock', 'badge' => $counts['cron'].''],
            ],
            'Security' => [
                ['route' => 'panel.security.index', 'label' => 'IP & directories', 'icon' => 'shield'],
                ['route' => 'panel.security.tokens', 'label' => 'API tokens', 'icon' => 'key'],
            ],
            'Applications' => [
                ['route' => 'panel.apps.index', 'label' => 'Applications', 'icon' => 'apps', 'badge' => $counts['apps'].''],
            ],
            'Usage' => [
                ['route' => 'panel.usage.bandwidth', 'label' => 'Bandwidth', 'icon' => 'chart'],
                ['route' => 'panel.usage.disk', 'label' => 'Disk', 'icon' => 'pie'],
                ['route' => 'panel.usage.logins', 'label' => 'Logins', 'icon' => 'receipt'],
            ],
        ];
    }

    /** @return array<string, int|float> */
    private function usage($account): array
    {
        try {
            $stats = $this->agent->call('disk.usage', [
                'username' => $account->username,
                'path' => $account->home(),
            ]);
        } catch (AgentException) {
            $stats = [];
        }

        $used = (int) ($stats['used_mb'] ?? 0);
        $limit = (int) ($stats['limit_mb'] ?? $account->disk_mb);

        return [
            'disk_used_mb' => $used,
            'disk_limit_mb' => $limit,
            'disk_pct' => $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0,
            'inodes_limit' => (int) ($stats['inodes_limit'] ?? $account->inodes),
            'inodes_used' => (int) ($stats['inodes_used'] ?? 0),
        ];
    }

    /**
     * Thirty days of traffic as a dense series, so the chart has a bar for every
     * day even when a day has no row.
     *
     * @return list<array{day: string, mb: int}>
     */
    private function bandwidthSeries($account): array
    {
        $rows = $account->bandwidthDays()
            ->where('day', '>=', now()->subDays(29)->toDateString())
            ->get()
            ->keyBy(fn (BandwidthDay $d) => $d->day->toDateString());

        $series = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $series[] = ['day' => $date, 'mb' => $rows->has($date) ? $rows[$date]->totalMb() : 0];
        }

        return $series;
    }

    private function peak($account): int
    {
        return max(1, (int) $account->bandwidthDays()
            ->where('day', '>=', now()->subDays(29)->toDateString())
            ->get()
            ->max(fn (BandwidthDay $d) => $d->totalMb()));
    }

    /** @return list<array{unit: string, active: bool, state: string}> */
    private function serverInfo(): array
    {
        try {
            $stats = $this->agent->call('system.stats');
        } catch (AgentException) {
            return [];
        }

        return array_map(
            fn ($svc) => [
                'unit' => (string) ($svc['unit'] ?? '?'),
                'active' => (bool) ($svc['active'] ?? false),
                'state' => (string) ($svc['state'] ?? 'unknown'),
            ],
            (array) ($stats['services'] ?? []),
        );
    }
}
