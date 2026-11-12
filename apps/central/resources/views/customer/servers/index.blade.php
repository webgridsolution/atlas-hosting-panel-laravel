<x-app-layout title="Servers" :crumbs="[['label' => 'Servers']]">
    <div class="page-head">
        <div>
            <h1>Servers</h1>
            <p>Servers registered against your account. Installation binds a licence to the server's fingerprint.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('customer.servers.create') }}" class="btn btn-primary"><x-icon name="plus" :size="15" /> Register server</a>
        </div>
    </div>

    @if ($servers->isEmpty())
        <x-empty icon="server" title="No servers yet"
                 description="Register the hostname of your VPS so its licence activation can be tied to it.">
            <a href="{{ route('customer.servers.create') }}" class="btn btn-primary btn-sm">Register your first server</a>
        </x-empty>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>OS</th>
                            <th>Licence</th>
                            <th>Status</th>
                            <th>Last heartbeat</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            <tr>
                                <td>
                                    <span class="cell-strong mono">{{ $server->hostname }}</span>
                                    <span class="cell-sub mono">{{ $server->ip_address ?? 'IP pending' }}</span>
                                </td>
                                <td>{{ $server->os ?? '—' }}</td>
                                <td>{{ $server->license?->plan->name ?? 'Unassigned' }}</td>
                                <td>
                                    <span class="badge {{ $server->statusBadge() }}">{{ ucfirst($server->status) }}</span>
                                    @if ($server->isStale() && $server->status === 'online')
                                        <span class="cell-sub">Heartbeat stale</span>
                                    @endif
                                </td>
                                <td class="num">{{ $server->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="text-right">
                                    <form method="POST" action="{{ route('customer.servers.destroy', $server) }}"
                                          data-confirm="Decommission {{ $server->hostname }}? The licence stays active and can be attached elsewhere.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)">Decommission</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $servers->links() }}
        </div>

        <p class="small muted mt-2">
            Online status is driven by the panel's daily heartbeat. A server that has not reported in 26 hours is treated as stale.
        </p>
    @endif
</x-app-layout>
