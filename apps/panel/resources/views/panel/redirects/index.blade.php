@extends('layouts.app')

@section('title', 'Redirects')

@section('content')
    <h1>Redirects</h1>
    <p class="small muted">A path on one of your domains that answers with a 301 or 302 and sends the visitor somewhere else.</p>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Active redirects</h2>

        @if ($redirects->isEmpty())
            <div class="empty"><h3>No redirects</h3><p>Create one below — nothing is written to your site's files.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>From</th><th>To</th><th>Code</th><th>Hits</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($redirects as $redirect)
                            <tr>
                                <td class="mono small">{{ $redirect->domain?->domain }}{{ $redirect->source_path }}</td>
                                <td class="small">{{ $redirect->target_url }}</td>
                                <td><span class="badge">{{ $redirect->status_code }}</span></td>
                                <td class="small faint">{{ number_format($redirect->hits) }}</td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.redirects.destroy', $redirect) }}" class="inline-form"
                                          data-confirm="Remove this redirect?">
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
        <h2 style="margin-top:0">New redirect</h2>

        <form method="POST" action="{{ route('panel.redirects.store') }}">
            @csrf
            <div class="inline-pair">
                <div class="form-row">
                    <label for="r-domain">Domain</label>
                    <select id="r-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="r-source">Source path</label>
                    <input id="r-source" name="source_path" type="text" value="{{ old('source_path', '/old-page') }}" required>
                    @error('source_path') <span class="error-text">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="inline-pair">
                <div class="form-row">
                    <label for="r-target">Destination URL</label>
                    <input id="r-target" name="target_url" type="url" value="{{ old('target_url') }}"
                           placeholder="https://new.example.com/page" required>
                    @error('target_url') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="r-code">Status</label>
                    <select id="r-code" name="status_code">
                        <option value="301">301 — permanent</option>
                        <option value="302">302 — temporary</option>
                        <option value="307">307 — temporary, method preserved</option>
                        <option value="308">308 — permanent, method preserved</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Create redirect</button>
            </div>
        </form>
    </div>
@endsection
