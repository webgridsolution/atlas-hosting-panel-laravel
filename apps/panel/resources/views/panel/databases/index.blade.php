@extends('layouts.app')

@section('title', 'Databases')

@section('content')
    <h1>Databases</h1>
    <p class="small muted">
        Each database gets its own user with privileges on that database only.
        @if ($limit !== null) Your package allows {{ $limit }} database(s). @endif
    </p>

    <div class="card">
        <div class="card-head"><h2>Create a database</h2></div>

        <form method="POST" action="{{ route('panel.databases.store') }}" class="row" style="align-items:flex-end">
            @csrf
            <div style="flex:1;min-width:260px">
                <label for="name">Database name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="shop_production" required>
                <div class="hint">Lowercase letters, digits and underscores. A matching user is created automatically.</div>
            </div>
            <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Create</button>
        </form>
    </div>

    <div class="card">
        <h2>Your databases</h2>

        @if ($databases->isEmpty())
            <div class="empty"><h3>No databases</h3><p>Create one above — the password is shown once.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>User</th><th>Size</th><th>Created</th><th class="right">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($databases as $database)
                            <tr>
                                <td class="mono">{{ $database->name }}</td>
                                <td class="mono small">{{ $database->db_user }}</td>
                                <td class="small">{{ $database->size_mb !== null ? number_format($database->size_mb).' MB' : '—' }}</td>
                                <td class="small faint">{{ $database->created_at?->format('j M Y') }}</td>
                                <td class="right">
                                    <form method="POST" action="{{ route('panel.databases.password', $database) }}" class="inline-form"
                                          onsubmit="return confirm('Reset the password for {{ $database->db_user }}?')">
                                        @csrf
                                        <button class="btn sm" type="submit"><x-icon name="key" :size="14" /> Reset password</button>
                                    </form>

                                    <form method="POST" action="{{ route('panel.databases.destroy', $database) }}" class="inline-form"
                                          onsubmit="return confirm('Drop {{ $database->name }}? This destroys its data.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn sm danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
