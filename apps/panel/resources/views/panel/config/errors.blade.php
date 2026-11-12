@extends('layouts.app')

@section('title', 'Error pages')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.config.php') }}">PHP</a>
        <a href="{{ route('panel.config.mime') }}">MIME types</a>
        <a href="{{ route('panel.config.errors') }}" class="active">Error pages</a>
    </div>

    <h1>Error pages</h1>
    <p class="small muted">Custom documents served instead of the server's default when a request fails.</p>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Configured pages</h2>

        @if ($pages->isEmpty())
            <div class="empty"><h3>No custom error pages</h3><p>The server default is used for every status code.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Domain</th><th>Status</th><th>Preview</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($pages as $page)
                            <tr>
                                <td class="small">{{ $page->domain?->domain }}</td>
                                <td><span class="badge">{{ $page->status_code }}</span></td>
                                <td class="small faint">{{ Str::limit(strip_tags((string) $page->body), 70) }}</td>
                                <td style="text-align:right" class="small faint">set below</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2 style="margin-top:0">Set an error page</h2>

        <form method="POST" action="{{ route('panel.config.errors.store') }}">
            @csrf
            <div class="inline-pair">
                <div class="form-row">
                    <label for="ep-domain">Domain</label>
                    <select id="ep-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="ep-status">Status</label>
                    <select id="ep-status" name="status_code">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}">{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label for="ep-body">HTML</label>
                <textarea id="ep-body" name="body" rows="8" class="mono small" required>{{ old('body', "<!doctype html>\n<title>Not found</title>\n<h1>404 — page not found</h1>\n<p>That address does not exist on this site.</p>") }}</textarea>
                @error('body') <span class="error-text">{{ $message }}</span> @enderror
            </div>
            <div class="form-actions">
                <button class="btn primary" type="submit"><x-icon name="file" :size="15" /> Save page</button>
            </div>
        </form>
    </div>
@endsection
