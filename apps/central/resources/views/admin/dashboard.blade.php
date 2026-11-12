<x-app-layout title="Control plane" :crumbs="[['label' => 'Dashboard']]">
    @php
        $maxPlan = max(1, $planBreakdown->max('count') ?? 1);
        $active = $licenseCounts[\App\Enums\LicenseStatus::Active->value] ?? 0;
        $pending = $licenseCounts[\App\Enums\LicenseStatus::PendingActivation->value] ?? 0;
        $expired = ($licenseCounts[\App\Enums\LicenseStatus::Expired->value] ?? 0) + ($licenseCounts[\App\Enums\LicenseStatus::Grace->value] ?? 0);
    @endphp

    <div class="page-head">
        <div>
            <h1>Control plane</h1>
            <p>Commercial state of the product: customers, licences, servers and revenue.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('admin.licenses.create') }}" class="btn btn-secondary"><x-icon name="plus" :size="15" /> Issue licence</a>
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary"><x-icon name="box" :size="15" /> New plan</a>
        </div>
    </div>

    <div class="stat-grid mb-3">
        <x-stat label="Customers" :value="number_format($customers)" meta="Registered accounts" />
        <x-stat label="Active licences" :value="number_format($active)" :meta="$pending.' pending activation'" />
        <x-stat label="MRR (INR)" :value="'₹'.number_format($mrrMinor / 100)" meta="Monthly-equivalent, active plans" />
        <x-stat label="Servers online" :value="$serversOnline.' / '.$serversTotal" meta="Heartbeat within 26h" />
        <x-stat label="Expiring / grace" :value="number_format($expired)" meta="Needs attention" />
    </div>

    <div class="grid cols-3" style="gap:16px;align-items:start">
        <div class="card" style="grid-column:span 2">
            <div class="card-head">
                <h2>Recent licences</h2>
                <a href="{{ route('admin.licenses.index') }}" class="btn btn-ghost btn-sm">All licences</a>
            </div>

            @if ($recentLicenses->isEmpty())
                <div class="card-body">
                    <x-empty icon="key" title="No licences issued" description="Issue a licence to a customer to see it here." />
                </div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Customer</th><th>Plan</th><th>Status</th><th>Issued</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($recentLicenses as $license)
                                <tr>
                                    <td>
                                        <span class="cell-strong">{{ $license->customer->user->name }}</span>
                                        <span class="cell-sub">{{ $license->customer->user->email }}</span>
                                    </td>
                                    <td>{{ $license->plan->name }}</td>
                                    <td><x-status-badge :license="$license" /></td>
                                    <td class="num">{{ $license->created_at->format('d M') }}</td>
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

        <div class="stack" style="--stack:16px">
            <div class="card">
                <div class="card-head"><h2>Plan mix</h2></div>
                <div class="card-body stack" style="--stack:12px">
                    @foreach ($planBreakdown as $row)
                        <div>
                            <div class="row between small">
                                <span>{{ $row['name'] }}</span>
                                <span class="mono muted">{{ $row['count'] }}</span>
                            </div>
                            <div style="height:6px;border-radius:99px;background:var(--surface-3);margin-top:5px;overflow:hidden">
                                <div style="height:100%;width:{{ max(4, round(($row['count'] / $maxPlan) * 100)) }}%;background:var(--brand);border-radius:99px"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Expiring in 30 days</h2></div>
                @if ($expiringSoon->isEmpty())
                    <div class="card-body small muted">Nothing expiring — good.</div>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <tbody>
                                @foreach ($expiringSoon as $license)
                                    <tr>
                                        <td>
                                            <span class="cell-strong">{{ $license->customer->user->name }}</span>
                                            <span class="cell-sub">{{ $license->plan->name }}</span>
                                        </td>
                                        <td class="num text-right">{{ $license->expires_at->format('d M') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-head">
            <h2>Recently registered servers</h2>
            <a href="{{ route('admin.servers.index') }}" class="btn btn-ghost btn-sm">All servers</a>
        </div>
        @if ($recentServers->isEmpty())
            <div class="card-body"><div class="small muted">No servers registered yet.</div></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Hostname</th><th>Customer</th><th>OS</th><th>Status</th><th>Heartbeat</th></tr></thead>
                    <tbody>
                        @foreach ($recentServers as $server)
                            <tr>
                                <td class="cell-strong mono">{{ $server->hostname }}</td>
                                <td>{{ $server->customer->user->name }}</td>
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
