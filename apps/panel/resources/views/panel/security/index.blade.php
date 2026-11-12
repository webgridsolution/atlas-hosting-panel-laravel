@extends('layouts.app')

@section('title', 'Security')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.security.index') }}" class="active">IP blocker &amp; directory privacy</a>
        <a href="{{ route('panel.security.tokens') }}">API tokens</a>
        <a href="{{ route('profile.edit') }}">Password</a>
    </div>

    <h1>Security</h1>
    <p class="small muted">
        Both controls below live inside your own home directory, so they cannot affect another tenant on the server.
    </p>

    <div class="grid two" style="margin-bottom:16px">
        <div class="card">
            <h2 style="margin-top:0">IP blocker</h2>
            <p class="small muted">Denies an address or range from your sites. Other customers are unaffected.</p>

            @if ($blocks->isNotEmpty())
                <div class="table-wrap" style="margin-bottom:12px">
                    <table>
                        <thead><tr><th>Address</th><th>Reason</th><th>Expires</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($blocks as $block)
                                <tr>
                                    <td class="mono small">{{ $block->cidr }}</td>
                                    <td class="small faint">{{ $block->reason ?? '—' }}</td>
                                    <td class="small">
                                        @if ($block->expires_at)
                                            <span class="badge {{ $block->isActive() ? 'warn' : 'danger' }}">
                                                {{ $block->expires_at->format('j M Y') }}
                                            </span>
                                        @else
                                            <span class="badge">permanent</span>
                                        @endif
                                    </td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('panel.security.block.destroy', $block) }}" class="inline-form"
                                              data-confirm="Unblock {{ $block->cidr }}?">
                                            @csrf @method('DELETE')
                                            <button class="btn ghost sm" type="submit"><x-icon name="x" :size="14" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="small faint">Nothing is blocked.</p>
            @endif

            <form method="POST" action="{{ route('panel.security.block') }}">
                @csrf
                <div class="inline-pair">
                    <div class="form-row">
                        <label for="ip-cidr">Address or range</label>
                        <input id="ip-cidr" name="cidr" type="text" value="{{ old('cidr') }}" placeholder="203.0.113.7" required>
                        @error('cidr') <span class="error-text">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-row">
                        <label for="ip-expires">Expires (optional)</label>
                        <input id="ip-expires" name="expires_at" type="datetime-local" value="{{ old('expires_at') }}">
                    </div>
                </div>
                <div class="form-row">
                    <label for="ip-reason">Reason</label>
                    <input id="ip-reason" name="reason" type="text" value="{{ old('reason') }}" placeholder="repeated scraping">
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="ban" :size="15" /> Block address</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top:0">Directory privacy</h2>
            <p class="small muted">Puts a username and password in front of one directory using .htaccess and .htpasswd.</p>

            @if ($protections->isNotEmpty())
                <div class="table-wrap" style="margin-bottom:12px">
                    <table>
                        <thead><tr><th>Path</th><th>User</th><th>Realm</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($protections as $protection)
                                <tr>
                                    <td class="mono small">{{ $protection->path }}</td>
                                    <td class="small">{{ $protection->username }}</td>
                                    <td class="small faint">{{ $protection->realm }}</td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('panel.security.protect.destroy', $protection) }}" class="inline-form"
                                              data-confirm="Remove protection from {{ $protection->path }}?">
                                            @csrf @method('DELETE')
                                            <button class="btn ghost sm" type="submit"><x-icon name="x" :size="14" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="small faint">No protected directories.</p>
            @endif

            <form method="POST" action="{{ route('panel.security.protect') }}">
                @csrf
                <div class="form-row">
                    <label for="dp-path">Directory</label>
                    <input id="dp-path" name="path" type="text" value="{{ old('path') }}" placeholder="/public_html/admin" required>
                    <span class="hint">Relative to your home directory.</span>
                    @error('path') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="dp-realm">Realm shown in the prompt</label>
                    <input id="dp-realm" name="realm" type="text" value="{{ old('realm', 'Restricted area') }}" required>
                </div>
                <div class="inline-pair">
                    <div class="form-row">
                        <label for="dp-user">Username</label>
                        <input id="dp-user" name="username" type="text" value="{{ old('username') }}" required>
                    </div>
                    <div class="form-row">
                        <label for="dp-pass">Password</label>
                        <input id="dp-pass" name="password" type="password" required minlength="12">
                        @error('password') <span class="error-text">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="form-row">
                    <label for="dp-pass2">Confirm password</label>
                    <input id="dp-pass2" name="password_confirmation" type="password" required minlength="12">
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="lock" :size="15" /> Protect directory</button>
                </div>
            </form>
        </div>
    </div>
@endsection
