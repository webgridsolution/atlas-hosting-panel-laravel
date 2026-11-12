@extends('layouts.app')

@section('title', $account->username)

@section('content')
    <div class="spread" style="margin-bottom:18px">
        <div>
            <h1>{{ $account->username }}</h1>
            <p class="small muted">
                {{ $account->domain }} ·
                <span class="badge {{ $account->status === 'active' ? 'ok' : ($account->status === 'suspended' ? 'warn' : 'accent') }}">{{ $account->status }}</span>
                @if ($account->suspended_at) · suspended {{ $account->suspended_at->diffForHumans() }} @endif
            </p>
        </div>

        <div class="row">
            @if ($account->status === 'active')
                <form method="POST" action="{{ route('whm.accounts.suspend', $account) }}" class="inline-form">
                    @csrf
                    <input type="hidden" name="reason" value="Suspended from WHM">
                    <button class="btn danger" type="submit"><x-icon name="lock" :size="15" /> Suspend</button>
                </form>
            @else
                <form method="POST" action="{{ route('whm.accounts.unsuspend', $account) }}" class="inline-form">
                    @csrf
                    <button class="btn" type="submit"><x-icon name="check" :size="15" /> Unsuspend</button>
                </form>
            @endif
            <a class="btn ghost" href="{{ route('whm.accounts.index') }}">Back</a>
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <div class="stat">
            <div class="label">Disk used</div>
            <div class="value">{{ number_format($usage['disk_used_mb']) }}<span class="small faint"> MB</span></div>
            <div class="meter {{ $usage['disk_pct'] > 90 ? 'danger' : ($usage['disk_pct'] > 75 ? 'warn' : '') }}" style="margin-top:8px">
                <span style="width: {{ $usage['disk_pct'] }}%"></span>
            </div>
            <div class="sub">of {{ number_format($usage['disk_limit_mb']) }} MB</div>
        </div>
        <div class="stat">
            <div class="label">Inodes</div>
            <div class="value">{{ number_format($usage['inodes_used']) }}</div>
            <div class="sub">limit {{ number_format($usage['inodes_limit']) }}</div>
        </div>
        <div class="stat">
            <div class="label">Bandwidth</div>
            <div class="value">{{ number_format($account->bandwidth_mb) }}<span class="small faint"> MB</span></div>
            <div class="sub">monthly allowance</div>
        </div>
        <div class="stat">
            <div class="label">PHP</div>
            <div class="value">{{ $account->php_version }}</div>
            <div class="sub">dedicated FPM pool</div>
        </div>
    </div>

    <div class="grid two">
        <div class="card">
            <h2>Resources</h2>
            <dl class="kv">
                <dt>Home directory</dt><dd class="mono small">{{ $account->home() }}</dd>
                <dt>Document root</dt><dd class="mono small">{{ $account->docRoot() }}</dd>
                <dt>Panel login</dt><dd>{{ $account->user?->email ?? 'none' }}</dd>
                <dt>Reseller</dt><dd>{{ $account->reseller?->name ?? 'direct' }}</dd>
                <dt>Created</dt><dd>{{ $account->created_at?->format('j M Y, H:i') }}</dd>
            </dl>

            <h3 style="margin-top:18px">Websites</h3>
            <ul class="small" style="padding-left:18px;color:var(--muted)">
                @forelse ($account->domains as $domain)
                    <li>{{ $domain->domain }} —
                        <span class="badge {{ $domain->ssl_status === 'active' ? 'ok' : 'warn' }}">{{ $domain->sslLabel() }}</span>
                    </li>
                @empty
                    <li>None</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <h2>Quota</h2>
            <form method="POST" action="{{ route('whm.accounts.quota', $account) }}">
                @csrf
                @method('PATCH')

                <div class="form-row cols">
                    <div>
                        <label for="disk_mb">Disk limit (MB)</label>
                        <input id="disk_mb" name="disk_mb" type="number" min="256" max="10485760" value="{{ $account->disk_mb }}" required>
                    </div>
                    <div>
                        <label for="inodes">Inode limit</label>
                        <input id="inodes" name="inodes" type="number" min="1000" max="50000000" value="{{ $account->inodes }}" required>
                    </div>
                </div>

                <button class="btn" type="submit">Apply quota</button>
            </form>

            <h2 style="margin-top:24px">Danger zone</h2>
            <p class="small muted">
                Removing an account leaves its files, databases and mail on the server. Deleting the data is a separate,
                deliberate action that requires the account to be suspended first.
            </p>

            <details>
                <summary class="small" style="cursor:pointer;color:var(--danger)">Remove this account</summary>
                <form method="POST" action="{{ route('whm.accounts.destroy', $account) }}" style="margin-top:12px">
                    @csrf
                    @method('DELETE')

                    <label class="checkbox" style="margin-bottom:10px">
                        <input type="checkbox" name="delete_data" value="1" @disabled(! $canPurge)>
                        Also delete all data on disk (requires a suspended account)
                    </label>

                    <div class="form-row">
                        <div>
                            <label for="confirm">Type <span class="mono">{{ $account->username }}</span> to confirm data deletion</label>
                            <input id="confirm" name="confirm" type="text" autocomplete="off">
                        </div>
                    </div>

                    <button class="btn danger" type="submit">Remove account</button>
                </form>
            </details>
        </div>
    </div>
@endsection
