<x-app-layout title="Licence" :crumbs="[['label' => 'Licences', 'url' => route('customer.licenses.index')], ['label' => $license->public_id]]">
    @php
        $status = $license->effectiveStatus();
        $features = $license->plan->entitlements->sortBy('feature_key');
    @endphp

    <div class="page-head">
        <div>
            <h1 class="mono" style="font-size:1.3rem">{{ $license->public_id }}</h1>
            <p class="row" style="gap:10px;margin-top:8px">
                <x-status-badge :license="$license" />
                <span class="small muted">{{ $license->plan->name }} · issued {{ $license->created_at->format('d M Y') }}</span>
            </p>
        </div>
        <div class="page-actions">
            <a href="{{ route('customer.servers.create') }}" class="btn btn-secondary"><x-icon name="plug" :size="15" /> Attach server</a>
        </div>
    </div>

    <div class="grid cols-3 mb-3">
        <div class="card card-pad">
            <div class="eyebrow">Licence key</div>
            <div class="row mt-1" style="gap:8px">
                <code class="mono" style="font-size:.86rem">
                    <span data-masked>{{ $license->maskedKey() }}</span>
                    <span data-revealed hidden>{{ $license->key }}</span>
                </code>
                <button type="button" class="btn btn-ghost btn-sm" data-reveal-toggle>Reveal</button>
            </div>
            <p class="hint">Treat this key like a password. Anyone with it and your server fingerprint could attempt activation.</p>
        </div>

        <div class="card card-pad">
            <div class="eyebrow">Expiry</div>
            <div class="stat-value" style="font-size:1.5rem">{{ $license->expires_at?->format('d M Y') ?? 'Never' }}</div>
            <p class="hint">
                @if ($license->daysUntilExpiry() === null)
                    Lifetime licence — no renewal required.
                @elseif ($license->daysUntilExpiry() < 0)
                    Expired. Grace period ends {{ $license->expires_at->copy()->addDays($license->grace_days)->format('d M Y') }}.
                @else
                    {{ $license->daysUntilExpiry() }} days remaining · {{ $license->grace_days }}-day grace window.
                @endif
            </p>
        </div>

        <div class="card card-pad">
            <div class="eyebrow">Activation</div>
            <div class="stat-value" style="font-size:1.5rem">{{ $license->servers->count() }} / {{ $license->activation_limit }}</div>
            <p class="hint">
                {{ $license->fingerprint_hash ? 'Fingerprint bound to '.substr($license->fingerprint_hash, 0, 12).'…' : 'Not yet activated on a server.' }}
            </p>
        </div>
    </div>

    <div class="grid cols-2" style="gap:16px;align-items:start">
        <div class="card">
            <div class="card-head"><h2>Entitlements</h2><span class="small muted">from plan</span></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Feature</th><th class="text-right">Value</th></tr></thead>
                    <tbody>
                        @forelse ($features as $entitlement)
                            @php $feature = \App\Models\Feature::where('key', $entitlement->feature_key)->first(); @endphp
                            <tr>
                                <td class="cell-strong">{{ $feature->label ?? $entitlement->feature_key }}</td>
                                <td class="text-right num">{{ $feature ? \App\Models\Feature::formatValue($feature->type, $entitlement->value) : $entitlement->value }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="muted">No entitlements recorded for this plan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Bound servers</h2></div>
            @if ($license->servers->isEmpty())
                <div class="card-body">
                    <x-empty icon="server" title="No server bound" description="Run the installer with this key to bind a server.">
                        <a href="{{ route('docs') }}#install" class="btn btn-secondary btn-sm">Read install guide</a>
                    </x-empty>
                </div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Hostname</th><th>Status</th><th>Heartbeat</th></tr></thead>
                        <tbody>
                            @foreach ($license->servers as $server)
                                <tr>
                                    <td>
                                        <span class="cell-strong">{{ $server->hostname }}</span>
                                        <span class="cell-sub">{{ $server->os ?? 'OS unknown' }}</span>
                                    </td>
                                    <td><span class="badge {{ $server->statusBadge() }}">{{ ucfirst($server->status) }}</span></td>
                                    <td class="num">{{ $server->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if ($license->subscription)
        <div class="card mt-3">
            <div class="card-head">
                <h2>Renewal</h2>
                <span class="small muted">{{ ucfirst(str_replace('_', ' ', $license->subscription->interval)) }} · ends {{ $license->subscription->current_period_end->format('d M Y') }}</span>
            </div>
            <div class="card-body row between wrap" style="gap:16px;align-items:center">
                <p class="small muted" style="max-width:52ch;margin:0">
                    Paying now extends the current period instead of starting a new licence. If the licence lapses,
                    the panel becomes read-only after the grace window — websites, mail and DNS keep working, and nothing is deleted.
                </p>
                <form method="POST" action="{{ route('billing.renew', $license) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Renew this licence</button>
                </form>
            </div>
        </div>
    @else
        <div class="alert alert-info mt-3">
            <x-icon name="alert" :size="17" style="flex:none;margin-top:1px" />
            <div>
                This licence was issued by staff rather than bought online, so it has no subscription to renew.
                Ask support to extend it. If it expires, your panel drops to read-only after the grace window — sites keep serving.
            </div>
        </div>
    @endif
</x-app-layout>
