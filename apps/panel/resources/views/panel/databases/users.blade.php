@extends('layouts.app')

@section('title', 'Database users')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.databases.index') }}">Databases</a>
        <a href="{{ route('panel.databases.users') }}" class="active">Users &amp; remote access</a>
    </div>

    <h1>Database users</h1>
    <p class="small muted">
        Give each application its own user with an explicit grant. A plugin compromise then reaches one database, not every database on the account.
    </p>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Users</h2>

        @if ($users->isEmpty())
            <div class="empty"><h3>No database users</h3><p>Create one below and grant it access to the databases it needs.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User</th><th>Privileges</th><th>Databases</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td class="mono small"><strong>{{ $user->username }}</strong><span class="faint">&#64;{{ $user->host }}</span></td>
                                <td><span class="badge">{{ $user->privileges }}</span></td>
                                <td class="small">
                                    @forelse ($user->databases as $db)
                                        <span class="badge accent">{{ $db->name }}</span>
                                    @empty
                                        <span class="faint">none</span>
                                    @endforelse
                                </td>
                                <td><span class="badge {{ $user->status === 'active' ? 'ok' : 'warn' }}">{{ $user->status }}</span></td>
                                <td style="text-align:right; white-space:nowrap">
                                    <form method="POST" action="{{ route('panel.databases.users.password', $user) }}" class="inline-form"
                                          data-confirm="Reset the password for {{ $user->username }}?">
                                        @csrf
                                        <button class="btn ghost sm" type="submit" title="Reset password"><x-icon name="key" :size="14" /></button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.databases.users.destroy', $user) }}" class="inline-form"
                                          data-confirm="Drop {{ $user->username }}? Its databases are kept.">
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

    <div class="grid two">
        <div class="card">
            <h2 style="margin-top:0">New database user</h2>

            @if ($databases->isEmpty())
                <p class="small muted">Create a database first — a user with no grant can do nothing.</p>
                <a class="btn" href="{{ route('panel.databases.index') }}">Go to databases</a>
            @else
                <form method="POST" action="{{ route('panel.databases.users.store') }}">
                    @csrf
                    <div class="inline-pair">
                        <div class="form-row">
                            <label for="du-name">Name</label>
                            <input id="du-name" name="name" type="text" value="{{ old('name') }}" placeholder="wordpress" required>
                            <span class="hint">Prefix <span class="mono">{{ $account->username }}_</span> is added.</span>
                            @error('name') <span class="error-text">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-row">
                            <label for="du-priv">Privileges</label>
                            <select id="du-priv" name="privileges">
                                <option value="ALL">All privileges</option>
                                <option value="WRITE">Read and write (SELECT, INSERT, UPDATE, DELETE)</option>
                                <option value="READ">Read only (SELECT)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <label>Databases</label>
                        <div class="stack" style="gap:6px; margin-top:4px">
                            @foreach ($databases as $db)
                                <label class="checkbox">
                                    <input type="checkbox" name="databases[]" value="{{ $db->id }}"
                                           @checked(in_array($db->id, old('databases', []), true))>
                                    <span class="mono small">{{ $db->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('databases') <span class="error-text">{{ $message }}</span> @enderror
                    </div>

                    <div class="form-actions">
                        <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Create user</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="card">
            <h2 style="margin-top:0">Remote MySQL access</h2>
            <p class="small muted">
                By default MySQL only listens on localhost. Adding a host lets a specific IP connect as one of your users — not as every user.
            </p>

            @if ($remote->isNotEmpty())
                <div class="table-wrap" style="margin-bottom:12px">
                    <table>
                        <thead><tr><th>Host</th><th>Label</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($remote as $host_)
                                <tr>
                                    <td class="mono small">{{ $host_->host }}</td>
                                    <td class="small faint">{{ $host_->label ?? '—' }}</td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('panel.databases.remote.destroy', $host_) }}" class="inline-form"
                                              data-confirm="Revoke access for {{ $host_->host }}?">
                                            @csrf @method('DELETE')
                                            <button class="btn ghost sm" type="submit"><x-icon name="x" :size="14" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form method="POST" action="{{ route('panel.databases.remote.store') }}">
                @csrf
                <div class="form-row">
                    <label for="rm-host">Allowed host</label>
                    <input id="rm-host" name="host" type="text" value="{{ old('host') }}" placeholder="203.0.113.7" required>
                    <span class="hint">An IP address or CIDR block. Hostnames are not accepted.</span>
                    @error('host') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="rm-label">Label (optional)</label>
                    <input id="rm-label" name="label" type="text" value="{{ old('label') }}" placeholder="office">
                </div>
                <div class="form-actions">
                    <button class="btn ghost" type="submit" @disabled($users->isEmpty())><x-icon name="plug" :size="15" /> Allow host</button>
                </div>
                @if ($users->isEmpty())
                    <span class="hint">Create a database user first.</span>
                @endif
            </form>
        </div>
    </div>
@endsection
