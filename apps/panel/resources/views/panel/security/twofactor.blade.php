@extends('layouts.app')

@section('title', 'Two-factor authentication')

@php
    use App\Domain\Security\Totp;
@endphp

@section('content')
<div class="spread mb-4">
    <div>
        <h1 class="mb-0">Two-factor authentication</h1>
        <p class="small muted mb-0">
            A six-digit code from your phone, on top of your password. Recommended for every login
            that can reach a server.
        </p>
    </div>

    <span class="badge {{ $enabled ? 'ok' : 'plain' }}">
        {{ $enabled ? 'Enabled' : 'Not enabled' }}
    </span>
</div>

@if ($recoveryCodes)
    <div class="alert warn mb-4">
        <x-icon name="key-round" :size="16" />
        <div>
            <strong>Save these recovery codes now.</strong> Each works once, and they are the only way in
            if you lose your phone. They will not be shown again.
            <div class="recovery-grid mt-3">
                @foreach ($recoveryCodes as $code)
                    <code>{{ strtoupper(substr($code, 0, 5)) }}-{{ strtoupper(substr($code, 5)) }}</code>
                @endforeach
            </div>
        </div>
    </div>
@endif

<div class="grid sidebar-right">
    <div>
        @if ($enabled)
            {{-- ---- on: offer to turn it off ---- --}}
            <div class="card">
                <div class="card-head mb-3">
                    <div>
                        <h2>Your second factor is active</h2>
                        <p>
                            Enabled
                            {{ $user->two_factor_confirmed_at?->gt(now()->subMinute()) ? 'just now' : $user->two_factor_confirmed_at?->diffForHumans() }}.
                        </p>
                    </div>
                    <span class="badge ok"><span class="dot"></span> live</span>
                </div>

                <p class="small muted">
                    Every login now asks for a code from your authenticator app after the password.
                    Turning this off needs your password and a current code, so a stolen session
                    cannot switch it off.
                </p>

                <form method="POST" action="{{ route('panel.security.twofactor.destroy') }}" class="mt-4">
                    @csrf
                    @method('DELETE')

                    <div class="form-row two">
                        <div>
                            <label for="off-password">Your password</label>
                            <input id="off-password" type="password" name="password" required autocomplete="current-password">
                        </div>
                        <div>
                            <label for="off-code">Current code</label>
                            <input id="off-code" type="text" name="code" inputmode="numeric" maxlength="10"
                                   placeholder="123456" required autocomplete="one-time-code">
                        </div>
                    </div>

                    <button class="btn solid-danger" type="submit"
                            data-confirm="Turn off two-factor authentication?">
                        <x-icon name="x" :size="14" /> Turn off two-factor
                    </button>
                </form>
            </div>

        @elseif ($pending)
            {{-- ---- half-way: the secret exists but is not armed ---- --}}
            <div class="card">
                <div class="card-head mb-3">
                    <div>
                        <h2>Scan this key</h2>
                        <p>Open your authenticator app, add an account, and scan or type the key.</p>
                    </div>
                    <span class="badge warn">not active yet</span>
                </div>

                <div class="totp-setup">
                    <div class="totp-key">
                        <span class="muted small">Key</span>
                        <code>{{ Totp::readableSecret((string) $pending) }}</code>
                    </div>

                    <div class="tiny faint mono truncate" title="{{ Totp::provisioningUri((string) $pending, $user->email) }}">
                        {{ Totp::provisioningUri((string) $pending, $user->email) }}
                    </div>
                </div>

                <div class="alert mb-4 mt-4">
                    <x-icon name="alert" :size="16" />
                    <div>
                        The panel does not draw a QR image itself — no external service is called with your
                        secret. Paste the <span class="mono">otpauth://</span> line above into any QR generator,
                        or type the key into your app by hand. Both produce the same account.
                    </div>
                </div>

                <form method="POST" action="{{ route('panel.security.twofactor.confirm') }}">
                    @csrf
                    <div class="form-row" style="max-width:260px">
                        <div>
                            <label for="confirm-code">Code from the app</label>
                            <input id="confirm-code" type="text" name="code" inputmode="numeric" maxlength="10"
                                   placeholder="123456" required autofocus autocomplete="one-time-code">
                            <div class="field-hint">Six digits. The key is not armed until this matches.</div>
                        </div>
                    </div>

                    <button class="btn primary" type="submit">
                        <x-icon name="shield-check" :size="15" /> Turn on two-factor
                    </button>
                </form>
            </div>

        @else
            {{-- ---- off ---- --}}
            <div class="card">
                <div class="card-head mb-3">
                    <div>
                        <h2>Add a second factor</h2>
                        <p>Works with Google Authenticator, Authy, 1Password, Bitwarden and FreeOTP.</p>
                    </div>
                </div>

                <p class="small muted">
                    The panel generates a secret on this server and shows it to you once. It is not armed
                    until you enter a code that proves your app holds the same secret — a half-finished
                    enrolment would otherwise lock you out.
                </p>

                <form method="POST" action="{{ route('panel.security.twofactor.start') }}" class="mt-4">
                    @csrf
                    <button class="btn primary" type="submit">
                        <x-icon name="shield" :size="15" /> Start setup
                    </button>
                </form>
            </div>
        @endif
    </div>

    <aside>
        <div class="card">
            <h2 style="font-size:.95rem;margin:0 0 10px">How it works</h2>
            <dl class="kv">
                <dt>Standard</dt>
                <dd class="mono small">RFC 6238 TOTP</dd>

                <dt>Code lifetime</dt>
                <dd>30 seconds</dd>

                <dt>Clock drift allowed</dt>
                <dd>±30 seconds</dd>

                <dt>Recovery codes</dt>
                <dd>8, single use</dd>

                <dt>Secret storage</dt>
                <dd>Encrypted at rest</dd>
            </dl>
        </div>

        <div class="card mt-3">
            <h2 style="font-size:.95rem;margin:0 0 6px">If you lose your phone</h2>
            <p class="small muted mb-0">
                Use one of your recovery codes at the login prompt. Each is single use. If those are gone
                too, an administrator has to reset the factor from the WHM console.
            </p>
        </div>
    </aside>
</div>
@endsection
