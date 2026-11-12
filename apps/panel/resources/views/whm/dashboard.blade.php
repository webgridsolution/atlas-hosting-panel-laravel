@extends('layouts.app')

@section('title', 'Server overview')

@section('content')
    @php
        $memPct = ($server['mem_total_mb'] ?? 0) > 0 ? round(($server['mem_used_mb'] ?? 0) / $server['mem_total_mb'] * 100) : 0;
        $diskPct = ($server['disk_total_gb'] ?? 0) > 0 ? round((1 - ($server['disk_free_gb'] ?? 0) / $server['disk_total_gb']) * 100) : 0;
        $load = $server['load_avg'][0] ?? 0;
        $cpu = max(1, (int) ($server['cpu_count'] ?? 1));
    @endphp

    <div class="spread" style="margin-bottom:20px">
        <div>
            <h1>Server overview</h1>
            <p class="small muted">
                @if ($server)
                    {{ $server['hostname'] ?? 'this server' }} ·
                    {{ $server['cpu_count'] ?? '?' }} vCPU ·
                    up {{ gmdate('z\d H\h i\m', (int) ($server['uptime_seconds'] ?? 0)) }}
                @else
                    The agent is not answering, so live server statistics are unavailable.
                @endif
            </p>
        </div>
        <a class="btn primary" href="{{ route('whm.accounts.create') }}"><x-icon name="plus" :size="15" /> New account</a>
    </div>

    @unless ($server)
        <div class="alert error">
            <x-icon name="alert" :size="16" />
            <div>
                <strong>The server agent is unreachable.</strong>
                Sites continue to serve; the panel cannot change anything until the agent is back.
                Check <span class="mono">atlas-agent</span> and the journal at <span class="mono">/var/log/atlas/agent-journal.jsonl</span>.
            </div>
        </div>
    @endunless

    <div class="grid four" style="margin-bottom:18px">
        <div class="stat">
            <div class="label">Accounts</div>
            <div class="value">{{ $counts['accounts'] }}</div>
            <div class="sub">{{ $counts['suspended'] }} suspended</div>
        </div>
        <div class="stat">
            <div class="label">Domains</div>
            <div class="value">{{ $counts['domains'] }}</div>
            <div class="sub">served by Apache</div>
        </div>
        <div class="stat">
            <div class="label">Databases</div>
            <div class="value">{{ $counts['databases'] }}</div>
            <div class="sub">MariaDB</div>
        </div>
        <div class="stat">
            <div class="label">Mailboxes</div>
            <div class="value">{{ $counts['mailboxes'] }}</div>
            <div class="sub">{{ config('atlas.mail.enabled') ? 'Dovecot' : 'mail module disabled' }}</div>
        </div>
    </div>

    <div class="grid two">
        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Load</h2>
                    <p>1 / 5 / 15 minute averages</p>
                </div>
                <span class="badge {{ $load > $cpu ? 'warn' : 'ok' }}">{{ number_format($load, 2) }}</span>
            </div>

            <div class="stack">
                <div>
                    <div class="spread small"><span class="muted">Memory</span><span>{{ $server['mem_used_mb'] ?? 0 }} / {{ $server['mem_total_mb'] ?? 0 }} MB</span></div>
                    <div class="meter {{ $memPct > 90 ? 'danger' : ($memPct > 75 ? 'warn' : '') }}"><span style="width: {{ $memPct }}%"></span></div>
                </div>

                <div>
                    <div class="spread small"><span class="muted">Disk</span><span>{{ $server['disk_free_gb'] ?? 0 }} GB free of {{ $server['disk_total_gb'] ?? 0 }} GB</span></div>
                    <div class="meter {{ $diskPct > 90 ? 'danger' : ($diskPct > 75 ? 'warn' : '') }}"><span style="width: {{ $diskPct }}%"></span></div>
                </div>
            </div>

            <div class="row" style="margin-top:16px">
                <a class="btn sm" href="{{ route('whm.services.index') }}">Services</a>
                <a class="btn sm" href="{{ route('whm.audit.index') }}">Audit log</a>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Licence</h2>
                    <p>{{ $licence['reason'] }}</p>
                </div>
                <span class="badge {{ $licence['state'] === 'active' ? 'ok' : ($licence['state'] === 'grace' ? 'warn' : 'danger') }}">
                    {{ str_replace('_', ' ', $licence['state']) }}
                </span>
            </div>

            @if ($licence['days_left'] !== null)
                <p class="small">{{ $licence['days_left'] }} day(s) remaining in the current window.</p>
            @endif

            <p class="small faint">
                Read-only mode never takes websites offline and never deletes data. It stops new provisioning
                and configuration changes until the licence is renewed.
            </p>

            <a class="btn sm" href="{{ route('whm.licence.index') }}">Manage licence</a>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Recent operations</h2>
                <p>What the panel asked the agent to do, newest first.</p>
            </div>
            <a class="btn ghost sm" href="{{ route('whm.audit.index') }}">Full log</a>
        </div>

        @if ($recent->isEmpty())
            <div class="empty"><h3>Nothing yet</h3><p>Operations appear here as soon as an account is created.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>When</th><th>Operation</th><th>Account</th><th>By</th><th>Status</th><th class="right">Took</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $op)
                            <tr>
                                <td class="faint small">{{ $op->created_at?->diffForHumans() }}</td>
                                <td class="mono">{{ $op->op }}</td>
                                <td>{{ $op->account?->username ?? '—' }}</td>
                                <td class="small">{{ $op->user?->email ?? 'system' }}</td>
                                <td><span class="badge {{ $op->status === 'ok' ? 'ok' : ($op->status === 'denied' ? 'warn' : 'danger') }}">{{ $op->status }}</span></td>
                                <td class="right faint small">{{ $op->duration_ms !== null ? $op->duration_ms.' ms' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
