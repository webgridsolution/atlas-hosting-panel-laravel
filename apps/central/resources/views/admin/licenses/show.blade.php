{{-- The operator looks for a licence by the key the customer quotes on the
     phone, not by its ULID; the identifier stays visible underneath as
     reference. --}}
<x-app-layout :title="$license->key"
              :crumbs="[['label' => 'Licences', 'url' => route('admin.licenses.index')], ['label' => $license->key]]">
    @php $status = $license->effectiveStatus(); @endphp

    <div class="page-head">
        <div>
            <h1 class="mono" style="font-size:1.35rem">{{ $license->key }}</h1>
            <p class="row" style="gap:10px;margin-top:8px">
                <x-status-badge :license="$license" />
                <span class="small muted">{{ $license->customer->user->name }} · {{ $license->plan->name }}</span>
            </p>
        </div>
    </div>

    @if ($revealedKey)
        <x-alert variant="warn" title="Copy this key now" class="mb-2">
            It will not be shown again. <span class="mono" style="font-weight:600">{{ $revealedKey }}</span>
        </x-alert>
    @endif

    <div class="grid cols-3 mb-3">
        <div class="card card-pad">
            <div class="eyebrow">Licence key</div>
            <div class="row mt-1" style="gap:8px">
                <code class="mono small">
                    <span data-masked>{{ $license->maskedKey() }}</span>
                    <span data-revealed hidden>{{ $license->key }}</span>
                </code>
                <button type="button" class="btn btn-ghost btn-sm" data-reveal-toggle>Reveal</button>
            </div>
            <div class="hint">Stored for activation matching; masked in all listings.</div>
        </div>

        <div class="card card-pad">
            <div class="eyebrow">Fingerprint</div>
            <div class="mt-1 mono small">{{ $license->fingerprint_hash ? substr($license->fingerprint_hash, 0, 24).'…' : 'Not activated' }}</div>
            <div class="hint">{{ $license->activated_at ? 'Activated '.$license->activated_at->diffForHumans() : 'Awaiting first activation' }}</div>
        </div>

        <div class="card card-pad">
            <div class="eyebrow">Expiry</div>
            <div class="mt-1">{{ $license->expires_at?->format('d M Y') ?? 'Lifetime' }}</div>
            <div class="hint">{{ $license->grace_days }}-day grace · {{ $license->daysUntilExpiry() !== null ? $license->daysUntilExpiry().' days left' : 'no expiry' }}</div>
        </div>
    </div>

    <div class="grid cols-2" style="gap:16px;align-items:start">
        <div class="card">
            <div class="card-head"><h2>Change status</h2></div>
            <form method="POST" action="{{ route('admin.licenses.status', $license) }}" class="card-body"
                  data-confirm="Change licence status? This affects the customer's panel capabilities.">
                @csrf
                @method('PATCH')

                <x-select label="New status" name="status" :selected="$license->status->value" :options="[
                    'active' => 'Active',
                    'suspended' => 'Suspended (read-only, sites keep running)',
                    'revoked' => 'Revoked (terminal — requires support review)',
                ]" />

                <div class="mt-2">
                    <x-textarea label="Reason (required, audited)" name="reason" rows="3"
                                hint="Written to the audit log with your user id. Be specific." />
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">Apply status</button>
                </div>
            </form>
        </div>

        <div class="stack" style="--stack:16px">
            <div class="card">
                <div class="card-head"><h2>Entitlements</h2><span class="small muted">{{ $license->plan->name }}</span></div>
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            @foreach ($license->plan->entitlements as $entitlement)
                                @php $feature = \App\Models\Feature::where('key', $entitlement->feature_key)->first(); @endphp
                                <tr>
                                    <td class="cell-strong">{{ $feature->label ?? $entitlement->feature_key }}</td>
                                    <td class="text-right num">{{ $feature ? \App\Models\Feature::formatValue($feature->type, $entitlement->value) : $entitlement->value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Bound servers</h2></div>
                @if ($license->servers->isEmpty())
                    <div class="card-body small muted">No server has activated this licence.</div>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <tbody>
                                @foreach ($license->servers as $server)
                                    <tr>
                                        <td>
                                            <span class="cell-strong mono">{{ $server->hostname }}</span>
                                            <span class="cell-sub">{{ $server->os ?? 'OS unknown' }}</span>
                                        </td>
                                        <td class="text-right"><span class="badge {{ $server->statusBadge() }}">{{ ucfirst($server->status) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
