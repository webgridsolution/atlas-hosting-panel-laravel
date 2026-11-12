<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Models\BandwidthDay;
use App\Models\LoginEvent;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Usage, bandwidth and the login log. These read from the agent and from what
 * the panel already recorded; nothing here is invented.
 */
class ObservabilityController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly Agent $agent) {}

    public function bandwidth(): View
    {
        $account = $this->requireAccount();

        try {
            $report = $this->agent->call('bandwidth.report', [
                'username' => $account->username,
                'days' => 30,
            ]);
        } catch (AgentException) {
            $report = ['days' => [], 'total_mb' => 0];
        }

        // Persist the rollup so the panel has history even when the log rotates.
        foreach (($report['days'] ?? []) as $day) {
            $date = $day['day'] ?? null;

            if (! $date || ! strtotime((string) $date)) {
                continue;
            }

            BandwidthDay::updateOrCreate(
                ['account_id' => $account->id, 'day' => Carbon::parse($date)->toDateString()],
                [
                    'http_mb' => (int) ($day['http_mb'] ?? 0),
                    'mail_mb' => (int) ($day['mail_mb'] ?? 0),
                    'ftp_mb' => (int) ($day['ftp_mb'] ?? 0),
                ],
            );
        }

        $history = $account->bandwidthDays()->orderBy('day')->get();

        return view('panel.usage.bandwidth', [
            'account' => $account,
            'days' => $history,
            'total' => (int) $history->sum(fn (BandwidthDay $d) => $d->totalMb()),
            'limit' => $account->bandwidth_mb,
            'peak' => (int) $history->max(fn (BandwidthDay $d) => $d->totalMb()) ?: 1,
        ]);
    }

    public function disk(): View
    {
        $account = $this->requireAccount();

        try {
            $usage = $this->agent->call('disk.usage', [
                'username' => $account->username,
                'path' => $account->home(),
            ]);
        } catch (AgentException) {
            $usage = [];
        }

        $used = (int) ($usage['used_mb'] ?? 0);
        $limit = (int) ($usage['limit_mb'] ?? $account->disk_mb);

        return view('panel.usage.disk', [
            'account' => $account,
            'metric' => [
                'disk_used_mb' => $used,
                'disk_limit_mb' => $limit,
                'disk_pct' => $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0,
                'inodes_used' => (int) ($usage['inodes_used'] ?? 0),
                'inodes_limit' => (int) ($usage['inodes_limit'] ?? $account->inodes),
            ],
            'children' => array_values((array) ($usage['entries'] ?? [])),
        ]);
    }

    public function logins(): View
    {
        $account = $this->requireAccount();

        return view('panel.usage.logins', [
            'account' => $account,
            'events' => LoginEvent::where('account_id', $account->id)
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
    }
}
