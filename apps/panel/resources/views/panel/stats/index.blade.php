@extends('layouts.app')

@section('title', 'Usage')

@section('content')
    <h1>Usage</h1>
    <p class="small muted">Figures come from the server agent and are cached for a couple of minutes.</p>

    <div class="grid two">
        <div class="card">
            <h2>Your account</h2>

            <div class="stack">
                <div>
                    <div class="spread small"><span class="muted">Disk</span><span>{{ number_format($usage['disk_used_mb']) }} / {{ number_format($usage['disk_limit_mb']) }} MB</span></div>
                    <div class="meter {{ $usage['disk_pct'] > 90 ? 'danger' : ($usage['disk_pct'] > 75 ? 'warn' : '') }}"><span style="width: {{ $usage['disk_pct'] }}%"></span></div>
                </div>
            </div>

            <dl class="kv" style="margin-top:18px">
                <dt>Inode limit</dt><dd>{{ number_format($usage['inodes_limit']) }}</dd>
                <dt>Bandwidth</dt><dd>{{ number_format($usage['bandwidth_mb']) }} MB / month</dd>
                <dt>Websites</dt><dd>{{ $usage['domains'] }}</dd>
                <dt>Databases</dt><dd>{{ $usage['databases'] }}</dd>
                <dt>Mailboxes</dt><dd>{{ $usage['mailboxes'] }}</dd>
                <dt>Last backup</dt><dd>{{ $lastBackup?->created_at?->diffForHumans() ?? 'never' }}</dd>
            </dl>
        </div>

        <div class="card">
            <h2>Server</h2>

            @if (! $server)
                <div class="alert warn"><x-icon name="alert" :size="16" /><div>The agent is not answering, so server figures are unavailable.</div></div>
            @else
                <dl class="kv">
                    <dt>Hostname</dt><dd class="mono small">{{ $server['hostname'] ?? '—' }}</dd>
                    <dt>Load</dt><dd>{{ implode(' / ', array_map(fn ($v) => number_format((float) $v, 2), $server['load_avg'] ?? [])) }}</dd>
                    <dt>Memory</dt><dd>{{ number_format($server['mem_used_mb'] ?? 0) }} / {{ number_format($server['mem_total_mb'] ?? 0) }} MB</dd>
                    <dt>Disk free</dt><dd>{{ $server['disk_free_gb'] ?? 0 }} GB</dd>
                    <dt>CPUs</dt><dd>{{ $server['cpu_count'] ?? '—' }}</dd>
                    <dt>Uptime</dt><dd>{{ gmdate('z\d H\h i\m', (int) ($server['uptime_seconds'] ?? 0)) }}</dd>
                </dl>
            @endif
        </div>
    </div>
@endsection
