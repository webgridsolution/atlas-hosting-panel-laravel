@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    @php
        $fmtMb = function (int $mb): string {
            return $mb >= 1024 ? number_format($mb / 1024, 2).' GB' : number_format($mb).' MB';
        };
        $peak = max(1, collect($bandwidth)->max('mb') ?: 1);
    @endphp

    {{-- ---- header: who you are, and what the server is -------------------- --}}
    <div class="spread" style="margin-bottom:16px">
        <div>
            <h1 style="margin:0">{{ $account->primaryDomain()?->domain ?? $account->domain }}</h1>
            <p class="small muted" style="margin:4px 0 0">
                <span class="mono">{{ $account->username }}</span> ·
                {{ $account->package?->name ?? 'custom limits' }} ·
                PHP {{ $account->php_version }} ·
                home <span class="mono">{{ $account->home() }}</span>
            </p>
        </div>
        <div class="row" style="gap:8px">
            <a class="btn ghost sm" href="{{ route('panel.files.index') }}"><x-icon name="folder" :size="15" /> File manager</a>
            <a class="btn" href="{{ route('panel.websites.index') }}"><x-icon name="globe" :size="15" /> Websites</a>
        </div>
    </div>

    @if ($account->isSuspended())
        <div class="alert error">
            <x-icon name="lock" :size="16" />
            <div><strong>This account is suspended.</strong> Files, databases and mail are intact. Contact your provider to reactivate.</div>
        </div>
    @endif

    @if ($licence['state'] !== 'active')
        <div class="license-bar {{ $licence['state'] === 'read_only' ? 'danger' : '' }}">
            <x-icon name="alert" :size="16" />
            <span>{{ $licence['reason'] }}</span>
        </div>
    @endif

    @if ($expiring->isNotEmpty())
        <div class="alert warn">
            <x-icon name="alert" :size="16" />
            <div>
                {{ $expiring->count() }} certificate(s) expire within two weeks:
                {{ $expiring->pluck('domain')->implode(', ') }}.
                <a href="{{ route('panel.ssl.index') }}">Renew now</a>
            </div>
        </div>
    @endif

    <div class="dash-grid">
        {{-- ================= left column: the tile grid ================= --}}
        <div>
            @foreach ($tiles as $group => $items)
                @php
                    $visible = collect($items)->filter(fn ($t) => $t['when'] ?? true);
                @endphp

                @if ($visible->isNotEmpty())
                    <section class="tile-group">
                        <header>
                            <x-icon name="grid" :size="15" />
                            <h2>{{ $group }}</h2>
                            <span class="count">{{ $visible->count() }} {{ Str::plural('tool', $visible->count()) }}</span>
                        </header>

                        <div class="tile-grid">
                            @foreach ($visible as $tile)
                                <a class="tile" href="{{ route($tile['route']) }}">
                                    {{-- The count rides on the icon: a chip in the tile's
                                         corner collides with a 42px icon in a 132px tile. --}}
                                    <span class="glyph">
                                        <x-icon :name="$tile['icon']" :size="21" />
                                        @if (! empty($tile['badge']))
                                            <span class="tile-badge">{{ $tile['badge'] }}</span>
                                        @endif
                                    </span>
                                    <span class="name">{{ $tile['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endforeach
        </div>

        {{-- ================= right column: live server state ============ --}}
        <aside style="display:flex; flex-direction:column; gap:14px">
            {{-- usage --}}
            <div class="status-tile">
                <div class="spread">
                    <span class="label">Disk usage</span>
                    <span class="small faint">{{ number_format($usage['disk_pct']) }}%</span>
                </div>
                <div class="value">{{ $fmtMb($usage['disk_used_mb']) }}</div>
                <div class="meter {{ $usage['disk_pct'] > 90 ? 'danger' : ($usage['disk_pct'] > 75 ? 'warn' : '') }}">
                    <span style="width: {{ min(100, $usage['disk_pct']) }}%"></span>
                </div>
                <div class="sub">of {{ $fmtMb($usage['disk_limit_mb']) }} allocated</div>
            </div>

            <div class="boxed-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:10px">
                <div class="status-tile">
                    <span class="label">Bandwidth</span>
                    <div class="value" style="font-size:17px">{{ $fmtMb($total = (int) collect($bandwidth)->sum('mb')) }}</div>
                    <span class="sub">of {{ $fmtMb($account->bandwidth_mb) }}</span>
                </div>
                <div class="status-tile">
                    <span class="label">Inodes</span>
                    <div class="value" style="font-size:17px">{{ number_format($usage['inodes_limit']) }}</div>
                    <span class="sub">limit</span>
                </div>
            </div>

            {{-- counts --}}
            <div class="status-tile">
                <span class="label">Account contents</span>
                <dl class="kv" style="margin:8px 0 0">
                    <dt>Domains</dt><dd>{{ $counts['domains'] }}</dd>
                    <dt>Databases</dt><dd>{{ $counts['databases'] }}</dd>
                    @if (config('atlas.mail.enabled'))
                        <dt>Mailboxes</dt><dd>{{ $counts['mailboxes'] }}</dd>
                    @endif
                    <dt>FTP accounts</dt><dd>{{ $counts['ftp'] }}</dd>
                    <dt>Cron jobs</dt><dd>{{ $counts['cron'] }}</dd>
                    <dt>Backups</dt><dd>{{ $counts['backups'] }}</dd>
                    <dt>Applications</dt><dd>{{ $counts['apps'] }}</dd>
                </dl>
            </div>

            {{-- bandwidth chart --}}
            <div class="status-tile">
                <div class="spread">
                    <span class="label">Bandwidth, last 30 days</span>
                    <a class="small" href="{{ route('panel.usage.bandwidth') }}">detail</a>
                </div>

                @if (count($bandwidth) === 0)
                    <p class="small faint" style="margin:10px 0 4px">
                        No traffic recorded yet. This chart fills in as the site serves requests.
                    </p>
                @else
                    <div class="chart" role="img" aria-label="Daily bandwidth for the last {{ count($bandwidth) }} days">
                        @foreach ($bandwidth as $point)
                            <div class="bar"
                                 style="height: {{ max(2, round($point['mb'] / $peak * 100)) }}%"
                                 data-tip="{{ \Illuminate\Support\Carbon::parse($point['day'])->format('j M') }}: {{ $fmtMb($point['mb']) }}"></div>
                        @endforeach
                    </div>
                    <div class="chart-axis">
                        <span>{{ \Illuminate\Support\Carbon::parse($bandwidth[0]['day'])->format('j M') }}</span>
                        <span>{{ \Illuminate\Support\Carbon::parse($bandwidth[count($bandwidth) - 1]['day'])->format('j M') }}</span>
                    </div>
                @endif
            </div>

            {{-- services --}}
            <div class="status-tile" style="padding:0">
                <div style="padding:14px 16px 8px"><span class="label">Services</span></div>

                @if (empty($serverInfo))
                    <p class="small faint" style="padding:0 16px 14px">
                        The agent could not report service state.
                    </p>
                @else
                    <div class="svc-list">
                        @foreach ($serverInfo as $svc)
                            <div class="svc-row">
                                <span class="svc-dot {{ $svc['active'] ? 'up' : 'down' }}"></span>
                                <span class="unit">{{ $svc['unit'] }}</span>
                                <span class="state">{{ $svc['state'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- recent logins --}}
            @if ($recentLogins->isNotEmpty())
                <div class="status-tile">
                    <div class="spread">
                        <span class="label">Recent logins</span>
                        <a class="small" href="{{ route('panel.usage.logins') }}">all</a>
                    </div>
                    <div class="stack" style="margin-top:8px; gap:6px">
                        @foreach ($recentLogins as $event)
                            <div class="small">
                                <span class="mono">{{ $event->created_at?->format('j M H:i') }}</span>
                                <span class="faint"> · {{ $event->ip }}</span>
                                @unless ($event->successful)
                                    <span class="badge danger">failed</span>
                                @endunless
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </aside>
    </div>
@endsection
