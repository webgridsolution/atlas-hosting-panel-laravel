<x-app-layout :title="$customer->user->name"
              :crumbs="[['label' => 'Customers', 'url' => route('admin.customers.index')], ['label' => $customer->user->name]]">
    <div class="page-head">
        <div>
            <h1>{{ $customer->user->name }}</h1>
            <p>{{ $customer->user->email }} · joined {{ $customer->created_at->format('d M Y') }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary"><x-icon name="plus" :size="15" /> Issue licence</a>
        </div>
    </div>

    <div class="grid cols-3 mb-3">
        <div class="card card-pad">
            <div class="eyebrow">Company</div>
            <div class="mt-1">{{ $customer->company_name ?? '—' }}</div>
            <div class="hint">{{ $customer->gstin ? 'GSTIN '.$customer->gstin : 'No GSTIN on record' }}</div>
        </div>
        <div class="card card-pad">
            <div class="eyebrow">Country</div>
            <div class="mt-1 mono">{{ $customer->country }}</div>
            <div class="hint">{{ $customer->phone ?? 'No phone on record' }}</div>
        </div>
        <div class="card card-pad">
            <div class="eyebrow">Account status</div>
            <div class="mt-1"><span class="badge {{ $customer->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($customer->status) }}</span></div>
            <div class="hint">Last login {{ $customer->user->last_login_at?->diffForHumans() ?? 'never' }}</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-head"><h2>Licences</h2></div>
        @if ($customer->licenses->isEmpty())
            <div class="card-body"><div class="small muted">No licences issued yet.</div></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Licence</th><th>Plan</th><th>Status</th><th>Expires</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($customer->licenses as $license)
                            <tr>
                                <td class="mono cell-strong">{{ $license->public_id }}</td>
                                <td>{{ $license->plan->name }}</td>
                                <td><x-status-badge :license="$license" /></td>
                                <td class="num">{{ $license->expires_at?->format('d M Y') ?? 'Lifetime' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.licenses.show', $license) }}" class="btn btn-secondary btn-sm">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-head"><h2>Servers</h2></div>
        @if ($customer->servers->isEmpty())
            <div class="card-body"><div class="small muted">No servers registered.</div></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Hostname</th><th>IP</th><th>OS</th><th>Status</th><th>Heartbeat</th></tr></thead>
                    <tbody>
                        @foreach ($customer->servers as $server)
                            <tr>
                                <td class="mono cell-strong">{{ $server->hostname }}</td>
                                <td class="mono">{{ $server->ip_address ?? '—' }}</td>
                                <td>{{ $server->os ?? '—' }}</td>
                                <td><span class="badge {{ $server->statusBadge() }}">{{ ucfirst($server->status) }}</span></td>
                                <td class="num">{{ $server->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
