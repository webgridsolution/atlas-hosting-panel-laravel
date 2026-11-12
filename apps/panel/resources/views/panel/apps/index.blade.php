@extends('layouts.app')

@section('title', 'Applications')

@section('content')
    <h1>Applications</h1>
    <p class="small muted">
        One-click installs from a fixed catalogue. Each install gets its own database and database user, so two sites never share credentials.
    </p>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Installed</h2>

        @if ($installed->isEmpty())
            <div class="empty"><h3>Nothing installed yet</h3><p>Pick an application below.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Application</th><th>Site</th><th>URL</th><th>Version</th><th>Database</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($installed as $app)
                            <tr>
                                <td><strong class="small">{{ $app->label() }}</strong></td>
                                <td class="small faint">{{ $app->domain?->domain }}</td>
                                <td class="small"><a href="{{ $app->url }}" target="_blank" rel="noopener">{{ $app->url }}</a></td>
                                <td class="small">
                                    {{ $app->version }}
                                    @if ($app->checked_at)
                                        <span class="faint">· checked {{ $app->checked_at->diffForHumans() }}</span>
                                    @endif
                                </td>
                                <td class="mono small">{{ $app->db_name ?? '—' }}</td>
                                <td style="text-align:right; white-space:nowrap">
                                    <form method="POST" action="{{ route('panel.apps.check', $app) }}" class="inline-form">
                                        @csrf
                                        <button class="btn ghost sm" type="submit" title="Check for a newer version"><x-icon name="refresh" :size="14" /></button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.apps.destroy', $app) }}" class="inline-form"
                                          data-confirm="Remove {{ $app->label() }}? Files are deleted; its database is kept.">
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

    <div class="tile-group">
        <header>
            <x-icon name="apps" :size="15" />
            <h2>Catalogue</h2>
            <span class="count">{{ count($catalogue) }} applications</span>
        </header>

        <div class="tile-grid">
            @foreach ($catalogue as $slug => $meta)
                <div class="tile" style="cursor:default">
                    <span class="glyph"><x-icon name="package" :size="21" /></span>
                    <span class="name">{{ $meta['label'] }}</span>
                    <span class="small faint" style="font-size:11px">v{{ $meta['version'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Install</h2>

        @if ($domains->isEmpty())
            <p class="small muted">Add a domain first — an application needs a document root.</p>
            <a class="btn" href="{{ route('panel.domains.index') }}">Add a domain</a>
        @else
            <form method="POST" action="{{ route('panel.apps.store') }}">
                @csrf
                <div class="inline-pair">
                    <div class="form-row">
                        <label for="app-slug">Application</label>
                        <select id="app-slug" name="slug">
                            @foreach ($catalogue as $slug => $meta)
                                <option value="{{ $slug }}">{{ $meta['label'] }} {{ $meta['version'] }} — {{ $meta['description'] }}</option>
                            @endforeach
                        </select>
                        @error('slug') <span class="error-text">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-row">
                        <label for="app-domain">Site</label>
                        <select id="app-domain" name="domain_id">
                            @foreach ($domains as $domain)
                                <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="inline-pair">
                    <div class="form-row">
                        <label for="app-path">Install into (optional)</label>
                        <input id="app-path" name="path" type="text" value="{{ old('path') }}" placeholder="blog">
                        <span class="hint">A subfolder of the document root. Leave empty to install at the root.</span>
                        @error('path') <span class="error-text">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-row">
                        <label for="app-email">Admin email</label>
                        <input id="app-email" name="admin_email" type="email" value="{{ old('admin_email', auth()->user()->email) }}" required>
                    </div>
                </div>
                <div class="form-row">
                    <label for="app-user">Admin username</label>
                    <input id="app-user" name="admin_user" type="text" value="{{ old('admin_user') }}" placeholder="admin" required>
                    <span class="hint">The admin password is generated and shown once after the install.</span>
                    @error('admin_user') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="cloud-up" :size="15" /> Install</button>
                </div>
            </form>
        @endif
    </div>
@endsection
