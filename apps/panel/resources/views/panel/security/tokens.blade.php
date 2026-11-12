@extends('layouts.app')

@section('title', 'API tokens')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.security.index') }}">IP blocker &amp; directory privacy</a>
        <a href="{{ route('panel.security.tokens') }}" class="active">API tokens</a>
        <a href="{{ route('profile.edit') }}">Password</a>
    </div>

    <h1>API tokens</h1>
    <p class="small muted">
        A token authenticates a script against the panel's API. The plaintext is shown once; only its hash is stored, so a database dump cannot be replayed.
    </p>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Your tokens</h2>

        @if ($tokens->isEmpty())
            <div class="empty"><h3>No tokens</h3><p>Create one below.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Prefix</th><th>Abilities</th><th>Expires</th><th>Last used</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td><strong class="small">{{ $token->name }}</strong></td>
                                <td class="mono small">{{ $token->prefix }}…</td>
                                <td class="small">
                                    @foreach ($token->abilityList() as $ability)
                                        <span class="badge">{{ $ability }}</span>
                                    @endforeach
                                </td>
                                <td class="small">
                                    <span class="badge {{ $token->isExpired() ? 'danger' : '' }}">
                                        {{ $token->expires_at?->format('j M Y') ?? 'never' }}
                                    </span>
                                </td>
                                <td class="small faint">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.security.tokens.destroy', $token) }}" class="inline-form"
                                          data-confirm="Revoke {{ $token->name }}? Scripts using it will stop working.">
                                        @csrf @method('DELETE')
                                        <button class="btn ghost sm" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2 style="margin-top:0">New token</h2>

        <form method="POST" action="{{ route('panel.security.tokens.store') }}">
            @csrf
            <div class="inline-pair">
                <div class="form-row">
                    <label for="tk-name">Name</label>
                    <input id="tk-name" name="name" type="text" value="{{ old('name') }}" placeholder="deploy script" required>
                </div>
                <div class="form-row">
                    <label for="tk-expiry">Expires in</label>
                    <select id="tk-expiry" name="expires_in_days">
                        <option value="30">30 days</option>
                        <option value="90" selected>90 days</option>
                        <option value="180">180 days</option>
                        <option value="365">1 year</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label>Abilities</label>
                <div class="row" style="gap:14px; flex-wrap:wrap">
                    @foreach (['read' => 'Read', 'write' => 'Write', 'dns' => 'DNS', 'email' => 'Email', 'files' => 'Files'] as $value => $label)
                        <label class="checkbox">
                            <input type="checkbox" name="abilities[]" value="{{ $value }}" @checked($value === 'read')> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="form-actions">
                <button class="btn primary" type="submit"><x-icon name="key" :size="15" /> Create token</button>
            </div>
        </form>
    </div>
@endsection
