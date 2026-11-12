<x-app-layout title="Overview">
    @php
        $firstName = explode(' ', auth()->user()->name)[0];
        $activeLicense = $primaryLicense;
        $activeCount = $licenses->filter(fn ($l) => $l->effectiveStatus()->allowsFullOperation())->count();
    @endphp

    <div class="page-head">
        <div>
            <h1>Welcome back, {{ $firstName }}</h1>
            <p>Your licences, servers and the next step to get a panel online.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('customer.servers.create') }}" class="btn btn-secondary"><x-icon name="plus" :size="15" /> Register server</a>
            <a href="{{ route('docs') }}#install" class="btn btn-primary"><x-icon name="terminal" :size="15" /> Install guide</a>
        </div>
    </div>

    @if ($licenses->isEmpty())
        <x-empty icon="key" title="No licence yet"
                 description="Licences are issued per server. Once billing is live you can purchase here; for now the staff console issues them.">
            <a href="{{ route('pricing') }}" class="btn btn-primary btn-sm">See editions</a>
        </x-empty>
    @else
        <div class="stat-grid mb-3">
            <x-stat label="Active licences" :value="$activeCount" :meta="$licenses->count().' total'" />
            <x-stat label="Servers" :value="$servers->count()" :meta="$servers->where('status', 'online')->count().' reporting online'" />
            <x-stat label="Edition" :value="$activeLicense?->plan->name ?? '—'"
                    :meta="$activeLicense?->effectiveStatus()->label()" />
            <x-stat label="Renews in"
                    :value="$activeLicense?->daysUntilExpiry() !== null ? $activeLicense->daysUntilExpiry().' d' : '∞'"
                    :meta="$activeLicense?->expires_at?->format('d M Y') ?? 'Lifetime licence'" />
        </div>

        @if ($activeLicense)
            <div class="card mb-3">
                <div class="card-head">
                    <div class="row" style="gap:10px">
                        <h2>Primary licence</h2>
                        <x-status-badge :license="$activeLicense" />
                    </div>
                    <a href="{{ route('customer.licenses.show', $activeLicense) }}" class="btn btn-ghost btn-sm">Details <x-icon name="chevron-right" :size="14" /></a>
                </div>

                <div class="card-body">
                    <div class="grid cols-2" style="gap:24px">
                        <div>
                            <div class="eyebrow mb-1">Licence key</div>
                            <div class="row" style="gap:8px">
                                <code class="mono" style="font-size:.92rem;letter-spacing:.02em">{{ $activeLicense->maskedKey() }}</code>
                                <a href="{{ route('customer.licenses.show', $activeLicense) }}" class="btn btn-secondary btn-sm">Show key</a>
                            </div>
                            <div class="hint">One key covers one server. The full key lives on the licence detail page.</div>
                        </div>

                        <div>
                            <div class="eyebrow mb-1">Plan</div>
                            <div class="row between">
                                <div>
                                    <div class="cell-strong">{{ $activeLicense->plan->name }}</div>
                                    <div class="small muted">Issued {{ $activeLicense->created_at->format('d M Y') }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="num">{{ number_format((int) $activeLicense->plan->entitlement('max_accounts')) === '-1' ? 'Unlimited' : $activeLicense->plan->entitlement('max_accounts') }}</div>
                                    <div class="small muted">accounts</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="divider" style="margin:18px 0">

                    <div class="eyebrow mb-1">Install command</div>
                    <div class="codeblock">
                        <span class="comment"># on your VPS, as root — paste your key where shown</span><br>
                        <span class="prompt">$</span> curl -fsSL {{ url('/install.sh') }} -o install.sh<br>
                        <span class="prompt">$</span> sudo bash install.sh --license=<span class="comment">ATLS-XXXX-XXXX-XXXX-XXXX</span>
                    </div>
                    <p class="hint">
                        The full key is never printed in listings. Open the licence detail page, reveal and copy it, then paste it into the command.
                    </p>
                </div>
            </div>
        @endif
    @endif

    <div class="card">
        <div class="card-head">
            <h2>Servers</h2>
            <a href="{{ route('customer.servers.index') }}" class="btn btn-ghost btn-sm">View all</a>
        </div>

        @if ($servers->isEmpty())
            <div class="card-body">
                <x-empty icon="server" title="No servers registered"
                         description="Register the VPS hostname you plan to install on. The licence binds to it during installation.">
                    <a href="{{ route('customer.servers.create') }}" class="btn btn-primary btn-sm">Register a server</a>
                </x-empty>
            </div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>OS</th>
                            <th>Licence</th>
                            <th>Status</th>
                            <th>Last heartbeat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers->take(5) as $server)
                            <tr>
                                <td>
                                    <span class="cell-strong">{{ $server->hostname }}</span>
                                    <span class="cell-sub mono">{{ $server->ip_address ?? 'IP pending' }}</span>
                                </td>
                                <td>{{ $server->os ?? '—' }}</td>
                                <td>{{ $server->license?->plan->name ?? '—' }}</td>
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
