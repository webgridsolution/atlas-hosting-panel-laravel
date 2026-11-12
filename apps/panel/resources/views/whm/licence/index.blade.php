@extends('layouts.app')

@section('title', 'Licence')

@section('content')
    <h1>Licence</h1>
    <p class="small muted">The licence is verified locally with a public key; the panel never has to reach the internet to stay running.</p>

    <div class="grid two">
        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Current state</h2>
                    <p>{{ $state['reason'] }}</p>
                </div>
                <span class="badge {{ $state['state'] === 'active' ? 'ok' : ($state['state'] === 'grace' ? 'warn' : 'danger') }}">
                    {{ str_replace('_', ' ', $state['state']) }}
                </span>
            </div>

            <dl class="kv">
                <dt>Key ID</dt><dd class="mono">{{ $licence->kid ?: '—' }}</dd>
                <dt>Expires</dt><dd>{{ $licence->expires_at?->format('j M Y') ?? '—' }}</dd>
                <dt>Last checked</dt><dd>{{ $licence->last_checked_at?->diffForHumans() ?? 'never' }}</dd>
                <dt>Grace period</dt><dd>{{ $graceDays }} day(s)</dd>
                <dt>Last error</dt><dd class="small">{{ $licence->last_error ?: 'none' }}</dd>
            </dl>

            <div class="row" style="margin-top:16px">
                <form method="POST" action="{{ route('whm.licence.refresh') }}" class="inline-form">
                    @csrf
                    <button class="btn" type="submit"><x-icon name="refresh" :size="15" /> Check with vendor</button>
                </form>
                <a class="btn ghost" href="{{ route('whm.settings.index') }}">Settings</a>
            </div>

            <p class="small faint" style="margin-top:14px">
                If the vendor is unreachable the panel keeps the last known-good token and continues.
                Expiry leads to read-only mode: sites keep serving, data is never deleted.
            </p>
        </div>

        <div class="card">
            <h2>Activate a key</h2>
            <p class="small muted">
                The key from your Atlas invoice, e.g. <span class="mono">ATLS-7F3K-9QWD-2MXB</span>.
                The panel sends it to the vendor together with this machine's fingerprint and stores the
                signed token that comes back. Activation needs the vendor once; running does not.
            </p>

            <form method="POST" action="{{ route('whm.licence.activate') }}">
                @csrf
                <div class="form-row">
                    <div>
                        <label for="key">Licence key</label>
                        <input id="key" name="key" required maxlength="64" autocomplete="off"
                               value="{{ old('key') }}" placeholder="ATLS-XXXX-XXXX-XXXX">
                    </div>
                </div>

                <button class="btn primary" type="submit"><x-icon name="key" :size="15" /> Activate key</button>
            </form>

            <hr style="border:0;border-top:1px solid var(--line);margin:22px 0">

            <h2>Or paste a token</h2>
            <p class="small muted">If the vendor gave you a signed token directly, paste it here. It is verified before it is stored.</p>

            <form method="POST" action="{{ route('whm.licence.store') }}">
                @csrf
                <div class="form-row">
                    <div>
                        <label for="token">Licence token</label>
                        <textarea id="token" name="token" required style="min-height:160px">{{ old('token') }}</textarea>
                        <div class="hint">Begins with <span class="mono">atlas-lic-v1.</span> · a pasted token cannot refresh itself; activate a key to get automatic renewal.</div>
                    </div>
                </div>

                <button class="btn primary" type="submit">Verify and install</button>
            </form>
        </div>
    </div>
@endsection
