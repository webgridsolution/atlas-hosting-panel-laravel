<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\DnsManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\StoreDnsRecordRequest;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class DnsController extends Controller
{
    use ResolvesAccount;

    public function __construct(private readonly DnsManager $dns) {}

    public function index(): View
    {
        $account = $this->requireAccount();

        if (! config('atlas.dns.enabled')) {
            abort(404);
        }

        $zones = DnsZone::whereIn('domain', $account->domains()->pluck('domain'))
            ->with('records')
            ->orderBy('domain')
            ->get();

        return view('panel.dns.index', [
            'account' => $account,
            'zones' => $zones,
            'nameservers' => config('atlas.dns.nameservers'),
        ]);
    }

    public function store(StoreDnsRecordRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $zone = DnsZone::findOrFail($request->integer('zone_id'));

        $this->authorize('update', $zone);

        try {
            $this->dns->addRecord(
                $zone,
                (string) $request->input('name'),
                (string) $request->input('type'),
                (string) $request->input('content'),
                (int) $request->input('ttl', 3600),
                $request->filled('priority') ? (int) $request->input('priority') : null,
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The record was not saved: '.$e->getMessage());
        }

        return back()->with('status', 'DNS record added and published.');
    }

    public function destroy(DnsRecord $record): RedirectResponse
    {
        $this->authorize('update', $record->zone);

        try {
            $this->dns->deleteRecord($record);
        } catch (Throwable $e) {
            return back()->with('error', 'The record was not removed: '.$e->getMessage());
        }

        return back()->with('status', 'DNS record removed.');
    }
}
