@extends('layouts.app')

@section('title', 'Websites')

@section('content')
    <h1>Websites</h1>
    <p class="small muted">
        Document roots live inside your home directory. HTTPS is issued per domain with Let's Encrypt once DNS points here.
        @if ($limit) Your package allows {{ $limit }} website(s). @endif
    </p>

    <div class="card">
        <div class="card-head"><h2>Add a domain</h2></div>

        <form method="POST" action="{{ route('panel.websites.store') }}" class="row" style="align-items:flex-end">
            @csrf
            <div style="flex:1;min-width:240px">
                <label for="domain">Domain</label>
                <input id="domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="shop.example.com" required>
            </div>
            <div style="flex:1;min-width:240px">
                <label for="document_root">Document root (optional)</label>
                <input id="document_root" name="document_root" type="text" value="{{ old('document_root') }}"
                       placeholder="{{ $account->docRoot() }}">
            </div>
            <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Add domain</button>
        </form>
    </div>

    <div class="card">
        <h2>Your websites</h2>

        @if ($domains->isEmpty())
            <div class="empty"><h3>No websites</h3><p>Add your first domain above.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Domain</th><th>Document root</th><th>HTTPS</th><th>Expires</th><th class="right">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($domains as $domain)
                            <tr>
                                <td>
                                    <strong>{{ $domain->domain }}</strong>
                                    @if ($domain->is_primary) <span class="badge accent">primary</span> @endif
                                </td>
                                <td class="mono small faint">{{ $domain->document_root }}</td>
                                <td>
                                    <span class="badge {{ $domain->ssl_status === 'active' ? 'ok' : ($domain->ssl_status === 'failed' ? 'danger' : 'warn') }}">
                                        {{ $domain->sslLabel() }}
                                    </span>
                                    @if ($domain->sslExpiringSoon())
                                        <div class="small" style="color:var(--warn)">expires {{ $domain->ssl_expires_at->diffForHumans() }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ $domain->ssl_expires_at?->format('j M Y') ?? '—' }}</td>
                                <td class="right">
                                    <form method="POST" action="{{ route('panel.websites.certificate', $domain) }}" class="inline-form">
                                        @csrf
                                        <button class="btn sm" type="submit">
                                            <x-icon name="lock" :size="14" />
                                            {{ $domain->ssl_status === 'active' ? 'Reissue' : 'Issue' }} certificate
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('panel.websites.destroy', $domain) }}" class="inline-form"
                                          onsubmit="return confirm('Remove {{ $domain->domain }}? Files stay on disk.')">
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
