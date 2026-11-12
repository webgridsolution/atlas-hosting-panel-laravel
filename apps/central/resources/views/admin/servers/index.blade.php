<x-app-layout title="Servers" :crumbs="[['label' => 'Servers']]">
    <div class="page-head">
        <div>
            <h1>Servers</h1>
            <p>Customer VPS registrations and their heartbeat state. A server counts as online when its panel reports within 26 hours.</p>
        </div>
    </div>

    <div class="row wrap mb-2" style="gap:6px">
        <a href="{{ route('admin.servers.index') }}" class="chip" style="font-weight:{{ $status === '' ? '600' : '400' }}">All</a>
        @foreach (['online' => 'Online', 'pending' => 'Pending', 'offline' => 'Offline', 'decommissioned' => 'Decommissioned'] as $value => $label)
            <a href="{{ route('admin.servers.index', ['status' => $value]) }}" class="chip"
               style="font-weight:{{ $status === $value ? '600' : '400' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($servers->isEmpty())
        <x-empty icon="server" title="No servers in this view" description="Servers appear once a customer registers one and the panel reports in." />
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Hostname</th><th>Customer</th><th>OS</th><th>Licence</th><th>Status</th><th>Heartbeat</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            <tr>
                                <td>
                                    <span class="cell-strong mono">{{ $server->hostname }}</span>
                                    <span class="cell-sub mono">{{ $server->ip_address ?? 'IP unknown' }}</span>
                                </td>
                                <td>{{ $server->customer->user->name }}</td>
                                <td>{{ $server->os ?? '—' }}</td>
                                <td>{{ $server->license?->plan->name ?? '—' }}</td>
                                <td><span class="badge {{ $server->statusBadge() }}">{{ ucfirst($server->status) }}</span></td>
                                <td class="num">{{ $server->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $servers->links() }}
        </div>
    @endif
</x-app-layout>
